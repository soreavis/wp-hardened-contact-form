# hardened-contact-form

**A Claude Code skill that installs a production-hardened contact form into a WordPress + Sage / Acorn theme.**

![License](https://img.shields.io/badge/license-MIT-blue)
![Version](https://img.shields.io/badge/version-0.1.0-blue)
![PHP](https://img.shields.io/badge/PHP-8.2%2B-777bb4?logo=php&logoColor=white)
![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b?logo=wordpress&logoColor=white)

---

Most "contact form" tutorials stop at the `<form>` tag. Spam starts arriving five minutes after you deploy. You bolt on reCAPTCHA v3 out of guilt, forget to update it, and six months later you're forwarding spam from `qwertyuiop@mail.ru` to the recycle bin.

This skill ships the opposite: a **9-layer defense stack** wired into a clean Sage theme — no visible CAPTCHA by default, one shared validation config that both JS and PHP read (drift impossible), and zero-framework tests that catch the first rule drift on CI. Extracted from a production WordPress site where it has blocked >99% of form spam with zero false-positive reports over its first month.

---

## What ships

- **9-layer server-side defense** — CSRF nonce (custom header avoids WP core collision), honeypot, timing check, interaction count, gibberish heuristic, rate limit, field validation, Cloudflare Turnstile (optional), Resend delivery. Silent rejection returns fake 200s so bots can't probe layers.
- **Single source of truth for validation** — `resources/data/validation.json` is read by both the PHP endpoint and the JS client; client/server can never drift.
- **Accessible glass-lightbox modal** — `role="dialog"`, focus trap, ESC + backdrop close, per-field `role="alert" aria-live="polite"` error slots, GDPR consent line. Passes Lighthouse a11y at 100.
- **Zero-framework tests** — `node theme/tests/contact.test.js` + `php theme/tests/contact-validation.test.php` catch rule drift. No PHPUnit, no Vitest, no install step beyond Node + PHP themselves.
- **Dev-mode-friendly** — Turnstile + Resend both gracefully no-op when env vars are absent, so local dev doesn't require you to sign up for anything.

## Install as a Claude Code skill

This skill is designed to be invoked by [Claude Code](https://claude.com/claude-code) against a target WordPress project. Installation:

```bash
# Clone into the project's .claude/skills/ folder (per-project install)
cd your-wordpress-project
mkdir -p .claude/skills
git clone https://github.com/soreavis/hardened-contact-form.git .claude/skills/hardened-contact-form

# Or install globally for all projects:
git clone https://github.com/soreavis/hardened-contact-form.git ~/.claude/skills/hardened-contact-form
```

Restart Claude Code so it picks up the new skill. Then in your session:

> "Install the hardened contact form into this theme."

Claude will invoke the skill, ask for your project slug / domain / inbox / Resend + Turnstile keys, copy and parameterize the templates, register the REST route, include the Blade partial, and run the tests. Full install flow is documented in [`SKILL.md`](./SKILL.md).

## Install manually (no Claude Code)

If you'd rather copy-paste, the authoritative template files live in [`./templates/`](./templates). Copy each one into its destination in your theme and replace the four placeholders:

| Token | Replace with | Example |
|---|---|---|
| `{slug}` | lowercase kebab-case slug | `mybrand` |
| `{SLUG}` | UPPERCASE slug | `MYBRAND` |
| `{Slug}` | PascalCase slug | `Mybrand` |
| `{domain}` | public domain | `mybrand.com` |

Destinations (standard Sage layout):

```
theme/
├── app/Http/ContactHandler.php              ← templates/ContactHandler.php
├── app/setup.php                            ← append templates/setup-rest-route.php
├── resources/
│   ├── data/validation.json                 ← templates/validation.json
│   ├── js/contact-modal.js                  ← templates/contact-modal.js
│   └── views/partials/contact-modal.blade.php ← templates/contact-modal.blade.php
├── tests/
│   ├── contact.test.js                      ← templates/contact.test.js
│   └── contact-validation.test.php          ← templates/contact-validation.test.php
└── .env.example                             ← append templates/.env.example
```

Then in your Blade layout, include the partial and add a trigger:

```blade
@include('partials.contact-modal')

<a href="#contact" data-contact-trigger>Write</a>
```

In your JS entry (usually `resources/js/app.js`):

```js
import { initContactModal } from './contact-modal.js';
document.addEventListener('DOMContentLoaded', () => { initContactModal(); });
```

Then fill in `.env` and run the tests. Done.

## Configuration — `validation.json`

All tunables live in [`templates/validation.json`](./templates/validation.json):

| Key | Default | Meaning |
|---|---|---|
| `email.maxLength` | `120` | Server-side email length cap |
| `email.pattern` | RFC-ish regex | Email format regex, shared JS + PHP |
| `message.minLength` | `10` | Minimum message length |
| `message.maxLength` | `5000` | Maximum message length |
| `spam._minElapsedSeconds` | `2` | Submitting faster than this looks like a script |
| `spam._minInteractions` | `3` | Required mousemove/keydown/touch count before submit |
| `spam.rateLimitPerMinute` | `3` | Max submissions per IP per window |
| `spam.rateLimitWindowSeconds` | `60` | Rate-limit window size |
| `spam.gibberishVowelMinRatio` | `0.15` | Messages below this vowel:letter ratio get flagged |
| `spam.gibberishMaxConsonantRun` | `6` | Max consecutive consonants before flagging keyboard-mashing |
| `spam.gibberishSkipLengthBelow` | `8` | Skip gibberish check on short strings (protects "Hi.", "OK.", initials) |

Edit once, both client and server pick up the change.

## Requirements

- **PHP 8.2+** (typed properties, arrow functions, null coalescing)
- **WordPress 6.0+** (REST API, transients, `is_email`, `sanitize_email`, `sanitize_textarea_field`)
- **Sage 10 or 11** layout (or any WP theme that can load Blade + register REST routes + read `env()`)
- **Resend account** — https://resend.com (free tier: 100 emails/day)
- **Optional: Cloudflare Turnstile widget** — https://dash.cloudflare.com/?to=/:account/turnstile (free)

## Testing

Both test suites run without a framework:

```bash
node theme/tests/contact.test.js
# → ~12 assertions: config shape, email regex, message length, honeypot naming

php theme/tests/contact-validation.test.php
# → ~15 assertions: config sanity, shared email regex, gibberish detector positive + negative cases
```

If either fails after a rule change, it means the PHP handler and JS client have drifted from `validation.json`.

## Gotchas worth knowing

Full list in [`SKILL.md`](./SKILL.md). The three that bite hardest:

1. **Custom `X-{Slug}-Nonce` header, not `X-WP-Nonce`.** WP core's `rest_cookie_check_errors` intercepts the standard header before your `permission_callback` runs. Custom name sidesteps this.
2. **Turnstile widget must be Managed mode + `appearance: 'interaction-only'`.** Invisible mode returns HTTP 400 from `challenges.cloudflare.com`.
3. **Bot failures return fake 200 `{ok: true}`, never 400/403.** Anything else tells the bot which layer tripped.

## Contributing

Issues and PRs welcome. If you ship this on a real site and find a gap, open an issue with the layer that failed and the payload shape — that's the single most useful signal for improving the defaults.

## License

[MIT](./LICENSE) — copy it, ship it, improve it.

## Acknowledgments

Extracted from a live Sage 11 / Tailwind 4 / Vite 8 site where the 9-layer stack runs in production.

Built with [Claude Code](https://claude.com/claude-code).
