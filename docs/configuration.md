# Configuration

Two files decide how the form behaves: `validation.json` for the rules, `.env` for the credentials. Nothing else needs editing to tune it.

## validation.json

Both runtimes read this file at request time — PHP through `get_theme_file_path()`, the client through a `fetch`. Change a number here and both sides pick it up. Defaults and meanings are tabled in the [main README](../README.md#configuration--validationjson); what follows is what actually happens when you move them.

**Email.** `pattern` is the shared regex, and it is the one place the two runtimes must agree exactly — the PHP suite and the JS suite each compile it independently and assert the same accept/reject set. `maxLength` is checked in bytes on the server (`strlen`) and in UTF-16 code units in the browser (`.length`), which only matters for non-ASCII input. Note also that the server runs `is_email()` as well as the regex, so WordPress can reject an address the pattern allowed.

**Message length.** `minLength` at 10 exists to stop one-word submissions; `maxLength` at 5000 is the ceiling that returns `message_too_long`. The same byte-versus-code-unit split applies, and it bites here rather than on email: a 5000-character message in Czech or Japanese is well over 5000 bytes, so the client accepts what the server then rejects with a `422`. Lower `maxLength` well under any limit you actually care about and the gap stops mattering.

**Timing and interaction.** `_minElapsedSeconds` is how long a form must be open before a submission is believable, and `_minInteractions` is how much mouse or keyboard activity must have happened. Both are cheap to pass for a human and awkward for a script. Raising `_minInteractions` much past a handful starts catching people who tab straight through a short form and submit with the keyboard.

**Rate limit.** `rateLimitPerMinute` requests per `rateLimitWindowSeconds`, keyed on IP, stored in a WordPress transient. Shared-office and carrier-NAT addresses arrive as one IP, so treat 3-per-minute as the floor rather than a target if you expect bursts from one building.

**Gibberish.** `gibberishSkipLengthBelow` is a guard, not a threshold — shorter strings are not judged at all. `gibberishVowelMinRatio` and `gibberishMaxConsonantRun` are deliberately loose so that real surnames survive; there are tests pinning that. Every point you tighten them buys a few more caught bots and risks a real message disappearing without a trace, which is the failure you never find out about.

The tests read this file too, so a malformed edit fails both suites rather than reaching production.

## Environment variables

Appended to the theme's `.env.example` at install. All six are read through Acorn's `env()` helper behind a `function_exists('env')` guard — in a theme without that helper they all read as empty, which silently means no Turnstile and no mail.

| Variable | Absent means |
|---|---|
| `RESEND_API_KEY` | `500 not_configured`, logged. The only variable with no fallback. |
| `RESEND_FROM` | Falls back to `onboarding@resend.dev`. |
| `CONTACT_TO` | Falls back to the WordPress `admin_email` option. |
| `TURNSTILE_SITE_KEY` | No script enqueued, no widget rendered, and server-side verification is skipped. |
| `TURNSTILE_SECRET_KEY` | Server-side verification skipped, and returns as if it passed. |

`onboarding@resend.dev` is Resend's testing sender. Their docs mark it testing-only; production needs an address on a domain you have verified there, with the DKIM and SPF records in place. Sending from an unverified domain is rejected at Resend, which arrives here as `502 upstream` with the status in the log.

## Turnstile

Off by default. Both keys set means the widget renders and the server verifies. Neither set means the whole layer is skipped, which is what makes local development work without signing up for anything.

Setting exactly one disables the layer rather than half-enabling it. The server requires both keys before it enforces anything, because a secret without a site key means no widget renders, no token is ever produced, and enforcing would reject every submission — one missing variable taking the whole form down. When it detects that state it logs `turnstile half-configured` on each submission and lets the request through to the other eight layers. If you set up Turnstile and the log says that, you have a typo in one of the two names.

Two settings are not optional. The dashboard widget must be created in **Managed** mode, and the client passes `appearance: 'interaction-only'` so it stays invisible unless a challenge is genuinely needed. Invisible dashboard mode returned HTTP 400 from `challenges.cloudflare.com` during the reference build, and the client-side `size` parameter does not accept `'invisible'` either — it takes `normal`, `flexible` or `compact`. Leave `size` unset.

One more thing about the client: it does not wait for the token. The `await getTurnstileToken()` block in `contact-modal.js` is written but commented out, so the POST carries whatever the hidden input holds at submit time. In practice the widget has issued a token long before anyone finishes typing. If you would rather fail fast in the browser than let the server return a `403`, uncomment those three lines.

## The config path

`contact-modal.js` fetches `validation.json` from a hardcoded `/wp-content/themes/{slug}/resources/data/validation.json`. That is the theme *directory* name, which is not necessarily the slug the skill derived.

Get it wrong and nothing complains. The `fetch` throws, the `catch` installs a small built-in fallback — a looser email pattern, min 10, max 5000 — and the form carries on working. The single source of truth is now only feeding one of the two sides, and the first symptom is a client that accepts an address the server rejects. If you rename the theme folder, this line moves with it.
