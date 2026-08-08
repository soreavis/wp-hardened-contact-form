<?php

namespace App\Http;

use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /wp-json/{slug}/v1/contact
 *
 * Hardened public contact form endpoint. Layered defences (in order):
 *   1. CSRF      — WP nonce (`X-{Slug}-Nonce` header) checked in permission_callback.
 *   2. Honeypot  — hidden `website` field; if filled, fake-200 silently.
 *   3. Timing    — server timestamp min elapsed = 2s; too-fast → fake-200.
 *   4. Interaction count — client reports mousemove/keydown count; low count → fake-200.
 *   5. Gibberish — vowel-ratio + consonant-run heuristic on message; bad → fake-200.
 *   6. Rate limit — 3 per 60s per IP via WP transient.
 *   7. Field validation — email + message length (shared config).
 *   8. Turnstile — Cloudflare CAPTCHA token verified server-side (when configured).
 *   9. Send      — Resend REST API.
 *
 * All silent rejections return `{ok: true}` / 200 so bots can't probe layers.
 * Every rejection is audit-logged with IP + reason (no message body).
 *
 * INSTALL-TIME PARAMETERISATION:
 *   Replace `{slug}` with a lowercase kebab-case slug (e.g. `mybrand`).
 *   Replace `{domain}` with the public domain (e.g. `mybrand.com`).
 */
class ContactHandler
{
    /** Load shared validation config (also used by the JS client). */
    private static function config(): array
    {
        $path = get_theme_file_path('resources/data/validation.json');
        return json_decode(file_get_contents($path), true) ?? [];
    }

    /** Short-form audit logger. Metadata only — never include message content. */
    private static function audit(string $event, string $ip, array $extra = []): void
    {
        $parts = ['event=' . $event, 'ip=' . $ip];
        foreach ($extra as $k => $v) {
            $parts[] = $k . '=' . (is_scalar($v) ? (string) $v : wp_json_encode($v));
        }
        error_log('[{slug} contact] ' . implode(' ', $parts));
    }

    /** True when the content looks like bot gibberish ("asdfkjhqwer"-ish). */
    private static function isGibberish(string $text, array $cfg): bool
    {
        $len = strlen($text);
        if ($len < (int) ($cfg['gibberishSkipLengthBelow'] ?? 8)) {
            return false; // too short to judge reliably (e.g., initials, short names)
        }
        $letters = preg_replace('/[^A-Za-z]/', '', $text);
        $letterLen = strlen($letters);
        if ($letterLen < 4) {
            return false;
        }
        $vowelCount = preg_match_all('/[aeiouAEIOU]/', $letters);
        $ratio = $vowelCount / $letterLen;
        if ($ratio < ($cfg['gibberishVowelMinRatio'] ?? 0.15)) {
            return true;
        }
        // Disallow runs of N+ consonants in a row — flags keyboard-mashing.
        $maxRun = (int) ($cfg['gibberishMaxConsonantRun'] ?? 6);
        if (preg_match('/[bcdfghjklmnpqrstvwxyzBCDFGHJKLMNPQRSTVWXYZ]{' . $maxRun . ',}/', $letters)) {
            return true;
        }
        return false;
    }

