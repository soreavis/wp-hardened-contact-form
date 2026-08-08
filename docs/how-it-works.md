# How it works

## The request path

**On page render**, `setup.php` prints a small config blob into `<head>` at priority 5: a fresh nonce for the `{slug}_contact` action, the REST URL, and the Turnstile site key if one is set. It is namespaced on `window.{SLUG}` so it does not collide with other globals. The Turnstile script is enqueued separately, and only when a site key exists.

**On modal open**, the client resets the form, stamps the `_ts` hidden field with the browser's clock, zeroes the interaction counter, renders the Turnstile widget if configured, and fetches `validation.json`.

**While the visitor types**, `mousemove`, `keydown`, `touchstart` and `pointermove` on the form each increment `_interactions`.

**On submit**, the client validates against the rules it fetched, then POSTs JSON to the REST URL with the nonce in an `X-{Slug}-Nonce` header.

That header name is deliberate. WordPress core intercepts `X-WP-Nonce` on the `rest_authentication_errors` filter and validates it against a cookie-authenticated session — so a logged-in editor submitting the form gets rejected by a check that has nothing to do with this endpoint. A custom name keeps the endpoint's own `permission_callback` as the only gatekeeper, for anonymous and authenticated visitors alike.

## The layers, in the order they run

The header comment in `ContactHandler.php` numbers them 1–9 as a stack. The handler runs them in a slightly different order: field validation comes before the gibberish and rate-limit checks, because there is no point heuristically judging a message that is already too long.

| Check | Trips when | Answer |
|---|---|---|
| Nonce | header missing, wrong, or expired | handler never runs; WordPress returns a REST error |
| Honeypot | the hidden `website` field is non-empty | `200 {ok:true}` |
| Timing | `_ts` is present and less than `_minElapsedSeconds` has passed | `200 {ok:true}` |
| Interactions | `_interactions` is below `_minInteractions` | `200 {ok:true}` |
| Field validation | email fails `is_email` or the length cap; message under min or over max | `422` with `invalid_email`, `message_too_short`, `message_too_long` |
| Gibberish | vowel ratio below the floor, or a consonant run at the cap | `200 {ok:true}` |
| Rate limit | this IP already has `rateLimitPerMinute` hits in the window | `429 rate_limited` |
| Turnstile | secret is configured and the token is missing or rejected | `403 captcha_failed` |
| Resend | key missing / transport error / non-2xx from Resend | `500 not_configured`, `502 network`, `502 upstream` |

Everything else gets `200 {ok:true}` and an email.

## Why bots get a fake 200

The four bot-shaped checks — honeypot, timing, interaction count, gibberish — answer exactly like a successful send. Nothing in the response says which one tripped, or that anything tripped at all.

A `403` tells a bot it found a wall, and a bot that knows which wall it hit can go around it. So the loud codes are reserved for things a human can act on: a malformed address, a message that is too long, too many attempts, a failed CAPTCHA. If you add a layer, keep it on the quiet side of that line.

Two consequences worth knowing. The rate limiter only counts requests that reach it, so anything caught by an earlier layer never consumes budget — a bot tripping the honeypot can post as often as it likes and each attempt costs a `200`. If volume is the problem rather than delivery, that belongs at the WAF, not here. And the timing check is skipped entirely when `_ts` is absent, since `$ts > 0` guards it; a client that omits the field is not evaluated.

## The honeypot name

The hidden field is called `website`. Not `_gotcha`, not `honeypot`, not `email_confirm`. Spam libraries carry heuristics for the well-known names and skip them, so a field that looks like a real one it should fill is worth more than a field that announces itself. A test asserts the name, which is why it will fail if someone renames it to something more descriptive.

## The gibberish heuristic

Two rules on the message body, and one guard. Strings shorter than `gibberishSkipLengthBelow` are not judged at all, which is what stops "Hi." being read as a bot. Above that, letters are extracted and the vowel-to-letter ratio is compared against `gibberishVowelMinRatio`; then a run of `gibberishMaxConsonantRun` or more consonants flags a keyboard mash.

The defaults are loose on purpose — a 0.15 ratio and a six-consonant cap leave room for names like Schmidt, Schwarzkopf and O'Brien, and there are tests holding that line. Tighten them and you start losing real messages, which is a worse failure than passing a bot, because you never hear about it.

## The audit log

Every rejection that reaches the handler writes one line to the PHP error log, tagged `[{slug} contact]`, carrying the event name and the IP — and never the message body.

```
[mybrand contact] event=honeypot_hit ip=203.0.113.7
[mybrand contact] event=timing_too_fast ip=203.0.113.7 elapsed=0
[mybrand contact] event=low_interactions ip=203.0.113.7 count=0
[mybrand contact] event=gibberish ip=203.0.113.7 msg_len=26
[mybrand contact] event=rate_limited ip=203.0.113.7 count=3
[mybrand contact] event=turnstile_failed ip=203.0.113.7
[mybrand contact] event=delivered ip=203.0.113.7
```

Field validation is the exception: a `422` is not audited, on the reasoning that a typo'd address is not an attack. So if you are counting rejections in the log, the numbers exclude those.

## The mail

Delivery is a POST to Resend's API with both a text and an HTML body. `reply_to` is set to whatever address the visitor typed, so replying in your client goes back to them.

Both bodies carry the same warning, and it is there for a reason: that address is unverified. Anyone can type anything into a contact form, and an inbox that shows a name and an address invites you to trust it. Confirm through another channel before acting on anything sensitive. The footer carries the submitting IP and user agent.

## Seeing the layers fire

Each of these should behave exactly as described, and all four silent ones look identical from the browser:

- Fill the `website` field → `200`, no mail.
- Submit within a second of opening → `200`, no mail.
- Submit with no mouse or keyboard activity → `200`, no mail.
- Send `asdfghjkl qwertyui zxcvbnm` → `200`, no mail.
- Submit four times in a minute → `429 rate_limited` on the fourth.
- Send a valid message → mail arrives, log says `delivered`.

The error log is the only place the difference shows up. That is the design working, and it is also why the log is worth keeping an eye on for the first week after launch.
