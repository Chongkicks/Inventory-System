<?php

require_once __DIR__ . '/session.php';

// CSRF helpers rely on the same session storage as auth.
inventory_start_session();

// Create or return the session token used by forms.
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

// Render the hidden input every POST form needs.
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

// Compare submitted and session tokens without timing leaks.
function csrf_is_valid(): bool
{
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $sessionToken = (string) ($_SESSION['csrf_token'] ?? '');

    return $submittedToken !== '' && $sessionToken !== '' && hash_equals($sessionToken, $submittedToken);
}

// Redirect helper for security failures.
function security_redirect(string $path, array $params = []): void
{
    $query = http_build_query($params);
    $separator = str_contains($path, '?') ? '&' : '?';

    header('Location:' . $path . ($query !== '' ? $separator . $query : ''));
    exit;
}

// Enforce POST + valid CSRF token for non-AJAX routes.
function csrf_require(string $redirectPath, string $message = 'Security token expired. Please try again.'): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !csrf_is_valid()) {
        security_redirect($redirectPath, ['message' => $message]);
    }
}

// Detect AJAX requests so backend actions can return JSON instead of a redirect.
function request_expects_json(): bool
{
    $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));

    return $requestedWith === 'xmlhttprequest' || str_contains($accept, 'application/json');
}

// Send one consistent response shape for normal forms and AJAX forms.
function action_response(string $message, string $redirectUrl, bool $ok, array $extra = []): void
{
    if (request_expects_json()) {
        http_response_code($ok ? 200 : 422);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode(array_merge([
            'ok' => $ok,
            'message' => $message,
            'redirect' => $redirectUrl,
            'refresh' => true
        ], $extra), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    header('Location:' . $redirectUrl);
    exit;
}

// Trim, remove tags/control chars, and cap text length before validation or storage.
function security_clean_text($value, int $maxLength = 255): string
{
    $value = trim((string) $value);
    $value = strip_tags($value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';

    return substr($value, 0, $maxLength);
}

// Normalize emails before uniqueness checks.
function security_clean_email($value): string
{
    return strtolower(security_clean_text($value, 80));
}

// Allow names with letters, numbers, spaces, and common punctuation only.
function security_valid_name(string $value, int $maxLength = 80): bool
{
    return $value !== ''
        && strlen($value) <= $maxLength
        && (bool) preg_match("/^[\p{L}\p{M}0-9 .,'-]+$/u", $value);
}

// Keep usernames predictable and URL/form friendly.
function security_valid_username(string $value): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_.-]{3,80}$/', $value);
}

// Basic password length rule used by registration and profile updates.
function security_valid_password(string $value): bool
{
    return strlen($value) >= 6 && strlen($value) <= 255;
}

// Read an integer inside an accepted range, falling back to the minimum.
function security_int($value, int $min = 0, int $max = PHP_INT_MAX): int
{
    $intValue = filter_var($value, FILTER_VALIDATE_INT, [
        'options' => [
            'default' => $min,
            'min_range' => $min,
            'max_range' => $max
        ]
    ]);

    return (int) $intValue;
}

// Read a decimal value inside an accepted money/quantity range.
function security_decimal($value, float $min = 0.0, float $max = 999999999.99): float
{
    $floatValue = filter_var($value, FILTER_VALIDATE_FLOAT);

    if ($floatValue === false) {
        return $min;
    }

    return min($max, max($min, (float) $floatValue));
}

?>
