// A fake SMSTunnel API for the tests of SMSTunnel for WHMCS. It answers like the real server
// (SMSserver public-api controller and idempotency service) and records every request, so the tests
// can check exactly what the module sent. It never sends a text anywhere.
//
//   node tests/fake-api/server.mjs [--port 0] [--host 127.0.0.1] [--port-file path]
//
// Endpoints of the real API:
//   POST /api/v1/sms/send          { to, message, deviceId?, defaultCountryCode? }
//   POST /api/v1/sms/send-2fa      the same; the text must hold a 4-10 digit code, at most 320 characters
//   GET  /api/v1/devices
// Idempotency-Key on the two send routes, as the server's idempotency.service.ts: same key + same
// request within 24 h → the stored answer with Idempotent-Replayed: true; same key, other request →
// 422 IDEMPOTENCY_KEY_REUSED; 2xx and 5xx answers are stored, a 4xx frees the key. Answers of the
// "html502" and "hang"/"drop" modes come from "a proxy" and are not stored.
// Test controls (never on the real API):
//   GET  /__requests     every request since the last reset
//   POST /__reset        forget everything, back to normal
//   POST /__control      { mode, times, delayMs, devicesOnline }
//   POST /__shutdown
// Modes (for the next `times` API calls, or until changed): normal | auth (401) | forbidden (403) |
//   rate (429, Retry-After: 42) | error500 (stored with the key) | html502 (a proxy page) |
//   hang (never answers) | drop (closes the connection) | inprogress (409 IDEMPOTENCY_KEY_IN_PROGRESS)
import http from 'node:http';
import crypto from 'node:crypto';
import fs from 'node:fs';

const args = Object.fromEntries(
  process.argv.slice(2).reduce((pairs, value, i, all) => (value.startsWith('--') ? [...pairs, [value.slice(2), all[i + 1]]] : pairs), []),
);

// Built from pieces so that no file holds something a secret scanner takes for a real key.
export const TEST_KEY = process.env.SMSTUNNEL_FAKE_KEY || 'sk_' + 'test_' + 'FakeWhmcsKey000000000000000001';
const PHONES = [
  { id: '6650c0ffee0000000000b001', name: 'Office phone' },
  { id: '6650c0ffee0000000000b002', name: 'Spare phone' },
];

const state = { requests: [], texts: [], keys: new Map(), mode: 'normal', times: 0, delayMs: 0, devicesOnline: [true, false] };

function reset() {
  state.requests = [];
  state.texts = [];
  state.keys = new Map();
  state.mode = 'normal';
  state.times = 0;
  state.delayMs = 0;
  state.devicesOnline = [true, false];
}

function json(res, status, body, headers = {}) {
  const text = JSON.stringify(body);
  res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Content-Length': Buffer.byteLength(text), ...headers });
  res.end(text);
  return { status, body, headers };
}

const errors = {
  badKey: { message: 'Invalid or expired API key', error: 'Unauthorized', statusCode: 401 },
  forbidden: { statusCode: 403, error: 'Forbidden', code: 'PERMISSION_REQUIRED', permission: 'sms:send', message: "This API key lacks the 'sms:send' permission" },
  rate: { statusCode: 429, message: 'ThrottlerException: Too Many Requests' },
  server: { statusCode: 500, message: 'Internal server error' },
  reused: { statusCode: 422, error: 'Unprocessable Entity', code: 'IDEMPOTENCY_KEY_REUSED', message: 'This Idempotency-Key was already used in the last 24 hours for a different request.' },
  inProgress: { statusCode: 409, error: 'Conflict', code: 'IDEMPOTENCY_KEY_IN_PROGRESS', message: 'A request with this Idempotency-Key is still being processed. Retry in a moment to get its result.' },
  invalidKey: { statusCode: 400, error: 'Bad Request', code: 'IDEMPOTENCY_KEY_INVALID', message: 'Idempotency-Key must be 1 to 255 printable ASCII characters.' },
  noCode: { statusCode: 400, error: 'Bad Request', message: 'No valid verification code found in message' },
};

