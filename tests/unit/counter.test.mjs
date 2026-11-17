// The editor's part counter (modules/addons/smstunnel/assets/counter.js) gives the same counts as
// lib/Text/SmsCounter.php for the shared vectors, and fills merge fields the same way.
//   node tests/unit/counter.test.mjs
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const require = createRequire(import.meta.url);
const counter = require(path.join(here, '../../modules/addons/smstunnel/assets/counter.js'));
const vectors = JSON.parse(fs.readFileSync(path.join(here, 'sms-vectors.json'), 'utf8'));

let checks = 0;
let failed = 0;
function eq(actual, expected, label) {
  checks += 1;
  const a = JSON.stringify(actual);
  const e = JSON.stringify(expected);
  if (a !== e) {
    failed += 1;
    console.log(`FAIL ${label}\n    expected ${e}\n    actual   ${a}`);
  }
}

for (const vector of vectors) {
  const text = vector.build.map(([piece, times]) => piece.repeat(times)).join('');
  const c = counter.count(text);
  eq([c.encoding, c.units, c.parts], [vector.encoding, vector.units, vector.parts], vector.name);
}
eq(counter.count('Plătit 👍').nonGsm, ['ă', '👍'], 'the characters that make a text Unicode');
eq(counter.render('Hi {client_first_name} , invoice #{$invoice_num}. {unknown}', { client_first_name: '', invoice_num: '7' }), 'Hi, invoice #7. {unknown}', 'render like Template::render');
eq(counter.fieldsIn('{a} {$B} {a}'), ['a', 'b'], 'fields');
eq(counter.describe('Hi Ana'), '1 SMS · 6 characters · GSM 7-bit (160 per SMS)', 'the same line as Page::countLine');
eq(counter.describe('ă'.repeat(71)), '2 SMS · 71 characters · Unicode (67 per part) · Unicode because of: ă', 'Unicode line');
eq(counter.describe(''), 'Empty: nothing is sent.', 'empty');

console.log(`\ncounter.js: ${vectors.length} vectors, ${checks} checks, ${failed} failed`);
process.exit(failed > 0 ? 1 : 0);
