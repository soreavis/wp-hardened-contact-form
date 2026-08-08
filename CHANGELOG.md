# Changelog

All notable changes to this skill are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this skill adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.2] - 2026-08-08

### Fixed
- A payload omitting `_ts` skipped the timing layer entirely, because the check was guarded on `$ts > 0`. The form always renders the field, so an absent one now fails the layer instead of bypassing it, logged as `missing_ts` to keep it separable from `timing_too_fast`. A custom client posting to the endpoint has to send the field.

### Added
- `templates/contact-validation.test.php` covers the Turnstile configuration gate — four key combinations through the real `verifyTurnstile`, asserting both the return and the `half-configured` log line. Regression cover for 0.1.1; it fails against the pre-0.1.1 handler.

### Changed
- The PHP suite drives `ContactHandler::isGibberish` by reflection instead of an inline transcription of it, so the detector's shipped code is what gets tested. Verified by mutation: neutering the real method now fails three assertions that previously stayed green. A missing `ContactHandler.php` is a hard failure rather than a silent skip.

## [0.1.1] - 2026-08-08

### Fixed
- Turnstile no longer rejects every submission when only one of `TURNSTILE_SITE_KEY` / `TURNSTILE_SECRET_KEY` is set. No widget renders without a site key, so no token could exist, and the secret-only check returned `403 captcha_failed` to every visitor. The endpoint now requires both keys before it enforces, and logs `turnstile half-configured` while the layer is off.
- The `_ts` timing field is no longer re-stamped from the browser clock when the modal opens. The server compares it against its own `time()`, so a visitor whose machine ran ahead of the host produced a negative elapsed time and was silently dropped by the timing layer for the length of the offset. The client now restores the server-rendered value.

### Added
- `docs/` — getting started, how it works, configuration, testing, troubleshooting.
- Hero image in the README.

## [0.1.0] - 2026-04-21

### Added
- Initial public release.
- `templates/ContactHandler.php` — 9-layer defense REST endpoint (CSRF nonce, honeypot, timing, interaction count, gibberish heuristic, rate limit, field validation, Cloudflare Turnstile, Resend delivery).
- `templates/validation.json` — single source of truth for client + server validation rules.
- `templates/contact-modal.blade.php` — accessible glass lightbox form with `role="dialog"`, focus trap, per-field `role="alert" aria-live="polite"` error slots, honeypot, hidden `_ts` / `_interactions` / `cf_turnstile_token` fields, GDPR consent line.
- `templates/contact-modal.js` — client-side modal controller: entrance/exit transitions, ESC + backdrop close, focus trap, interaction counter, Turnstile Managed-mode render with `interaction-only` appearance, client-side validation mirroring the shared config.
- `templates/setup-rest-route.php` — REST route registration + `window.{SLUG}` config injection + conditional Turnstile script enqueue.
- `templates/.env.example` — documented env var shell for Resend + Turnstile keys.
- `templates/contact.test.js` + `templates/contact-validation.test.php` — zero-framework test suites covering config shape, shared email regex (JS ↔ PHP parity), message length rules, gibberish-detector positive + negative cases, and honeypot field-name hygiene.
- `SKILL.md` + `README.md` + `LICENSE` (MIT).

### Notes
- Extracted from a production WordPress theme (Sage 11) currently running this stack. The templates target Sage 10/11 + Acorn specifically — adapting to other stacks is out of scope for v0.1.0.
