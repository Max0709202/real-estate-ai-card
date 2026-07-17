<?php
/**
 * Public form abuse controls: CSRF, honeypot, timing trap, per-IP rate limit, audit log.
 * Loaded by functions.php after Composer autoload.
 *
 * Applies to unauthenticated forms that trigger outbound mail (contact.php).
 */

/**
 * Log structured abuse events (grep for prefix [form_security]).
 */
function form_security_log_event(string $event, array $context = []): void {
    $row = [
        'ts'      => date('c'),
        'event'   => $event,
        'ip'      => form_security_client_ip(),
        'ua'      => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
        'context' => $context,
    ];
    error_log('[form_security] ' . json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/**
 * Client IP. REMOTE_ADDR only — proxy headers are attacker-controlled and would defeat the rate limit.
 */
function form_security_client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

/**
 * Session CSRF token for a named form. Created once per session, reused across renders.
 */
function form_security_csrf_token(string $formKey): string {
    $sessionKey = 'form_csrf_' . $formKey;
    if (empty($_SESSION[$sessionKey]) || !is_string($_SESSION[$sessionKey])) {
        $_SESSION[$sessionKey] = generateToken(32);
    }
    return $_SESSION[$sessionKey];
}

/**
 * Constant-time CSRF comparison. Fails closed when no token was ever issued.
 */
function form_security_verify_csrf(string $formKey, $posted): bool {
    $sessionKey = 'form_csrf_' . $formKey;
    if (empty($_SESSION[$sessionKey]) || !is_string($posted) || $posted === '') {
        return false;
    }
    return hash_equals($_SESSION[$sessionKey], $posted);
}

/**
 * Invalidate the token after a successful send so a replayed POST cannot resubmit.
 */
function form_security_rotate_csrf(string $formKey): void {
    $_SESSION['form_csrf_' . $formKey] = generateToken(32);
}

/**
 * Signed render timestamp, embedded as a hidden field. Keyed to the session CSRF
 * token so it cannot be forged or replayed across sessions.
 */
function form_security_timestamp(string $formKey): string {
    $now = time();
    return $now . '.' . hash_hmac('sha256', (string) $now, form_security_csrf_token($formKey));
}

/**
 * Verify the render timestamp: signature valid, not submitted implausibly fast, not stale.
 *
 * @return bool True if the timing looks human.
 */
function form_security_verify_timestamp(string $formKey, $posted): bool {
    if (!is_string($posted) || strpos($posted, '.') === false) {
        return false;
    }
    [$ts, $sig] = explode('.', $posted, 2);
    if (!ctype_digit($ts)) {
        return false;
    }
    $expected = hash_hmac('sha256', $ts, form_security_csrf_token($formKey));
    if (!hash_equals($expected, $sig)) {
        return false;
    }

    $minFill = defined('CONTACT_MIN_FILL_SECONDS') ? (int) CONTACT_MIN_FILL_SECONDS : 3;
    $maxAge  = defined('CONTACT_FORM_MAX_AGE_SECONDS') ? (int) CONTACT_FORM_MAX_AGE_SECONDS : 86400;
    $elapsed = time() - (int) $ts;

    return $elapsed >= $minFill && $elapsed <= $maxAge;
}

/**
 * Honeypot: a CSS-hidden text input humans never see. Any value means a bot filled the form.
 *
 * @return bool True if the honeypot was tripped.
 */
function form_security_honeypot_tripped(array $post): bool {
    $field = defined('CONTACT_HONEYPOT_FIELD') ? CONTACT_HONEYPOT_FIELD : 'website_url';
    return isset($post[$field]) && trim((string) $post[$field]) !== '';
}

/**
 * Reject cross-origin POSTs. Absent Origin/Referer is allowed (some privacy tools strip
 * them); a present-but-foreign one is not.
 *
 * @return bool True if the origin is acceptable.
 */
function form_security_check_origin(): bool {
    $source = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
    if ($source === '') {
        return true;
    }
    $sourceHost = parse_url($source, PHP_URL_HOST);
    if ($sourceHost === null || $sourceHost === false) {
        return false;
    }

    $allowed = [];
    if (defined('BASE_URL')) {
        $baseHost = parse_url(BASE_URL, PHP_URL_HOST);
        if (is_string($baseHost) && $baseHost !== '') {
            $allowed[] = strtolower($baseHost);
        }
    }
    if (!empty($_SERVER['HTTP_HOST'])) {
        $allowed[] = strtolower(parse_url('http://' . $_SERVER['HTTP_HOST'], PHP_URL_HOST));
    }

    return in_array(strtolower($sourceHost), array_filter($allowed), true);
}

/**
 * Per-IP fixed-window rate limit (file-based, no DB). Mirrors the upload limiter but
 * takes an explicit window so mail-sending forms can use a longer one.
 *
 * @param string $scope  Namespace so different forms don't share a counter.
 * @param int    $max    Max requests per window (0 = unlimited).
 * @param int    $window Window length in seconds.
 * @return bool True if the request is allowed.
 */
function form_security_check_rate_limit(string $scope, int $max, int $window): bool {
    if ($max <= 0) {
        return true;
    }
    $ip = form_security_client_ip();
    if ($ip === '') {
        return true;
    }

    $file = sys_get_temp_dir() . '/ai_fcard_form_rl_' . $scope . '_' . hash('sha256', $ip) . '.json';
    $now  = time();

    $handle = @fopen($file, 'c+');
    if ($handle === false) {
        // Fail open: a broken temp dir must not take the contact form offline.
        return true;
    }

    $allowed = true;
    if (flock($handle, LOCK_EX)) {
        $raw  = stream_get_contents($handle);
        $data = ['window_start' => $now, 'count' => 0];
        if ($raw !== false && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['window_start'], $decoded['count'])) {
                $data = $decoded;
            }
        }
        if ($now - (int) $data['window_start'] >= $window) {
            $data = ['window_start' => $now, 'count' => 0];
        }
        $data['count'] = (int) $data['count'] + 1;

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($data));
        fflush($handle);
        flock($handle, LOCK_UN);

        if ($data['count'] > $max) {
            $allowed = false;
        }
    }
    fclose($handle);

    if (!$allowed) {
        form_security_log_event('rate_limit_exceeded', ['scope' => $scope, 'max' => $max, 'window' => $window]);
    }
    return $allowed;
}

/**
 * Render the honeypot + signed-timestamp + CSRF hidden inputs.
 * Echoes markup; call inside the <form>.
 */
function form_security_render_fields(string $formKey): void {
    $field = defined('CONTACT_HONEYPOT_FIELD') ? CONTACT_HONEYPOT_FIELD : 'website_url';
    $csrf  = htmlspecialchars(form_security_csrf_token($formKey), ENT_QUOTES, 'UTF-8');
    $ts    = htmlspecialchars(form_security_timestamp($formKey), ENT_QUOTES, 'UTF-8');
    $fieldEsc = htmlspecialchars($field, ENT_QUOTES, 'UTF-8');

    echo '<input type="hidden" name="form_csrf" value="' . $csrf . '">' . "\n";
    echo '<input type="hidden" name="form_ts" value="' . $ts . '">' . "\n";
    // Honeypot. Off-screen rather than display:none, which trivial bots skip.
    echo '<div class="form-hp" aria-hidden="true">' . "\n";
    echo '    <label for="' . $fieldEsc . '">この項目は入力しないでください</label>' . "\n";
    echo '    <input type="text" id="' . $fieldEsc . '" name="' . $fieldEsc . '" value="" tabindex="-1" autocomplete="off">' . "\n";
    echo '</div>' . "\n";
}
