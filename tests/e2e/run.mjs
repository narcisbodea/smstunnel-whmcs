// End-to-end test of SMSTunnel for WHMCS against a local SMSTunnel API and a simulated phone.
//
//   node tests/e2e/run.mjs
//
// 1. Seeds a database of its own (smstunnel_whmcs_test on the test MongoDB 127.0.0.1:27018) with a
//    copy of the dev seed, made on the fly with that database's address (the seed empties the
//    database it points at, so the copy refuses any other).
// 2. Starts the SMSTunnel API build on port 3171 against that database, and the simulated phone,
//    which goes online and marks every text sent.
// 3. Signs in as the seeded user and creates an API key through the API.
// 4. Runs tests/e2e/e2e.php in Docker (PHP 8.3): the module on the test WHMCS fires its real hook
//    callbacks, sends a test text and a sign-in code, retries after an outage, replays a key.
// 5. Checks every text in the API's `messages` collection (number, words, sent by the phone, no
//    duplicates, nothing to skipped clients), hands the sign-in code the phone got back to PHP,
//    which verifies it.
// 6. Stops the API and the phone, drops the database, takes the Docker project down.
//
// The paths of the API build and the dev tools come from SMSTUNNEL_SERVER_DIR, SMSTUNNEL_DEV_DIR and
// SMSTUNNEL_NODE_MODULES (defaults: the SMSTunnel developer machine). Nothing goes to smstunnel.io;
// recipients are +1415555xxxx test numbers.
import { spawn, spawnSync } from 'node:child_process';
import { createRequire } from 'node:module';
import fs from 'node:fs';
import net from 'node:net';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const repo = path.resolve(here, '../..');
const state = path.join(here, '.state');
const DB = 'smstunnel_whmcs_test';
const MONGO = `mongodb://127.0.0.1:27018/${DB}`;
const PORT = 3171;
const API = `http://127.0.0.1:${PORT}`;
const SERVER_DIR = process.env.SMSTUNNEL_SERVER_DIR || 'D:/ANDROID/SMSserver-shopify';
const DEV_DIR = process.env.SMSTUNNEL_DEV_DIR || 'D:/ANDROID/smstunnel-app/dev';
const NODE_MODULES = process.env.SMSTUNNEL_NODE_MODULES || 'D:/ANDROID/SMSserver/node_modules';
const SEED_PASSWORD_LINE = /^const PASSWORD = "([^"]+)"$/m;
const COMPOSE = ['compose', '-p', 'smst-int-whmcs', '-f', path.join(repo, 'tests/docker/docker-compose.yml')];

const serverRequire = createRequire(path.join(NODE_MODULES, '..', 'package.json'));
const { MongoClient, ObjectId } = serverRequire('mongodb');

