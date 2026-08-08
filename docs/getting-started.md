# Getting started

## Before you run it

The skill assumes a WordPress theme it can write Blade into. Specifically: WordPress 5.0+, PHP 7.4+ for the handler's own syntax, and a Sage 10 / Sage 11 layout or another theme that loads Blade and can register REST routes. Sage 11.2 itself requires PHP 8.3+, so on current Sage that is the real floor.

One requirement is easy to miss: the handler and the route file both read env vars through Acorn's `env()` helper, guarded by `function_exists('env')`. In a theme without that helper every key reads as empty, which means Turnstile is skipped and mail never sends. If you are not on Acorn, plan to replace those four `env()` calls with whatever your theme uses.

You also need a [Resend](https://resend.com) account for delivery. Cloudflare Turnstile is optional and off unless you supply keys.

## Running the skill

Install it per the [main README](../README.md#install-as-a-claude-code-skill), restart Claude Code, then ask for it in a session pointed at the theme:

> Install the hardened contact form into this theme.

It asks six things: project slug, display name, public domain, contact inbox, Resend from-address, and whether to wire up Turnstile. From the slug it derives three casings — `mybrand`, `MYBRAND`, `Mybrand` — which become the REST namespace, the `window.MYBRAND` config global, and the `X-Mybrand-Nonce` header. It reports the derivation back before writing anything, which is your chance to reject a bad guess.

Then it copies seven files into the theme, appends the REST route to `setup.php`, appends the Resend and Turnstile keys to `.env.example`, and runs both test suites.

## Doing it by hand

Copy the files from [`templates/`](../templates) to the destinations in the main README and replace four tokens: `{slug}`, `{SLUG}`, `{Slug}`, `{domain}`. Then confirm none survived:

```bash
grep -rn '{slug}\|{SLUG}\|{Slug}\|{domain}' path/to/theme/
```

A leftover token is silent. `register_rest_route('{slug}/v1', ...)` registers a namespace literally called `{slug}`, and the form posts somewhere else entirely.

## What you finish yourself

The skill does not do these, and the form is not usable until they are done.

**Include the partial and add a trigger.** Any element carrying `data-contact-trigger` opens the modal; several on a page all open the same one.

```blade
@include('partials.contact-modal')

<a href="#contact" data-contact-trigger>Write</a>
```

**Call the initialiser** from the theme's JS entry, after DOM ready:

```js
import { initContactModal } from './contact-modal.js';
document.addEventListener('DOMContentLoaded', () => { initContactModal(); });
```

**Check the config path.** `contact-modal.js` fetches `/wp-content/themes/{slug}/resources/data/validation.json` — the theme *folder* name, which is not always the slug you gave the skill. If it differs, edit the URL in `loadValidation()`. A wrong path fails silently: the client falls back to its own built-in rules and quietly stops sharing them with the server.

**Fill in `.env`.** `RESEND_API_KEY` is the only one with no fallback. Confirm `.env` is gitignored before you put a key in it.

**Supply the styling.** The Blade partial is Tailwind markup and expects two project-level tokens: a `gold` colour (`bg-gold`, `text-gold`, `focus:border-gold`) and a `font-display` family. Undefined, those classes do nothing and the modal renders plain. The consent line also points at `data-legal-trigger="privacy-policy"` with `href="#"`, which assumes you have a legal modal of your own; without one, wire the href to a real page.

## Checking it worked

Restart the site and load `/wp-json/{slug}/v1/`. You should see the `/contact` route with `methods: ["POST"]`. A 404 means the route never registered — usually `setup.php` is not required from `functions.php`.

Then run both suites from the theme:

```bash
node tests/contact.test.js
php tests/contact-validation.test.php
```

Both print a `passed: N  failed: 0` line and exit non-zero on failure.

Finally, submit the form as a human would — open it, type slowly, real address, more than ten characters — and confirm the mail lands in `CONTACT_TO`. The rejection paths are worth exercising too; the list is in [How it works](how-it-works.md#seeing-the-layers-fire).
