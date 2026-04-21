---
name: hardened-contact-form
description: Install a production-hardened contact form into a WordPress / Sage / Acorn theme. Ships a 9-layer defense stack (CSRF nonce, honeypot, timing, interaction count, gibberish heuristic, rate limit, field validation, Cloudflare Turnstile, Resend delivery) with a single shared validation.json consumed by both JS and PHP, an accessible glass-lightbox modal (role=dialog, focus trap, per-field aria-live errors), zero-framework tests. Use when the user asks to add a contact form, harden an existing form against spam/bots, add Resend + Turnstile to a WP/Sage site, or set up a form with "no visible CAPTCHA."
allowed-tools: Read, Glob, Grep, Bash, Write, Edit, AskUserQuestion
---

# /hardened-contact-form

Install a hardened contact form into a **WordPress + Sage 10/11 + Acorn** (or plain WP theme with `composer.json` and Blade) project. All patterns come from a production site that has blocked >99% of form spam with zero false-positive reports over its first month in production.

Trigger examples: *"add a contact form to this theme"*, *"harden the form against bots"*, *"set up Resend + Turnstile contact"*, *"install a 9-layer contact form."*

---

## Stack requirements

- PHP 8.2+ (the endpoint uses typed properties + `fn()` arrow functions)
- WordPress 6.0+ (uses `wp_remote_post`, `get_transient`, `sanitize_email`, `is_email`)
- Sage 10 or 11 theme layout (Acorn-based), or any WP theme that can load Blade + register REST routes
- Resend account (free tier — https://resend.com)
- Optional: Cloudflare Turnstile widget (free — https://dash.cloudflare.com/?to=/:account/turnstile)

If the project doesn't meet all three of the first bullets, stop and tell the user which assumption failed. Do not try to adapt the endpoint to a Laravel-only, plain-PHP, or Node project — those are outside this skill's scope.

---

## What gets installed

Inside the target theme (paths assume a standard Sage layout — adjust if the project differs):

```
theme/
├── app/Http/ContactHandler.php              (new — 9-layer REST endpoint)
├── app/setup.php                            (append REST route + wp_head config)
├── resources/
│   ├── data/validation.json                 (new — shared validation rules)
│   ├── js/contact-modal.js                  (new — client-side modal controller)
│   └── views/partials/contact-modal.blade.php (new — accessible glass lightbox)
├── tests/
│   ├── contact.test.js                      (new — zero-framework JS tests)
│   └── contact-validation.test.php          (new — zero-framework PHP tests)
└── .env.example                             (append Resend + Turnstile keys)
```

The Blade partial is included in the site layout; the JS is imported from the app's main entry; the REST route is registered in `setup.php`.

---

## Phase 1 — Gather inputs

Use **AskUserQuestion** to batch. Skip any already derivable from project context.

| Q | What to ask | Derived from |
|---|---|---|
| Project slug | kebab-case, e.g. `mybrand` | theme folder name or domain stem if not obvious |
| Display name | e.g. "My Brand" | site title |
| Public domain | e.g. `mybrand.com` | homepage URL |
| Contact inbox | email where messages land | user provides |
| Resend from address | e.g. `noreply@mybrand.com` | must match a Resend-verified domain; fall back to `onboarding@resend.dev` for dev |
| Install Turnstile? | yes/no | yes → ask for site key + secret |

From the slug, derive three casings the templates need:

- `{slug}` — lowercase kebab (e.g. `mybrand`) — REST namespace, transient prefix, log tag
- `{SLUG}` — UPPERCASE (e.g. `MYBRAND`) — `window.{SLUG}` global
- `{Slug}` — PascalCase (e.g. `Mybrand`) — `X-{Slug}-Nonce` custom header

Document these in a one-line summary back to the user before writing files, so they can reject a bad derivation early.

---

## Phase 2 — Copy templates + parameterize

Copy each template from this skill's `templates/` directory into the target theme, applying the slug substitutions above at copy time.

| Source (in this skill) | Destination (in target theme) | Substitutions |
|---|---|---|
| `templates/ContactHandler.php` | `theme/app/Http/ContactHandler.php` | `{slug}` → project slug; `{domain}` → public domain |
| `templates/validation.json` | `theme/resources/data/validation.json` | no substitutions |
| `templates/contact-modal.blade.php` | `theme/resources/views/partials/contact-modal.blade.php` | `{SLUG}` → uppercase slug |
| `templates/contact-modal.js` | `theme/resources/js/contact-modal.js` | `{slug}` → lowercase; `{SLUG}` → uppercase; `{Slug}` → PascalCase |
| `templates/contact.test.js` | `theme/tests/contact.test.js` | no substitutions |
| `templates/contact-validation.test.php` | `theme/tests/contact-validation.test.php` | no substitutions |

Verify every substitution landed with a single `grep -r '{slug}\|{SLUG}\|{Slug}\|{domain}' theme/` afterwards. If anything matches, find + replace manually — those tokens must not survive into committed code.

---

## Phase 3 — Register REST route in setup.php

Open `theme/app/setup.php`. If the file already imports `use Illuminate\Support\Facades\Vite;` or similar, it's a Sage setup file — append the contents of `templates/setup-rest-route.php` to the end (after the existing `add_action`/`add_filter` calls, before any closing brace).

If there's no `setup.php`, create one and make sure it's required from the theme's `functions.php` (Sage 10/11 does this via `collect(['setup', 'filters'])->each(…)`).

Apply the same `{slug}` / `{SLUG}` / `{Slug}` substitutions as Phase 2.

**Verify** by restarting the LocalWP site and visiting `https://<site>/wp-json/{slug}/v1/` — you should see a JSON response listing the `/contact` route with `methods: ["POST"]`. If it 404s, the route didn't register (check `setup.php` is actually required from `functions.php`).

---

## Phase 4 — Include the Blade partial + trigger

In the Blade view where the contact form should be reachable (usually `layouts/app.blade.php`, `front-page.blade.php`, or a dedicated `/contact` page template), add:

```blade
@include('partials.contact-modal')
```

Add at least one **trigger** — any element with `data-contact-trigger` opens the modal. Example for a header link:

```blade
<a href="#contact" data-contact-trigger class="…">Write</a>
```

Multiple triggers can exist on the same page; all open the same modal.

---

## Phase 5 — Import the JS into the app entry

In the theme's main JS entry (usually `theme/resources/js/app.js`):

```js
import { initContactModal } from './contact-modal.js';

document.addEventListener('DOMContentLoaded', () => {
  initContactModal();
});
```

If the entry already has a DOMContentLoaded handler, add the `initContactModal()` call there.

**Also required:** the `contact-modal.js` references `/wp-content/themes/{slug}/resources/data/validation.json` — verify the path matches your theme's actual slug (WP theme folder name). If it differs, edit the URL in `loadValidation()` before testing.

---

## Phase 6 — Env vars

Append the contents of `templates/.env.example` to the theme's existing `.env.example` (create one if it doesn't exist). Then tell the user to copy to `.env` and fill in:

- `RESEND_API_KEY` — required. Without it the endpoint returns `{ok:false, error:"not_configured"}`.
- `RESEND_FROM` — for initial testing use `onboarding@resend.dev` (Resend's built-in sender, capped at 100 emails/day).
- `CONTACT_TO` — inbox that receives messages.
- `TURNSTILE_SITE_KEY` + `TURNSTILE_SECRET_KEY` — optional. Empty = Turnstile check skipped silently (dev mode).

Confirm `.env` is gitignored. Almost every Sage `.gitignore` already excludes `.env` + `.env.local`; verify before proceeding.

---

## Phase 7 — Run tests

```bash
node theme/tests/contact.test.js
php theme/tests/contact-validation.test.php
```

Both should print `passed: N  failed: 0`. If either fails, the most common cause is a path mismatch — the tests look for `validation.json` and `contact-modal.blade.php` relative to `theme/tests/`. Adjust the `resolve()` / `__DIR__` paths if your layout differs.

---

## Critical gotchas — embed these in the project's CLAUDE.md

Landmines from the reference implementation. Teach future sessions to avoid them.

1. **Custom `X-{Slug}-Nonce` header is load-bearing.** Do NOT "simplify" to `X-WP-Nonce`. WP core's `rest_cookie_check_errors` intercepts the standard header before our `permission_callback` runs — a valid nonce still gets rejected because WP also expects a cookie session. Custom header name sidesteps that path entirely.

2. **Turnstile widget mode: Managed + `appearance: 'interaction-only'`.** Invisible mode (`size: 'invisible'` or dashboard "Invisible") returns HTTP 400 from `challenges.cloudflare.com`. Verified against 3+ combinations during the reference build — only Managed + interaction-only ships.

3. **Honeypot field name: `website`.** Not `_gotcha`, not `honeypot`, not `email_confirm`. Bots specifically scan for well-known honeypot names and skip them. A plausible-looking name (`website`, `url`, `company_url`) catches >50% of basic scrapers.

4. **Silent bot rejection = fake `{ok: true}` / HTTP 200.** Never return 400/403 on honeypot, timing, interaction-count, or gibberish failures — that signal tells the bot which layer tripped and lets it train. Only return non-200 for user-fixable errors (invalid email, message too long, rate limit, CAPTCHA failure).

5. **`validation.json` is the single source of truth.** If you change a rule in PHP without updating JS (or vice versa), `contact.test.js` + `contact-validation.test.php` both fail — that's the point. Never hand-sync; always edit `validation.json`.

6. **Privacy Pass 401s in dev tools.** Requests to `challenges.cloudflare.com/cdn-cgi/` returning 401 are **expected** — they're the Turnstile/RFC 9578 Privacy Pass handshake, not a bug. Only worry about 400s.

7. **Gibberish heuristic false-positives short names.** The detector skips strings under `gibberishSkipLengthBelow` chars (default 8). If a real user sends just "Hi." it's below threshold and not evaluated. International surnames like Schmidt, Schwarzkopf, O'Brien pass because the vowel ratio is generous (0.15) and consonant-run cap is 6.

8. **Rate limit resets on serverless cold starts.** The endpoint uses WP transients (persistent), so this doesn't affect a traditional WP host. But if you deploy behind a serverless layer (Vercel Functions, Lambda), WP transients may not persist reliably; pair with a WAF-level rate limit if so.

---

## Verification checklist

Before declaring the install done:

- [ ] `curl -I https://<site>/wp-json/{slug}/v1/` returns 200 with the `/contact` route listed
- [ ] Submitting the form as a real user (slow, multiple mousemoves, real email, >10 char message) delivers a Resend email to `CONTACT_TO`
- [ ] Submitting with the `website` honeypot populated returns 200 with `{ok:true}` but no email arrives
- [ ] Submitting 4× in 60s from the same IP returns `{ok:false, error:"rate_limited"}` on the 4th
- [ ] Submitting within 1s of page render returns 200 with `{ok:true}` but no email (timing reject)
- [ ] Submitting with 0 interactions returns 200 with `{ok:true}` but no email (interaction reject)
- [ ] Submitting "asdfghjkl qwertyui zxcvbnm" returns 200 with `{ok:true}` but no email (gibberish reject)
- [ ] `node theme/tests/contact.test.js` and `php theme/tests/contact-validation.test.php` both green
- [ ] Lighthouse accessibility score on the modal stays at 100 (focus trap works, aria-live errors announced)

---

## Reference implementation

Extracted from a production WordPress theme where it has been running without incident since initial deploy. Patterns are battle-tested, not speculative. If you want to see one of these in the wild, ask the user — they can share a live URL.