// The server's rule (utils/sign-in-code.util.ts).
const SIGN_IN_CODE = [/(?<![\w+]|\d[.,:/])\d{4,10}(?!\w|[.,:/]\d)/, /(?<![\w+-]|\d[.,:/])\d{3}-\d{3}(?!\w|-\d|[.,:/]\d)/];

function modeNow() {
  const mode = state.mode;
  if (mode !== 'normal' && state.times > 0) {
    state.times -= 1;
    if (state.times === 0) state.mode = 'normal';
  }
  return mode;
}

function canonical(value) {
  if (value === null || typeof value !== 'object') return JSON.stringify(value);
  if (Array.isArray(value)) return `[${value.map(canonical).join(',')}]`;
  return `{${Object.keys(value).filter((k) => value[k] !== undefined).sort().map((k) => `${JSON.stringify(k)}:${canonical(value[k])}`).join(',')}}`;
}

function validateSend(body, twoFactor) {
  const allowed = ['to', 'message', 'deviceId', 'defaultCountryCode'];
  const problems = [];
  for (const key of Object.keys(body)) if (!allowed.includes(key)) problems.push(`property ${key} should not exist`);
  if (typeof body.to !== 'string') problems.push('to must be a string in international format, e.g. "+14155550123"');
  else if (!/^[\d\s.\-()+ ]{7,25}$/.test(body.to)) problems.push('Phone number must contain 7-25 characters (digits, spaces, dots, dashes, + allowed)');
  if (typeof body.message !== 'string') problems.push('message must be a string (the text to send)');
  else if (body.message.length < 1) problems.push('message must be longer than or equal to 1 characters');
  else if (body.message.length > (twoFactor ? 320 : 1600)) problems.push(`message must be shorter than or equal to ${twoFactor ? 320 : 1600} characters`);
  return problems;
}

async function readBody(req) {
  const chunks = [];
  for await (const chunk of req) chunks.push(chunk);
  return Buffer.concat(chunks).toString('utf8');
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://fake');
  const raw = await readBody(req);

  if (url.pathname.startsWith('/__')) {
    if (url.pathname === '/__requests') return json(res, 200, { requests: state.requests, texts: state.texts });
    if (url.pathname === '/__shutdown') return json(res, 200, { ok: true });
    if (url.pathname === '/__reset') {
      reset();
      return json(res, 200, { ok: true });
    }
    if (url.pathname === '/__control') {
      const control = raw ? JSON.parse(raw) : {};
      if (control.mode !== undefined) state.mode = control.mode;
      if (control.times !== undefined) state.times = Number(control.times) || 0;
      if (control.delayMs !== undefined) state.delayMs = Number(control.delayMs) || 0;
      if (control.devicesOnline !== undefined) state.devicesOnline = control.devicesOnline;
      return json(res, 200, { ok: true });
    }
    return json(res, 404, { statusCode: 404, message: 'Not Found' });
  }

  let body = null;
  if (raw) {
    try {
      body = JSON.parse(raw);
    } catch {
      body = raw;
    }
  }
  const record = {
    method: req.method,
    path: url.pathname,
    keyOk: req.headers['x-api-key'] === TEST_KEY,
    contentType: req.headers['content-type'] || '',
    userAgent: req.headers['user-agent'] || '',
    idempotencyKey: req.headers['idempotency-key'] ?? null,
    body,
    status: null,
  };
  state.requests.push(record);
  const answer = await route(req, res, url, body);
  if (answer) {
    record.status = answer.status;
    record.replayed = Boolean(answer.headers && answer.headers['Idempotent-Replayed']);
  }
});

