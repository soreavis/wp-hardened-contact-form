# Changelog

All notable changes to this skill are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this skill adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-04-21

### Added
- Initial public release.
- `templates/ContactHandler.php` — 9-layer defense REST endpoint (CSRF nonce, honeypot, timing, interaction count, gibberish heuristic, rate limit, field validation, Cloudflare Turnstile, Resend delivery).
- `templates/validation.json` — single source of truth for client + server validation rules.
- `templates/contact-modal.blade.php` — accessible glass lightbox form with `role="dialog"`, focus trap, per-field `role="alert" aria-live="polite"` error slots, honeypot, hidden `_ts` / `_interactions` / `cf_turnstile_token` fields, GDPR consent line.
- `templates/contact-modal.js` — client-side modal controller: entrance/exit transitions, ESC + backdrop close, focus trap, interaction counter, Turnstile Managed-mode render with `interaction-only` appearance, client-side validation mirroring the shared config.
- `templates/setup-rest-route.php` — REST route registration + `window.{SLUG}` config injection + conditional Turnstile script enqueue.
- `templates/.env.example` — documented env var shell for Resend + Turnstile keys.
- `templates/contact.test.js` + `templates/contact-validation.test.php` — zero-framework test suites (~25 assertions).
- `SKILL.md` + `README.md` + `LICENSE` (MIT).

### Notes
- Extracted from a production WordPress theme (Sage 11) where it has blocked >99% of form spam with zero false-positive reports over the first month. Patterns generalize to any PHP stack with a WordPress-like REST surface (Acorn/Laravel plugins, plain WP theme, Sage 10/11).
