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

The JS suite loads `validation.json`, rebuilds the client's validation from it, and asserts the config has the shape the client expects, that the shared email regex accepts a normal and a plus-addressed address while rejecting a missing `@`, a missing TLD, an empty string and an over-length one, and that message length is enforced at both bounds. Its last test reads the Blade partial and asserts the honeypot is still named `website` and still is not `_gotcha` or `honeypot`.

The PHP suite loads the same file, compiles the same regex under PCRE, and runs the same accept/reject set — that pairing is the actual drift check, since a pattern that behaves differently in the two engines fails on one side. It then exercises the gibberish detector in both directions: keyboard mash, a vowel-starved string and a long consonant run must flag; an ordinary English sentence, a line with an umlaut, a string below the skip threshold, technical prose with punctuation, and a Slavic-plus-German name line must not.

The PHP suite also covers the Turnstile configuration gate, which is the one place it loads `ContactHandler` itself. Four key combinations go through the real `verifyTurnstile` by reflection: neither key set skips silently, either key alone skips *and* writes `half-configured` to the log, and a fully configured pair rejects an empty token. All four return before any network call, and `env()` is stubbed only when the runtime has none — which is always the case for a file run directly by the PHP CLI. It is regression cover for the 0.1.1 fix, and it fails against the handler as it stood before that.

CI runs both on every push to `main` and every pull request, alongside `php -l` on the templates, a JSON parse of the config, and the private-data check in `scripts/check-hygiene.py`.

## What they do not check

Worth knowing before you rely on a green run.

**No HTTP, no WordPress.** Neither suite boots WordPress or calls the endpoint over the wire. The Turnstile block above is the one place a suite loads `ContactHandler` at all, and it reaches a single method that returns before any network call. Nonce handling, the honeypot, timing, interaction counting, rate limiting and the fake-200 behaviour are exercised by hand — the checklist is in [How it works](how-it-works.md#seeing-the-layers-fire).

**The gibberish detector is tested as a copy.** `contact-validation.test.php` carries its own inline `isGibberish()` mirroring the one in `ContactHandler`. That copy predates the Turnstile tests, which showed the handler can be loaded without WordPress after all — so the separation is now habit rather than necessity, and driving the real method by reflection would close the gap. As it stands the two are identical, and editing one without the other leaves the suite green.

**The client is tested as a copy too, for a harder reason.** The templates are not runnable as shipped: `contact-modal.js` contains `window.{SLUG}` at module scope, so it does not parse until the placeholders are substituted — `node --check` on it fails with a syntax error. The PHP templates lint clean only because their placeholders sit inside strings. So the JS suite reimplements `validateClient`'s rules rather than importing them, with the same consequence as above.

**ASCII only.** Every length case uses `'a'.repeat(n)`. The server counts bytes and the browser counts UTF-16 code units, and nothing here covers the range where those disagree.

**Nothing touches the network.** No request leaves the machine. The Turnstile tests stop at the configuration gate, before `siteverify` is ever contacted, so a real token has never been checked here. Resend is not called at all, which leaves `not_configured`, `upstream` and `network` untested.

**No DOM, no accessibility.** The focus trap, the ESC and backdrop close, the `aria-live` error slots and the `aria-hidden` toggling are unverified by machine. Tab into the modal, confirm the cycle stays inside it, Shift-Tab back, press ESC, and listen to a field error with VoiceOver or NVDA.

## When one fails

A failure after a rule change means `validation.json` and one of the two runtimes have drifted, and the suite tells you which. A failure straight after install is more likely a path problem — both suites resolve the config relative to their own directory, so a non-standard layout needs the candidate paths adjusted.
