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

// Inline copy of the gibberish detector (mirrors ContactHandler::isGibberish)
// so tests can run without bootstrapping WordPress.
function isGibberish(string $text, array $cfg): bool
{
    $len = strlen($text);
    if ($len < (int) ($cfg['gibberishSkipLengthBelow'] ?? 8)) return false;
    $letters = preg_replace('/[^A-Za-z]/', '', $text);
    $letterLen = strlen($letters);
    if ($letterLen < 4) return false;
    $vowelCount = preg_match_all('/[aeiouAEIOU]/', $letters);
    $ratio = $vowelCount / $letterLen;
    if ($ratio < ($cfg['gibberishVowelMinRatio'] ?? 0.15)) return true;
    $maxRun = (int) ($cfg['gibberishMaxConsonantRun'] ?? 6);
    if (preg_match('/[bcdfghjklmnpqrstvwxyzBCDFGHJKLMNPQRSTVWXYZ]{' . $maxRun . ',}/', $letters)) return true;
    return false;
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
// Reflection rather than a WordPress bootstrap: verifyTurnstile returns
// before any wp_remote_post call in all four cases below, so nothing here
// touches the network. env() is stubbed only if the runtime has none, which
// is always true when this file is run directly as a CLI script.
$handlerPath = null;
foreach ([__DIR__ . '/ContactHandler.php', __DIR__ . '/../app/Http/ContactHandler.php'] as $candidate) {
    if (file_exists($candidate)) { $handlerPath = $candidate; break; }
}

echo "\nturnstile configuration gate:\n";
if ($handlerPath === null) {
    echo "  ! skipped — ContactHandler.php not found next to this file or in ../app/Http/\n";
} else {
    require_once $handlerPath;
    if (!function_exists('env')) {
        function env(string $key)
        {
            return $GLOBALS['TEST_ENV'][$key] ?? '';
        }
    }

    $logFile = tempnam(sys_get_temp_dir(), 'contact-test-');
    ini_set('error_log', $logFile);

    $verify = new ReflectionMethod('App\Http\ContactHandler', 'verifyTurnstile');
    if (PHP_VERSION_ID < 80100) {
        $verify->setAccessible(true); // no-op since 8.1, deprecated in 8.5
    }

    // Returns [allowed, log output] for one key combination.
    $gate = function (string $secret, string $siteKey, string $token) use ($verify, $logFile) {
        $GLOBALS['TEST_ENV'] = ['TURNSTILE_SECRET_KEY' => $secret, 'TURNSTILE_SITE_KEY' => $siteKey];
        file_put_contents($logFile, '');
        return [$verify->invoke(null, $token, '203.0.113.7'), file_get_contents($logFile)];
    };

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
}

// ── Summary ───────────────────────────────────────────────────────────────
echo "\n—\n";
echo "passed: $passed    failed: $failed\n";
exit($failed === 0 ? 0 : 1);
