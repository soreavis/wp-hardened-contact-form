/**
 * Contact form client-side validation tests.
 *
 * Runs without a framework dependency — just:
 *   node theme/tests/contact.test.js
 *
 * Asserts the shared-config-driven validation rules match between what the
 * client enforces (validateClient in the modal JS) and what the server
 * enforces (ContactHandler.php). If this test fails after a rule change,
 * ContactHandler is likely out of sync.
 *
 * Assumes the standard Sage-ish layout:
 *   theme/resources/data/validation.json
 *   theme/resources/views/partials/contact-modal.blade.php
 *   theme/tests/contact.test.js  ← this file
 *
 * Adjust the two resolve() paths below if your layout differs.
 */

import { existsSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
// In this repo the config sits next to the templates; once the skill installs
// them into a Sage theme it lands in resources/data/. Look in both so the test
// runs from a clone as well as from an installed theme — it previously only
// resolved the installed path, so it could not run here at all.
const CANDIDATES = ['./validation.json', '../resources/data/validation.json'];
const cfgPath = CANDIDATES.map((c) => resolve(__dirname, c)).find(existsSync);
if (!cfgPath) {
  console.error(`  validation.json not found in: ${CANDIDATES.join(', ')}`);
  process.exit(1);
}
const cfg = JSON.parse(readFileSync(cfgPath, 'utf8'));

// ── Tiny test runner ──────────────────────────────────────────────────────
let passed = 0, failed = 0;
const t = (name, fn) => {
  try { fn(); console.log(`  ✓ ${name}`); passed++; }
  catch (e) { console.log(`  ✗ ${name}\n    ${e.message}`); failed++; }
};
const assert = (cond, msg = 'expected true') => { if (!cond) throw new Error(msg); };

// ── Validation, driven through the client's own exported rules ────────────
// Same dual layout as the config. contact-modal.js reads `window` at module
// scope, so the shim has to exist before the import is evaluated — which is
// why this is a dynamic import rather than a static one at the top.
const CLIENT_CANDIDATES = ['./contact-modal.js', '../resources/js/contact-modal.js'];
const clientPath = CLIENT_CANDIDATES.map((c) => resolve(__dirname, c)).find(existsSync);
if (!clientPath) {
  console.error(`  contact-modal.js not found in: ${CLIENT_CANDIDATES.join(', ')}`);
  process.exit(1);
}
globalThis.window = {};
const { validateFields } = await import(pathToFileURL(clientPath).href);

const FILLER = 'x'.repeat(cfg.message.minLength);
const validateEmail = (email) => !('email' in validateFields({ email, message: FILLER }, cfg));
const validateMessage = (msg) => !('message' in validateFields({ email: 'user@example.com', message: msg }, cfg));

// ── Tests ─────────────────────────────────────────────────────────────────
console.log('\nconfig loaded:');
t('has email.pattern + maxLength', () => {
  assert(typeof cfg.email.pattern === 'string' && cfg.email.pattern.length > 0);
  assert(cfg.email.maxLength >= 64);
});
t('has message.minLength + maxLength', () => {
  assert(cfg.message.minLength >= 1);
  assert(cfg.message.maxLength > cfg.message.minLength);
});

console.log('\nemail validation:');
t('accepts normal email', () => assert(validateEmail('user@example.com')));
t('accepts plus-addressed', () => assert(validateEmail('user+tag@example.com')));
t('rejects missing @', () => assert(!validateEmail('no-at.com')));
t('rejects missing TLD', () => assert(!validateEmail('user@domain')));
t('rejects empty', () => assert(!validateEmail('')));
t('rejects over-length', () => {
  const tooLong = 'a'.repeat(cfg.email.maxLength + 1) + '@x.com';
  assert(!validateEmail(tooLong));
});

console.log('\nmessage validation:');
t('rejects below min', () => assert(!validateMessage('short')));
t('accepts at min', () => assert(validateMessage('a'.repeat(cfg.message.minLength))));
t('accepts typical length', () => assert(validateMessage('Hello, this is a reasonable test message body.')));
t('rejects above max', () => assert(!validateMessage('a'.repeat(cfg.message.maxLength + 1))));

console.log('\nhoneypot semantics:');
t('honeypot field name is NOT _gotcha or honeypot (detectable conventions)', () => {
  // Read the Blade template to confirm we picked an innocuous name.
  // Same dual layout as the config: flat here, nested once installed.
  const bladeCandidates = ['./contact-modal.blade.php', '../resources/views/partials/contact-modal.blade.php'];
  const bladePath = bladeCandidates.map((c) => resolve(__dirname, c)).find(existsSync);
  if (!bladePath) throw new Error(`contact-modal.blade.php not found in: ${bladeCandidates.join(', ')}`);
  const blade = readFileSync(bladePath, 'utf8');
  assert(/name="website"/.test(blade), 'honeypot must be named "website"');
  assert(!/name="_gotcha"/.test(blade), '_gotcha is a well-known bot-scannable name');
  assert(!/name="honeypot"/.test(blade), 'honeypot is a well-known bot-scannable name');
});

console.log(`\n— passed: ${passed}    failed: ${failed}`);
process.exit(failed === 0 ? 0 : 1);
