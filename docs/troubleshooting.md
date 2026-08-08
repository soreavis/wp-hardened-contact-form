# Troubleshooting

## The route 404s

Load `/wp-json/{slug}/v1/`. If the `/contact` route is not listed, `rest_api_init` never ran the registration — usually because `setup.php` is not required from `functions.php`. Sage does that through the `collect(['setup', 'filters'])` loop; if you created the file yourself, it may not be in the list.

If the route exists but under a namespace literally called `{slug}`, a placeholder survived the install. Grep the theme for `{slug}`, `{SLUG}`, `{Slug}` and `{domain}` and fix all four.

## Every submission is rejected before the handler runs

That is the nonce. Three causes, in order of likelihood.

The header name has to match on both sides: the client sends `X-{Slug}-Nonce` in PascalCase, the server reads `x_{slug}_nonce`. If the slug casings were substituted inconsistently, they will never meet.

The nonce comes from `window.{SLUG}` printed into `<head>`. If the config blob is missing from the page source, the `wp_head` hook is not firing — check the theme actually calls `wp_head()`.

Or it expired. WordPress nonces are time-limited, so a tab left open long enough submits a stale one. A reload fixes it, and there is nothing to configure.

## A message vanishes with no error

Working as designed, and the browser cannot tell you which layer caught it — the honeypot, timing, interaction-count and gibberish checks all answer exactly like a success. The PHP error log is the only place the difference is recorded:

```bash
grep '\[{slug} contact\]' path/to/debug.log
```

You want `event=honeypot_hit`, `timing_too_fast`, `low_interactions` or `gibberish`. If the log says `delivered`, the form did its job and the problem is downstream at Resend.

## Real messages vanish, and the log says `timing_too_fast`

`_ts` is rendered by the server and compared against the server's own clock, so a visitor's machine being fast or slow cannot cause this. Two things still can.

A genuine sub-`_minElapsedSeconds` submission — someone with the form prefilled by a password manager who submits immediately. Two seconds is a low bar, but if your form is short, raising it is the wrong direction.

Or the host's clock stepped backwards, usually an NTP correction, which makes `time()` earlier than a timestamp it issued moments ago. If the log fills with `timing_too_fast` from unrelated IPs at once, check the clock on the host before touching the config.

## The client accepts an address the server rejects

The two are no longer reading the same rules. `contact-modal.js` fetches `validation.json` from `/wp-content/themes/{slug}/resources/data/validation.json` using the theme *folder* name — if that path 404s, the `catch` silently installs a looser built-in fallback and the form keeps working with client and server disagreeing.

Open the network tab, submit, and check the `validation.json` request. A 404 there is the whole answer.

## Long messages in other languages get `message_too_long`

The server measures with `strlen()`, which counts bytes; the browser measures with `.length`, which counts UTF-16 code units. Accented and CJK characters take more than one byte, so a message the browser counts as under the cap can be over it on the server. Lower `message.maxLength` if it matters, or accept that the effective ceiling for non-Latin text is lower than the number in the config.

## Every submission returns `403 captcha_failed`

Both keys are set and something is wrong with the token itself. The likeliest cause is a submission that beat the widget to one, since the client does not wait by default — see [Configuration](configuration.md#turnstile). After that, check the widget is in Managed mode and that the secret belongs to the same widget as the site key.

One key without the other does not produce this. The server treats a half-configured pair as unconfigured and logs `turnstile half-configured` instead, so if the log says that, the layer is off and this is not your problem.

## Turnstile returns HTTP 400

The widget is in the wrong mode. It has to be created as **Managed** in the Cloudflare dashboard, with `appearance: 'interaction-only'` client-side and no `size` parameter. Invisible dashboard mode and `size: 'invisible'` both produce 400s from `challenges.cloudflare.com`.

401s from `challenges.cloudflare.com/cdn-cgi/` are a different thing entirely and are expected — that is the Privacy Pass handshake, not an error. Only 400s are worth chasing.

## `500 not_configured`

`RESEND_API_KEY` is empty. Either it is genuinely missing from `.env`, or the theme has no `env()` helper — every key is read behind `function_exists('env')`, so on a non-Acorn theme they all resolve to empty and mail can never send. Replace those calls with your theme's own config accessor.

## `502 upstream`

Resend answered with a non-2xx, and the status is in the log line `Resend returned HTTP <status>`. The usual cause is a `from` address on a domain that is not verified in the Resend dashboard. `onboarding@resend.dev` works without verification but is their testing sender, not a production one.

`502 network` is different — that is `wp_remote_post` failing to reach Resend at all, which is a firewall or DNS problem on the host.

## The rate limit does not hold

It is stored in a WordPress transient, which is persistent on an ordinary host. Behind a serverless layer, transients may not survive between invocations, and the counter resets. Enforce the limit at the WAF instead.

Two other things that look like a broken limiter but are not. Requests caught by an earlier layer never reach the counter, so a bot tripping the honeypot is never throttled. And the key is the IP, so an office or a mobile carrier behind one NAT shares a single budget.

## The modal opens unstyled

The Blade partial is Tailwind markup that expects two project-level tokens: a `gold` colour and a `font-display` family. If the theme defines neither, the classes referencing them do nothing. The layout still works; it renders plain.

If it does not open at all, check that `initContactModal()` is called from the JS entry and that at least one element on the page carries `data-contact-trigger` — the initialiser returns immediately if it finds no modal or no trigger.
