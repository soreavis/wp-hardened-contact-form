<?php

/**
 * Contact form REST endpoint setup — add this to your theme's setup.php
 * (or a dedicated Acorn service provider). Registers:
 *
 *   1. POST /wp-json/{slug}/v1/contact → App\Http\ContactHandler::handle
 *   2. Per-request CSRF nonce + config blob injected into <head> as window.{SLUG}
 *   3. Conditional Turnstile script enqueue when TURNSTILE_SITE_KEY env var is set
 *
 * INSTALL-TIME PARAMETERISATION:
 *   Replace `{slug}` with a lowercase kebab-case slug (e.g. `mybrand`).
 *   Replace `{SLUG}` with its UPPERCASE variant (e.g. `MYBRAND`) for the JS-facing
 *   global. Replace `{Slug}` with PascalCase (e.g. `Mybrand`) for the custom header.
 *
 * Why the custom `X-{Slug}-Nonce` header?
 *   WP core's `rest_cookie_check_errors` intercepts the standard `X-WP-Nonce`
 *   header BEFORE our `permission_callback` runs — which means a valid nonce
 *   would still be rejected because WP also looks for an authenticated cookie
 *   session. Using a custom header name sidesteps that path entirely and lets
 *   our permission_callback be the sole gatekeeper.
 */

namespace App;

use App\Http\ContactHandler;

add_action('rest_api_init', function () {
    register_rest_route('{slug}/v1', '/contact', [
        'methods' => 'POST',
        'callback' => [ContactHandler::class, 'handle'],
        'permission_callback' => function ($request) {
            // Custom header — avoids WP core's X-WP-Nonce cookie-session check.
            $nonce = $request->get_header('x_{slug}_nonce');
            return (bool) wp_verify_nonce($nonce, '{slug}_contact');
        },
    ]);
});

/**
 * Inject a small frontend config blob into <head> — short-lived CSRF nonce
 * for the contact form and (optionally) the Turnstile site key. Namespaced
 * on window.{SLUG} to avoid collisions with other globals.
 */
add_action('wp_head', function () {
    $config = [
        'nonce' => wp_create_nonce('{slug}_contact'),
        'contactUrl' => rest_url('{slug}/v1/contact'),
        'turnstileSiteKey' => function_exists('env') ? (string) env('TURNSTILE_SITE_KEY') : '',
    ];
    echo "\n<script id=\"{slug}-config\">window.{SLUG}=" . wp_json_encode($config) . ";</script>\n";
}, 5);

/**
 * Load Turnstile's script only when a site key is configured. When absent
 * (e.g. local dev without a key), the script tag is omitted and the client
 * falls back to no-CAPTCHA submission.
 */
add_action('wp_enqueue_scripts', function () {
    $siteKey = function_exists('env') ? (string) env('TURNSTILE_SITE_KEY') : '';
    if ($siteKey !== '') {
        wp_enqueue_script(
            'cf-turnstile',
            'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit',
            [],
            null,
            true
        );
    }
}, 20);