let passed = 0;
let failed = 0;
const failures = [];
function check(ok, label, detail = '') {
  if (ok) {
    passed += 1;
    console.log(`  ok   ${label}`);
  } else {
    failed += 1;
    failures.push(label);
    console.log(`  FAIL ${label}${detail ? `\n       ${detail}` : ''}`);
  }
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function portFree(port) {
  return new Promise((resolve) => {
    const socket = net.connect({ host: '127.0.0.1', port }, () => {
      socket.destroy();
      resolve(false);
    });
    socket.on('error', () => resolve(true));
  });
}

// Only what the API and the phone need: no credentials of this machine's shell reach them.
function cleanEnv(extra) {
  const keep = ['PATH', 'Path', 'SystemRoot', 'SYSTEMROOT', 'WINDIR', 'windir', 'TEMP', 'TMP', 'USERPROFILE', 'APPDATA', 'LOCALAPPDATA', 'HOMEDRIVE', 'HOMEPATH', 'HOME', 'ComSpec', 'PATHEXT'];
  const env = {};
  for (const name of keep) if (process.env[name] !== undefined) env[name] = process.env[name];
  return { ...env, ...extra };
}

async function waitFor(fn, label, timeoutMs) {
  const until = Date.now() + timeoutMs;
  let last;
  while (Date.now() < until) {
    try {
      last = await fn();
      if (last) return last;
    } catch (error) {
      last = error;
    }
    await sleep(300);
  }
  throw new Error(`Timed out waiting for ${label}${last instanceof Error ? `: ${last.message}` : ''}`);
}

async function api(method, route, { key, token, body } = {}) {
  const headers = { Accept: 'application/json' };
  if (body) headers['Content-Type'] = 'application/json';
  if (key) headers['X-API-Key'] = key;
  if (token) headers.Authorization = `Bearer ${token}`;
  const res = await fetch(API + route, { method, headers, body: body ? JSON.stringify(body) : undefined });
  const text = await res.text();
  let json = null;
  try {
    json = JSON.parse(text);
  } catch {
    json = text;
  }
  return { status: res.status, json };
}

// The dev seed, pointed at this test's own database.
function makeSeed() {
  const original = fs.readFileSync(path.join(DEV_DIR, 'seed.mjs'), 'utf8');
  const uriLine = 'const URI = "mongodb://127.0.0.1:27018/smstunnel_app_dev"';
  if (!original.includes(uriLine)) throw new Error('The dev seed changed: its URI line is not where expected. Not running it.');
  let seed = original.replace(uriLine, `const URI = "${MONGO}"\nif (!URI.endsWith("/${DB}")) throw new Error("This copy of the seed only seeds ${DB}.")`);
  // The test account sends ~20 texts a run: a Standard plan keeps the free line out of them.
  seed = seed.replace('const alex = await user("alex@smstunnel.test", "Alex", "Popescu")', 'const alex = await user("alex@smstunnel.test", "Alex", "Popescu", { plan: "standard" })');
  seed = seed.replace(/Seeded smstunnel_app_dev:/g, `Seeded ${DB}:`);
  const others = (seed.match(/mongodb:\/\/[^"'`\s]+/g) || []).filter((uri) => uri !== MONGO);
  if (others.length) throw new Error(`The seed copy still names another database: ${others.join(', ')}`);
  const password = (original.match(SEED_PASSWORD_LINE) || [])[1];
  if (!password) throw new Error('The dev seed has no test password line.');
  fs.writeFileSync(path.join(state, 'seed.mjs'), seed);
  return password;
}

const children = [];
function start(label, args, options, logFile) {
  const out = fs.openSync(logFile, 'w');
  const child = spawn(process.execPath, args, { ...options, stdio: ['ignore', out, out], windowsHide: true });
  child.label = label;
  children.push(child);
  return child;
}

async function main() {
  fs.rmSync(state, { recursive: true, force: true });
  fs.mkdirSync(state, { recursive: true });
  if (!(await portFree(PORT))) throw new Error(`Port ${PORT} is in use: not starting a second API.`);
  if (!fs.existsSync(path.join(repo, 'tests/.cache/vendor-php83/autoload.php'))) {
    throw new Error('Run bash tools/check.sh 83 first (it installs the test WHMCS libraries for PHP 8.3).');
  }

  console.log(`== seed ${DB}`);
  const password = makeSeed();
  const seeded = spawnSync(process.execPath, [path.join(state, 'seed.mjs')], { encoding: 'utf8', env: cleanEnv({}) });
  if (seeded.status !== 0) throw new Error(`seed failed: ${seeded.stderr || seeded.stdout}`);
  console.log(`   ${seeded.stdout.trim()}`);

  console.log(`== SMSTunnel API on ${PORT} (${SERVER_DIR}/dist/main.js)`);
  const startedAt = new Date();
  start('api', [path.join(SERVER_DIR, 'dist/main.js')], {
    cwd: SERVER_DIR,
    env: cleanEnv({
      MONGODB_URI: MONGO,
      PORT: String(PORT),
      JWT_SECRET: 'testsecret_app_dev',
      JWT_ACCESS_EXPIRY: '30d',
      JWT_REFRESH_EXPIRY: '90d',
      NODE_ENV: 'development',
      FRONTEND_URL: 'http://localhost:5180',
      DASHBOARD_URL: 'http://localhost:5180',
      SERVER_URL: API,
      DEVICE_PROOF_HOSTS: 'localhost',
      NODE_PATH: NODE_MODULES,
      // Placeholders: the sign-in-with-Google/Facebook strategies refuse to start without them (no
      // .env is read here, so no real credential of this machine reaches the test API).
      GOOGLE_CLIENT_ID: 'local-test-only',
      GOOGLE_CLIENT_SECRET: 'local-test-only',
      FACEBOOK_APP_ID: 'local-test-only',
      FACEBOOK_APP_SECRET: 'local-test-only',
    }),
  }, path.join(state, 'api.log'));
  await waitFor(async () => (await api('GET', '/api/v1/public/plans')).status === 200, 'the API', 120000);

  console.log('== simulated phone');
  start('phone', [path.join(DEV_DIR, 'fake-phone.mjs')], {
    cwd: DEV_DIR,
    env: cleanEnv({ FAKE_PHONE_API: API, FAKE_PHONE_MONGODB_URI: MONGO, FAKE_PHONE_JWT_SECRET: 'testsecret_app_dev' }),
  }, path.join(state, 'phone.log'));
  await waitFor(() => fs.readFileSync(path.join(state, 'phone.log'), 'utf8').includes('Online as'), 'the phone to come online', 30000);

  console.log('== API key (signed in as the seeded user)');
  const login = await api('POST', '/api/v1/auth/login', { body: { email: 'alex@smstunnel.test', password } });
  if (login.status !== 200) throw new Error(`login: ${login.status} ${JSON.stringify(login.json)}`);
  const created = await api('POST', '/api/v1/api-keys', { token: login.json.data.accessToken, body: { name: 'WHMCS end-to-end test', permissions: ['sms:send', 'sms:read'] } });
  if (created.status !== 200) throw new Error(`api key: ${created.status} ${JSON.stringify(created.json)}`);
  const key = created.json.data.plainKey;
  const devices = await api('GET', '/api/v1/devices', { key });
  const phone = (devices.json.data.devices || []).find((device) => device.isOnline);
  if (!phone) throw new Error('No phone is online.');
  console.log(`   key ${created.json.data.apiKey.maskedKey}, phone "${phone.name}" online`);

  console.log('== PHP 8.3 in Docker: the module fires its hooks');
  const php = spawn('docker', [...COMPOSE, 'run', '--rm', '-T', '-e', 'SMSTUNNEL_E2E_URL', '-e', 'SMSTUNNEL_E2E_KEY', '-e', 'SMSTUNNEL_E2E_DEVICE', 'php83', 'php tests/e2e/e2e.php'], {
    cwd: repo,
    env: { ...process.env, MSYS_NO_PATHCONV: '1', SMSTUNNEL_E2E_URL: `http://host.docker.internal:${PORT}`, SMSTUNNEL_E2E_KEY: key, SMSTUNNEL_E2E_DEVICE: phone.id },
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  let phpOutput = '';
  php.stdout.on('data', (chunk) => (phpOutput += chunk));
  php.stderr.on('data', (chunk) => (phpOutput += chunk));
  const phpDone = new Promise((resolve) => php.on('close', resolve));
  const resultsFile = path.join(state, 'results.json');
  await Promise.race([
    waitFor(() => fs.existsSync(resultsFile), 'results.json', 240000),
    phpDone.then(() => {
      if (!fs.existsSync(resultsFile)) throw new Error(`PHP stopped early:\n${phpOutput}`);
    }),
  ]);
  const results = JSON.parse(fs.readFileSync(resultsFile, 'utf8'));

  const mongo = await new MongoClient(MONGO).connect();
  try {
    const messages = mongo.db().collection('messages');
    const byId = async (id) => (id && ObjectId.isValid(id) ? messages.findOne({ _id: new ObjectId(id) }) : null);

    console.log('\n== the texts, as the API stored them and the phone sent them');
    for (const text of results.texts) {
      const found = await waitFor(async () => {
        const message = await byId(text.messageId);
        return message && ['sent', 'delivered'].includes(message.status) ? message : null;
      }, `${text.label} to be sent`, 20000).catch(() => null);
      const expected = text.body || {};
      check(Boolean(found) && found.phoneNumber === text.to && found.message === expected.message && expected.to === text.to,
        `${text.label}: ${text.to} "${(expected.message || '').slice(0, 70)}${(expected.message || '').length > 70 ? '...' : ''}"`,
        found ? `stored ${found.phoneNumber} ${found.status} "${found.message}"` : `no sent message ${text.messageId} (module: ${text.status} ${text.error})`);
      if (expected.message) check(/^\+1415555\d{4}$/.test(expected.to), `${text.label}: a +1415555xxxx test number, one recipient`);
    }
    check(results.texts.filter((t) => t.event === 'staff_order_paid').length === 2, 'the paid order alerted both staff numbers, one call each');
    const sentIds = results.texts.map((t) => t.messageId).concat(results.signIn.messageId);
    const created = await messages.countDocuments({ type: 'outgoing', createdAt: { $gte: startedAt } });
    check(created === new Set(sentIds).size, `exactly one stored message per text (${created} messages, ${new Set(sentIds).size} texts)`);

    console.log('\n== skipped, deduplicated, replayed, retried');
    check(results.skipped.length === 3, 'three clients skipped', JSON.stringify(results.skipped));
    for (const [number, reason] of [['+14155550103', 'opted out'], ['+19005550105', 'premium']]) {
      const count = await messages.countDocuments({ phoneNumber: number, createdAt: { $gte: startedAt } });
      check(count === 0 && results.skipped.some((s) => s.error.includes(reason)), `nothing to ${number} (${reason})`);
    }
    check(results.skipped.some((s) => s.error === 'no phone number on the client profile'), 'the client without a number is logged as skipped');
    check(results.checks.duplicateHookSentNothing === true, 'the same hook fired again sends nothing');
    check(results.checks.replay.replayed === true && results.checks.replay.sameMessageId === true && results.checks.replay.keyIsTheLoggedOne === true,
      'a request sent again with its Idempotency-Key: the same message ID, Idempotent-Replayed, no second text', JSON.stringify(results.checks.replay));
    check(results.checks.reusedKey === '422 IDEMPOTENCY_KEY_REUSED', 'another text under a used key is refused (422)', results.checks.reusedKey);
    check(results.checks.outage.status === 'retry' && results.checks.outage.error.includes('Could not reach SMSTunnel'), 'an outage leaves the text waiting for the cron', JSON.stringify(results.checks.outage));
    const retried = results.texts.find((t) => t.label === 'invoice paid after an outage');
    check(retried && retried.status === 'sent' && retried.attempts === 2 && retried.key === results.checks.outage.key, 'the cron sent it on the second try, with the same key', JSON.stringify(retried));
    check(results.checks.testTextShownOnPage === true, "the admin page shows the test text's message ID");
    check(results.checks.phonesListed === true, 'the admin page lists the phone as online (GET /api/v1/devices)');
    check(results.log.every((row) => !row.hasRequest), 'no row keeps its words once done');

    console.log('\n== sign-in code');
    const signIn = await waitFor(async () => {
      const message = await byId(results.signIn.messageId);
      return message && ['sent', 'delivered'].includes(message.status) ? message : null;
    }, 'the sign-in code to be sent', 20000).catch(() => null);
    const code = signIn ? (signIn.message.match(/^(\d{6}) is your sign-in code/) || [])[1] : undefined;
    check(Boolean(signIn) && signIn.phoneNumber === '+14155550177' && Boolean(code), 'the code went to the user\'s phone through send-2fa', signIn ? signIn.message.replace(/\d{6}/g, '######') : 'not found');
    check(results.signIn.shown === true && results.signIn.loggedRequest === null, 'the challenge page says so; the log keeps no words');
    fs.writeFileSync(path.join(state, 'code.txt'), code || 'none');
  } finally {
    await mongo.close();
  }

  const exit = await phpDone;
  const verifyFile = path.join(state, 'verify.json');
  if (!fs.existsSync(verifyFile)) throw new Error(`PHP did not verify the code (exit ${exit}):\n${phpOutput}`);
  const verified = JSON.parse(fs.readFileSync(verifyFile, 'utf8'));
  check(verified.codeReceived && verified.wrong === false && verified.right === true && verified.again === false,
    'a wrong code fails, the code from the phone signs in once, then no more', JSON.stringify(verified));
  check(verified.codeNotStored === true, 'the code is in no table and no log');
  check(exit === 0, 'the PHP side finished cleanly', phpOutput.slice(-2000));
}

async function cleanup() {
  for (const child of children.reverse()) {
    if (child.exitCode === null) child.kill();
  }
  spawnSync('docker', [...COMPOSE, 'down', '-v', '--remove-orphans'], { cwd: repo, stdio: 'ignore', env: { ...process.env, MSYS_NO_PATHCONV: '1' } });
  try {
    const mongo = await new MongoClient(MONGO).connect();
    if (mongo.db().databaseName === DB) await mongo.db().dropDatabase();
    await mongo.close();
    console.log(`\n== cleaned up: API and phone stopped, ${DB} dropped, Docker project down`);
  } catch (error) {
    console.log(`\n== could not drop ${DB}: ${error.message}`);
  }
  // The API key and the code were in .state: gone. The API's and the phone's logs (the phone's holds
  // the texts) stay only when something failed, for a look.
  const files = ['results.json', 'verify.json', 'code.txt', 'seed.mjs'];
  if (failed === 0) files.push('api.log', 'phone.log');
  for (const file of files) fs.rmSync(path.join(state, file), { force: true });
}

try {
  await main();
} catch (error) {
  failed += 1;
  failures.push(error.message);
  console.log(`\nERROR ${error.message}`);
} finally {
  await cleanup();
}
console.log(`\nend-to-end: ${passed} checks passed, ${failed} failed`);
if (failures.length) console.log(failures.map((f) => ` - ${f}`).join('\n'));
process.exit(failed > 0 ? 1 : 0);
