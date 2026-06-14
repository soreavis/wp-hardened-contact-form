# Contributing

Contributions are welcome — bug reports, new defense layers, and improvements to the templates.

## How it's laid out

The authoritative source is [`./templates/`](./templates):

| File | Role |
|------|------|
| `contact-modal.blade.php` | Markup + Turnstile mount |
| `contact-modal.js` | Front-end submit + client-side validation |
| `setup-rest-route.php` | Registers the REST endpoint |
| `ContactHandler.php` | Server-side validation, spam layers, Resend send |
| `validation.json` | **Single source of truth** — both JS and PHP read these rules |
| `contact-validation.test.php`, `contact.test.js` | Drift tests: JS and PHP must agree with `validation.json` |
| `.env.example` | Required env vars (placeholder-only — never commit a real `.env`) |

## The one rule that matters

**JS and PHP must validate identically.** Both read `validation.json`. If you change a rule (length, pattern, a spam check), change it in `validation.json` — don't hard-code it in one side. The test files exist to catch exactly this drift.

## Before opening a PR

```bash
# PHP syntax
for f in templates/*.php; do php -l "$f"; done

# JSON config parses
python3 -c "import json; json.load(open('templates/validation.json'))"
```

CI runs the same checks on every push and PR.

## Submitting

1. Branch (`feat/<layer>` or `fix/<thing>`)
2. Keep `validation.json` the source of truth for any rule change
3. Open a PR describing the spam/attack pattern it addresses and the payload shape, if relevant — that's the most useful signal for improving the defaults