async function route(req, res, url, body) {
  if (req.headers['x-api-key'] !== TEST_KEY) return json(res, 401, errors.badKey);
  const mode = modeNow();
  if (mode === 'hang') return null;
  if (mode === 'drop') {
    res.socket.destroy();
    return null;
  }
  if (mode === 'html502') {
    res.writeHead(502, { 'Content-Type': 'text/html' });
    res.end('<html><head><title>502 Bad Gateway</title></head><body><center><h1>502 Bad Gateway</h1></center><hr><center>nginx</center></body></html>');
    return { status: 502 };
  }
  if (mode === 'auth') return json(res, 401, errors.badKey);
  if (mode === 'forbidden') return json(res, 403, errors.forbidden);
  if (state.delayMs > 0) await new Promise((resolve) => setTimeout(resolve, state.delayMs));

  const send = req.method === 'POST' && (url.pathname === '/api/v1/sms/send' || url.pathname === '/api/v1/sms/send-2fa');
  if (send) {
    const twoFactor = url.pathname.endsWith('send-2fa');
    const payload = body && typeof body === 'object' && !Array.isArray(body) ? body : {};
    const problems = validateSend(payload, twoFactor);
    if (problems.length) return json(res, 400, { statusCode: 400, message: problems, error: 'Bad Request' });

    // Idempotency-Key, the way the server keeps it.
    const header = req.headers['idempotency-key'];
    let keyHash = null;
    if (typeof header === 'string' && header.trim() !== '') {
      if (header.length > 255 || !/^[\x20-\x7E]+$/.test(header)) return json(res, 400, errors.invalidKey);
      keyHash = crypto.createHash('sha256').update(header.trim()).digest('hex');
      const fingerprint = crypto.createHash('sha256').update(`${url.pathname}\n${canonical(payload)}`).digest('hex');
      const stored = state.keys.get(keyHash);
      if (stored) {
        if (stored.fingerprint !== fingerprint) return json(res, 422, errors.reused);
        return json(res, stored.status, stored.body, { 'Idempotent-Replayed': 'true' });
      }
      if (mode === 'inprogress') return json(res, 409, errors.inProgress, { 'Retry-After': '1' });
      state.keys.set(keyHash, { fingerprint, status: 0, body: null, pending: true });
    }
    const finish = (status, answerBody, headers = {}) => {
      if (keyHash) {
        if (status >= 400 && status < 500) state.keys.delete(keyHash);
        else state.keys.set(keyHash, { ...state.keys.get(keyHash), status, body: answerBody, pending: false });
      }
      return json(res, status, answerBody, headers);
    };

    if (mode === 'rate') return finish(429, errors.rate, { 'Retry-After': '42' });
    if (mode === 'error500') return finish(500, errors.server);
    if (twoFactor && !SIGN_IN_CODE.some((pattern) => pattern.test(payload.message))) return finish(400, errors.noCode);

    const index = payload.deviceId ? PHONES.findIndex((p) => p.id === payload.deviceId) : state.devicesOnline.findIndex(Boolean);
    const online = index >= 0 && Boolean(state.devicesOnline[index]);
    const id = crypto.randomBytes(12).toString('hex');
    state.texts.push({ id, to: payload.to, message: payload.message, deviceId: payload.deviceId ?? null, defaultCountryCode: payload.defaultCountryCode ?? null, twoFactor });
    return finish(200, {
      success: true,
      messageId: id,
      ...(online ? {} : { queued: true }),
      data: { messageId: id, branded: false, ...(online ? {} : { queued: true }), remaining: { clean: -1, total: 'unlimited' } },
    });
  }

  if (req.method === 'GET' && url.pathname === '/api/v1/devices') {
    const devices = PHONES.map((phone, i) => ({ id: phone.id, name: phone.name, isOnline: Boolean(state.devicesOnline[i]), lastSeenAt: new Date().toISOString() }));
    return json(res, 200, { success: true, data: { devices, online: devices.filter((d) => d.isOnline).length, total: devices.length } });
  }

  return json(res, 404, { message: `Cannot ${req.method} ${url.pathname}`, error: 'Not Found', statusCode: 404 });
}

const host = args.host || '127.0.0.1';
server.keepAliveTimeout = 2000;
server.listen(Number(args.port || 0), host, () => {
  const { port } = server.address();
  if (args['port-file']) fs.writeFileSync(args['port-file'], String(port));
  console.log(JSON.stringify({ listening: port, host }));
});

function shutdown() {
  server.close(() => process.exit(0));
  if (typeof server.closeAllConnections === 'function') server.closeAllConnections();
  setTimeout(() => process.exit(0), 1000).unref();
}
for (const signal of ['SIGINT', 'SIGTERM', 'SIGBREAK']) process.on(signal, shutdown);
server.on('request', (req) => {
  if (req.method === 'POST' && req.url === '/__shutdown') setTimeout(shutdown, 50);
});
