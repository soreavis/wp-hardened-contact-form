<?php
/**
 * Standalone PHP tests for contact form validation + spam detection.
 *
 * Runs without PHPUnit so there's no build dependency — just:
 *   php theme/tests/contact-validation.test.php
 *
 * Exits 0 on pass, non-zero on first failure. Tests both the shared config
 * (resources/data/validation.json) and the gibberish detector logic.
 *
 * Assumes the standard Sage-ish layout:
 *   theme/resources/data/validation.json
 *   theme/tests/contact-validation.test.php  ← this file
 */

// ── Mini-framework ────────────────────────────────────────────────────────
$passed = 0;
$failed = 0;

function t(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        echo "  ✓ $name\n";
        $passed++;
    } catch (Throwable $e) {
        echo "  ✗ $name\n    {$e->getMessage()}\n";
        $failed++;
    }
}
function assertTrue($cond, string $msg = 'expected true'): void
{
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}
function assertEqual($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        $a = var_export($actual, true);
        $e = var_export($expected, true);
        throw new RuntimeException(($msg ? $msg . ': ' : '') . "expected $e, got $a");
    }
}

// ── Load the handler ──────────────────────────────────────────────────────
// The gibberish detector and the Turnstile gate are both private statics on
// ContactHandler. Reflection reaches the real ones without a WordPress
// bootstrap — every method used below returns before it touches WP or the
// network — so the suite tests the shipped code rather than a copy of it.
// Same dual layout as the config: flat in this repo, nested once installed.
$handlerPath = null;
foreach ([__DIR__ . '/ContactHandler.php', __DIR__ . '/../app/Http/ContactHandler.php'] as $candidate) {
    if (file_exists($candidate)) { $handlerPath = $candidate; break; }
}
if ($handlerPath === null) {
    echo "\nContactHandler.php not found next to this file or in ../app/Http/.\n";
    echo "The detector and Turnstile tests need it — adjust the paths above for your layout.\n";
    exit(1);
}
require_once $handlerPath;

// Stubbed only when the runtime has none, which is always true for a file run
// directly by the PHP CLI. Acorn's own env() wins if something loaded it.
if (!function_exists('env')) {
    function env(string $key)
    {
        return $GLOBALS['TEST_ENV'][$key] ?? '';
    }
}

function handlerMethod(string $name): ReflectionMethod
{
    $method = new ReflectionMethod('App\Http\ContactHandler', $name);
    if (PHP_VERSION_ID < 80100) {
        $method->setAccessible(true); // required on 7.4; a no-op since 8.1, deprecated in 8.5
    }
    return $method;
}

function isGibberish(string $text, array $cfg): bool
{
    return handlerMethod('isGibberish')->invoke(null, $text, $cfg);
}

// ── Load shared validation config ─────────────────────────────────────────
// In this repo the config sits next to the templates; once the skill installs
// them into a Sage theme it lands in resources/data/. Look in both so the test
// runs from a clone as well as from an installed theme.
$cfgPath = null;
foreach ([__DIR__ . '/validation.json', __DIR__ . '/../resources/data/validation.json'] as $candidate) {
    if (file_exists($candidate)) { $cfgPath = $candidate; break; }
}
assertTrue($cfgPath !== null, 'validation.json must be found');
$cfg = json_decode(file_get_contents($cfgPath), true);
assertTrue($cfg !== null, 'validation.json must parse');
$spamCfg = $cfg['spam'];

// ── Config sanity ─────────────────────────────────────────────────────────
echo "\nconfig:\n";
t('email has pattern + maxLength', function () use ($cfg) {
    assertTrue(!empty($cfg['email']['pattern']), 'pattern missing');
    assertTrue($cfg['email']['maxLength'] >= 64, 'maxLength sane');
});
t('message has min + max length', function () use ($cfg) {
    assertTrue($cfg['message']['minLength'] >= 1);
    assertTrue($cfg['message']['maxLength'] > $cfg['message']['minLength']);
});
t('rate limit sane', function () use ($cfg) {
    assertTrue($cfg['spam']['rateLimitPerMinute'] >= 1);
    assertTrue($cfg['spam']['rateLimitWindowSeconds'] >= 10);
});

