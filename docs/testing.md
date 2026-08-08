# Testing

Two suites, no framework, no install step beyond Node and PHP.

```bash
# 13 assertions
node templates/contact.test.js

# 20 assertions
php templates/contact-validation.test.php
```

Both print a `passed: N  failed: 0` line and exit non-zero if anything fails. From a theme the skill has installed into, the same files live under `tests/` — each looks for `validation.json` next to itself first, then at `../resources/data/validation.json`, so the same file runs in both layouts.

## What they check

The JS suite loads `validation.json`, imports `validateFields` from the client itself, and feeds the config straight into it — so the rules under test are the ones that ship. It asserts the config has the shape the client expects, that the shared email regex accepts a normal and a plus-addressed address while rejecting a missing `@`, a missing TLD, an empty string and an over-length one, and that message length is enforced at both bounds. Its last test reads the Blade partial and asserts the honeypot is still named `website` and still is not `_gotcha` or `honeypot`.

The import needs one shim: `contact-modal.js` reads `window` at module scope, so the suite defines an empty `globalThis.window` before a dynamic import. Nothing else about the file is special-cased, and no placeholder substitution happens — it is loaded exactly as committed.

The PHP suite loads the same file, compiles the same regex under PCRE, and runs the same accept/reject set — that pairing is the actual drift check, since a pattern that behaves differently in the two engines fails on one side.

It then loads `ContactHandler` and reaches two of its private statics by reflection, so what runs is the shipped code rather than a transcription of it. No WordPress bootstrap is involved: both methods return before they touch WP or the network, and `env()` is stubbed only when the runtime has none, which is always the case for a file run directly by the PHP CLI.

`isGibberish` is exercised in both directions — keyboard mash, a vowel-starved string and a long consonant run must flag; an ordinary English sentence, a line with an umlaut, a string below the skip threshold, technical prose with punctuation, and a Slavic-plus-German name line must not. `verifyTurnstile` gets four key combinations: neither key set skips silently, either key alone skips *and* writes `half-configured` to the log, and a fully configured pair rejects an empty token. That last group is regression cover for the 0.1.1 fix and fails against the handler as it stood before it.

CI runs both on every push to `main` and every pull request, alongside `php -l` and `node --check` on the templates, a JSON parse of the config, and the private-data check in `scripts/check-hygiene.py`.

## What they do not check

Worth knowing before you rely on a green run.

**No HTTP, no WordPress.** Neither suite boots WordPress or calls the endpoint over the wire. `handle()` is never invoked, so nonce handling, the honeypot, timing, interaction counting, rate limiting and the fake-200 behaviour are exercised by hand — the checklist is in [How it works](how-it-works.md#seeing-the-layers-fire).

**ASCII only.** Every length case is built by repeating a single Latin character. The server counts bytes and the browser counts UTF-16 code units, and nothing here covers the range where those disagree.

**Nothing touches the network.** No request leaves the machine. The Turnstile tests stop at the configuration gate, before `siteverify` is ever contacted, so a real token has never been checked here. Resend is not called at all, which leaves `not_configured`, `upstream` and `network` untested.

**No DOM, no accessibility.** `initContactModal` is imported but never called — only the pure `validateFields` beside it is exercised. The focus trap, the ESC and backdrop close, the interaction counter, the Turnstile render, the `aria-live` error slots and the `aria-hidden` toggling are all unverified by machine. Tab into the modal, confirm the cycle stays inside it, Shift-Tab back, press ESC, and listen to a field error with VoiceOver or NVDA.

## When one fails

A failure after a rule change means `validation.json` and one of the two runtimes have drifted, and the suite tells you which. A failure straight after install is more likely a path problem — both suites resolve the config relative to their own directory, so a non-standard layout needs the candidate paths adjusted.
