<?php
/**
 * TEMPORARY web-accessible opcache reset + deploy verifier.
 * The chat runs under PHP-FPM, whose opcache can only be reset from inside an
 * FPM (web) request — a CLI `opcache_reset()` does NOT clear it. Open this URL
 * in a browser to (1) confirm the deployed files are current and (2) flush the
 * web opcache so the new code takes effect.
 *
 *   https://staging.ai-fcard.com/backend/scripts/opcache_reset_web.php?token=fcard-optemp-2026
 *
 * SECURITY: delete this file once the chat works again.
 */
header('Content-Type: text/plain; charset=UTF-8');

$TOKEN = 'fcard-optemp-2026';
if (($_GET['token'] ?? '') !== $TOKEN) {
    http_response_code(403);
    echo "forbidden\n";
    exit;
}

echo "SAPI                 : " . PHP_SAPI . "\n";
echo "PHP version          : " . PHP_VERSION . "\n";

$helper = realpath(__DIR__ . '/../includes/chat-public-data-helper.php');
$send   = realpath(__DIR__ . '/../api/chat/send.php');
echo "\n-- deployed files --\n";
foreach (['chat-public-data-helper.php' => $helper, 'send.php' => $send] as $label => $path) {
    if ($path && is_file($path)) {
        echo sprintf("%-28s mtime=%s size=%d\n", $label, date('Y-m-d H:i:s', filemtime($path)), filesize($path));
    } else {
        echo sprintf("%-28s NOT FOUND\n", $label);
    }
}

// Is the fix actually present in the file the WEB tree serves?
echo "\n-- fix marker check --\n";
$src = $helper ? (string)file_get_contents($helper) : '';
$hasFix = strpos($src, 'Keep the recalled $rows and let the confidence + location filtering below') !== false;
$hasOldBug = (bool)preg_match('/\}\s*else\s*\{\s*\/\/\s*Exact equality always outranks[^\n]*\n\s*\$rows = \$exactRows;/', $src);
echo "location-qualifier fix present : " . ($hasFix ? "YES" : "NO  <<< old file still deployed") . "\n";
echo "old '\$rows = \$exactRows' else  : " . ($hasOldBug ? "STILL PRESENT <<< old file" : "gone") . "\n";

// opcache status + reset (FPM only).
echo "\n-- opcache --\n";
if (function_exists('opcache_get_status')) {
    $cfg = function_exists('opcache_get_configuration') ? opcache_get_configuration() : [];
    $dir = $cfg['directives'] ?? [];
    echo "opcache.enable            : " . var_export($dir['opcache.enable'] ?? null, true) . "\n";
    echo "opcache.validate_timestamps: " . var_export($dir['opcache.validate_timestamps'] ?? null, true) . "\n";
    echo "opcache.revalidate_freq   : " . var_export($dir['opcache.revalidate_freq'] ?? null, true) . "\n";

    if (function_exists('opcache_reset')) {
        $ok = opcache_reset();
        echo "opcache_reset()           : " . ($ok ? "OK (web opcache flushed)" : "returned false") . "\n";
    }
    if ($helper && function_exists('opcache_invalidate')) {
        opcache_invalidate($helper, true);
        if ($send) opcache_invalidate($send, true);
        echo "opcache_invalidate()      : forced for helper + send.php\n";
    }
} else {
    echo "opcache extension not loaded in this SAPI (nothing to flush).\n";
}

echo "\nDONE. Re-test the chat now, then DELETE this file.\n";
