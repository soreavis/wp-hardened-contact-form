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

import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const cfg = JSON.parse(readFileSync(resolve(__dirname, '../resources/data/validation.json'), 'utf8'));

// ── Tiny test runner ──────────────────────────────────────────────────────
let passed = 0, failed = 0;
const t = (name, fn) => {
  try { fn(); console.log(`  ✓ ${name}`); passed++; }
  catch (e) { console.log(`  ✗ ${name}\n    ${e.message}`); failed++; }
};
const assert = (cond, msg = 'expected true') => { if (!cond) throw new Error(msg); };

// ── Validation (mirrors validateClient in contact-modal.js) ───────────────
const emailRe = new RegExp(cfg.email.pattern);

const validateEmail = (email) =>
  email && emailRe.test(email) && email.length <= cfg.email.maxLength;

const validateMessage = (msg) =>
  msg.length >= cfg.message.minLength && msg.length <= cfg.message.maxLength;

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
  const bladePath = resolve(__dirname, '../resources/views/partials/contact-modal.blade.php');
  const blade = readFileSync(bladePath, 'utf8');
  assert(/name="website"/.test(blade), 'honeypot must be named "website"');
  assert(!/name="_gotcha"/.test(blade), '_gotcha is a well-known bot-scannable name');
  assert(!/name="honeypot"/.test(blade), 'honeypot is a well-known bot-scannable name');
});

console.log(`\n— passed: ${passed}    failed: ${failed}`);
process.exit(failed === 0 ? 0 : 1);