    /** Verify Cloudflare Turnstile token server-side. Returns true if valid
     *  OR if Turnstile isn't configured (dev mode). */
    private static function verifyTurnstile(string $token, string $ip): bool
    {
        $secret = function_exists('env') ? (string) env('TURNSTILE_SECRET_KEY') : '';
        $siteKey = function_exists('env') ? (string) env('TURNSTILE_SITE_KEY') : '';
        if ($secret === '' || $siteKey === '') {
            // Half-configured is not configured. Without a site key no widget
            // renders, so no token can ever exist and enforcing here would
            // reject every submission — a total outage from one missing var.
            if ($secret !== '' || $siteKey !== '') {
                error_log('[{slug} contact] turnstile half-configured — set both TURNSTILE_SITE_KEY and TURNSTILE_SECRET_KEY, or neither; skipping check');
            }
            return true;
        }
        if ($token === '') {
            return false;
        }
        $res = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'timeout' => 8,
            'body' => [
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $ip,
            ],
        ]);
        if (is_wp_error($res)) {
            error_log('[{slug} contact] turnstile verify http error: ' . $res->get_error_message());
            return false;
        }
        $body = json_decode(wp_remote_retrieve_body($res), true);
        return (bool) ($body['success'] ?? false);
    }

    public static function handle(WP_REST_Request $request): WP_REST_Response
    {
        $cfg = self::config();
        $data = $request->get_json_params() ?? [];
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

        // 2. Honeypot — bots fill `website` (legitimate-looking name).
        if (!empty(trim((string) ($data['website'] ?? '')))) {
            self::audit('honeypot_hit', $ip);
            return new WP_REST_Response(['ok' => true], 200);
        }

        // 3. Timing — submit within N seconds of page-render? almost always a script.
        $ts = (int) ($data['_ts'] ?? 0);
        $minElapsed = (int) ($cfg['spam']['_minElapsedSeconds'] ?? 2);
        // The form always renders `_ts`, so its absence means the payload was
        // not built by the form. Treat that as a failure rather than a skip.
        if ($ts <= 0) {
            self::audit('missing_ts', $ip);
            return new WP_REST_Response(['ok' => true], 200);
        }
        if ((time() - $ts) < $minElapsed) {
            self::audit('timing_too_fast', $ip, ['elapsed' => time() - $ts]);
            return new WP_REST_Response(['ok' => true], 200);
        }

        // 4. Interaction count — low/zero count → bot.
        $interactions = (int) ($data['_interactions'] ?? 0);
        $minInteractions = (int) ($cfg['spam']['_minInteractions'] ?? 3);
        if ($interactions < $minInteractions) {
            self::audit('low_interactions', $ip, ['count' => $interactions]);
            return new WP_REST_Response(['ok' => true], 200);
        }

        // 7. Field validation (shared rules).
        $email = sanitize_email((string) ($data['email'] ?? ''));
        $message = sanitize_textarea_field((string) ($data['message'] ?? ''));

        if (!is_email($email) || strlen($email) > (int) ($cfg['email']['maxLength'] ?? 120)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'invalid_email'], 422);
        }
        $msgLen = strlen($message);
        if ($msgLen < (int) ($cfg['message']['minLength'] ?? 10)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'message_too_short'], 422);
        }
        if ($msgLen > (int) ($cfg['message']['maxLength'] ?? 5000)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'message_too_long'], 422);
        }

        // 5. Gibberish — silent reject (fake success).
        if (self::isGibberish($message, $cfg['spam'] ?? [])) {
            self::audit('gibberish', $ip, ['msg_len' => $msgLen]);
            return new WP_REST_Response(['ok' => true], 200);
        }

        // 6. Rate limit.
        $window = (int) ($cfg['spam']['rateLimitWindowSeconds'] ?? 60);
        $max = (int) ($cfg['spam']['rateLimitPerMinute'] ?? 3);
        $key = '{slug}_contact_' . md5($ip);
        $recent = (array) (get_transient($key) ?: []);
        $now = time();
        $recent = array_values(array_filter($recent, fn ($t) => ($now - (int) $t) < $window));
        if (count($recent) >= $max) {
            self::audit('rate_limited', $ip, ['count' => count($recent)]);
            return new WP_REST_Response(['ok' => false, 'error' => 'rate_limited'], 429);
        }
        $recent[] = $now;
        set_transient($key, $recent, $window * 2);

        // 8. Turnstile — only when configured; dev without keys passes through.
        $token = (string) ($data['cf_turnstile_token'] ?? '');
        if (!self::verifyTurnstile($token, $ip)) {
            self::audit('turnstile_failed', $ip);
            return new WP_REST_Response(['ok' => false, 'error' => 'captcha_failed'], 403);
        }

        // 9. Send via Resend.
        $apiKey = function_exists('env') ? (string) env('RESEND_API_KEY') : '';
        $fromAddr = (function_exists('env') ? (string) env('RESEND_FROM') : '') ?: 'onboarding@resend.dev';
        $toAddr = (function_exists('env') ? (string) env('CONTACT_TO') : '') ?: get_option('admin_email');

        if ($apiKey === '') {
            error_log('[{slug} contact] RESEND_API_KEY not set — see theme/.env');
            return new WP_REST_Response(['ok' => false, 'error' => 'not_configured'], 500);
        }

        $response = wp_remote_post('https://api.resend.com/emails', [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'from' => $fromAddr,
                'to' => [$toAddr],
                'reply_to' => $email,
                'subject' => 'New message from {domain}',
                'text' => self::buildTextBody($email, $message, $ip),
                'html' => self::buildHtmlBody($email, $message, $ip),
            ]),
        ]);

        if (is_wp_error($response)) {
            error_log('[{slug} contact] wp_remote_post error: ' . $response->get_error_message());
            return new WP_REST_Response(['ok' => false, 'error' => 'network'], 502);
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            error_log("[{slug} contact] Resend returned HTTP {$status}");
            return new WP_REST_Response(['ok' => false, 'error' => 'upstream'], 502);
        }

        self::audit('delivered', $ip);
        return new WP_REST_Response(['ok' => true], 200);
    }

    private static function buildTextBody(string $email, string $message, string $ip): string
    {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown');
        $when = gmdate('Y-m-d H:i:s') . ' UTC';

        return implode("\n", [
            "From:   {$email}",
            "Sent:   {$when}",
            '',
            '─ Message ───────────────────────────────────────',
            $message,
            '',
            '─ Verify before replying ───────────────────────',
            'The address above is unverified — anyone can type',
            'any email into the form. Before replying, confirm',
            'the sender with another signal (LinkedIn, Slack,',
            'a phone they\'ve shared, etc.) if the ask is',
            'sensitive or you don\'t recognize them.',
            '',
            '─ Meta ─────────────────────────────────────────',
            "IP:     {$ip}",
            "Agent:  {$ua}",
        ]);
    }

    private static function buildHtmlBody(string $email, string $message, string $ip): string
    {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown');
        $when = gmdate('Y-m-d H:i:s') . ' UTC';

        return sprintf(
            '<div style="font-family:Georgia,serif;color:#222;max-width:560px;line-height:1.5">'
            . '<p style="margin:0 0 4px 0"><strong>From:</strong> %s</p>'
            . '<p style="margin:0 0 20px 0;color:#666;font-size:13px">Sent %s</p>'
            . '<pre style="font-family:Georgia,serif;font-size:15px;line-height:1.6;white-space:pre-wrap;margin:0 0 28px 0;padding:16px 20px;background:#f6f5f1;border-left:3px solid #d6a84e;color:#222">%s</pre>'
            . '<p style="margin:0 0 8px 0;color:#888;font-size:12px;letter-spacing:0.05em;text-transform:uppercase">Verify before replying</p>'
            . '<p style="margin:0 0 20px 0;color:#555;font-size:13px">The address above is <em>unverified</em> — anyone can type any email into the form. Before replying, confirm the sender via a separate channel (LinkedIn, phone, prior correspondence) if the ask is sensitive or the sender is unknown.</p>'
            . '<hr style="border:0;border-top:1px solid #eee;margin:20px 0">'
            . '<p style="margin:0;color:#999;font-size:11px;font-family:Menlo,Monaco,monospace">IP: %s &nbsp;&middot;&nbsp; Agent: %s</p>'
            . '</div>',
            esc_html($email),
            esc_html($when),
            esc_html($message),
            esc_html($ip),
            esc_html($ua)
        );
    }
}