// ── Email pattern (shared with JS) ────────────────────────────────────────
echo "\nemail pattern:\n";
$re = '/' . str_replace('/', '\\/', $cfg['email']['pattern']) . '/';
t('accepts simple email', function () use ($re) {
    assertEqual(1, preg_match($re, 'user@example.com'));
});
t('accepts plus-addressed email', function () use ($re) {
    assertEqual(1, preg_match($re, 'user+tag@example.com'));
});
t('rejects missing @', function () use ($re) {
    assertEqual(0, preg_match($re, 'no-at-sign.com'));
});
t('rejects missing TLD', function () use ($re) {
    assertEqual(0, preg_match($re, 'user@domain'));
});
t('rejects trailing space', function () use ($re) {
    // The pattern doesn't permit spaces; browser trimming handles most cases.
    assertEqual(0, preg_match($re, 'user@example.com '));
});

// ── Gibberish detector ────────────────────────────────────────────────────
echo "\ngibberish detector (positive cases — should flag):\n";
t('keyboard mash', function () use ($spamCfg) {
    assertTrue(isGibberish('asdfghjklqwertyuiopzxcvbnm', $spamCfg));
});
t('vowel-starved string', function () use ($spamCfg) {
    assertTrue(isGibberish('ssrtnplmzzdcvgbhjkqw', $spamCfg));
});
t('long consonant run', function () use ($spamCfg) {
    assertTrue(isGibberish('Hello zzzzzzzzzz world', $spamCfg));
});

echo "\ngibberish detector (negative cases — should NOT flag):\n";
t('normal English sentence', function () use ($spamCfg) {
    assertTrue(!isGibberish('Hello, I would like to discuss a project with you.', $spamCfg));
});
t('international name line', function () use ($spamCfg) {
    assertTrue(!isGibberish('From Anna Müller — hoping to connect next week.', $spamCfg));
});
t('short name (below skip threshold)', function () use ($spamCfg) {
    assertTrue(!isGibberish('Hi.', $spamCfg));
});
t('technical content with symbols', function () use ($spamCfg) {
    assertTrue(!isGibberish('I built the site using Next.js + WordPress REST + Resend.', $spamCfg));
});
t('Slavic / German names do not false-positive', function () use ($spamCfg) {
    assertTrue(!isGibberish('Greetings from Bratislava, my name is Petra Schwarzkopf.', $spamCfg));
});

// ── Turnstile configuration gate ──────────────────────────────────────────
// Regression cover for 0.1.1: a secret without a site key meant no widget
// rendered, so no token could exist, and every submission was answered with
// 403. The gate must treat a half-configured pair as off, and say so in the
// log — that line is the operator's only signal the layer is not running.
//
// All four cases return before any wp_remote_post call, so nothing here
// touches the network.
$logFile = tempnam(sys_get_temp_dir(), 'contact-test-');
ini_set('error_log', $logFile);
$verify = handlerMethod('verifyTurnstile');

// Returns [allowed, log output] for one key combination.
$gate = function (string $secret, string $siteKey, string $token) use ($verify, $logFile) {
    $GLOBALS['TEST_ENV'] = ['TURNSTILE_SECRET_KEY' => $secret, 'TURNSTILE_SITE_KEY' => $siteKey];
    file_put_contents($logFile, '');
    return [$verify->invoke(null, $token, '203.0.113.7'), file_get_contents($logFile)];
};

echo "\nturnstile configuration gate:\n";
t('neither key — skips silently (dev)', function () use ($gate) {
    [$allowed, $log] = $gate('', '', '');
    assertEqual(true, $allowed, 'must skip when Turnstile is not configured');
    assertTrue(strpos($log, 'half-configured') === false, 'must not warn when neither key is set');
});
t('secret without site key — skips and warns', function () use ($gate) {
    [$allowed, $log] = $gate('secret', '', '');
    assertEqual(true, $allowed, 'must not reject when no widget can render');
    assertTrue(strpos($log, 'half-configured') !== false, 'must log the misconfiguration');
});
t('site key without secret — skips and warns', function () use ($gate) {
    [$allowed, $log] = $gate('', 'sitekey', '');
    assertEqual(true, $allowed, 'must not enforce without a secret to verify against');
    assertTrue(strpos($log, 'half-configured') !== false, 'must log the misconfiguration');
});
t('both keys, empty token — rejects', function () use ($gate) {
    [$allowed] = $gate('secret', 'sitekey', '');
    assertEqual(false, $allowed, 'must reject an empty token once fully configured');
});

unlink($logFile);

// ── Summary ───────────────────────────────────────────────────────────────
echo "\n—\n";
echo "passed: $passed    failed: $failed\n";
exit($failed === 0 ? 0 : 1);
