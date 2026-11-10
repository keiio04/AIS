<?php
// ============================================================
// security.php — Centralized Security & Data Protection Helpers
// STEP D: Input Handling & Data Protection
// ============================================================

/**
 * Escape output to prevent Cross-Site Scripting (XSS).
 * Safe replacement for raw echo of dynamic/user variables.
 *
 * @param mixed $value
 * @return string
 */
if (!function_exists('e')) {
    function e($value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Sanitize a general string input: trims whitespace and strips non-printable control characters.
 *
 * @param mixed $input
 * @param int $maxLen Maximum allowed character length (0 for unlimited)
 * @return string
 */
if (!function_exists('clean_string')) {
    function clean_string($input, int $maxLen = 255): string {
        $str = trim((string)($input ?? ''));
        // Strip ASCII control characters except standard whitespace (newlines, tabs)
        $str = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $str);
        if ($maxLen > 0 && mb_strlen($str) > $maxLen) {
            $str = mb_substr($str, 0, $maxLen);
        }
        return $str;
    }
}

/**
 * Validate and sanitize integer input (e.g., IDs, pagination parameters).
 *
 * @param mixed $input
 * @param int $default Fallback value if input is invalid
 * @return int
 */
if (!function_exists('clean_int')) {
    function clean_int($input, int $default = 0): int {
        if ($input === null || $input === '') {
            return $default;
        }
        $val = filter_var($input, FILTER_VALIDATE_INT);
        return ($val !== false) ? (int)$val : $default;
    }
}

/**
 * Validate and sanitize accounting monetary amounts and floating point numbers.
 * Cleans thousand-separator commas and spaces before parsing.
 *
 * @param mixed $input
 * @param float $default
 * @return float
 */
if (!function_exists('clean_decimal')) {
    function clean_decimal($input, float $default = 0.0): float {
        if ($input === null || $input === '') {
            return $default;
        }
        $clean = str_replace([',', ' '], '', (string)$input);
        return is_numeric($clean) ? (float)$clean : $default;
    }
}

/**
 * Validate and normalize email address. Returns null if invalid.
 *
 * @param mixed $input
 * @return string|null
 */
if (!function_exists('clean_email')) {
    function clean_email($input): ?string {
        $email = filter_var(trim((string)($input ?? '')), FILTER_VALIDATE_EMAIL);
        return ($email !== false) ? strtolower($email) : null;
    }
}

/**
 * Mask email address for privacy and Data Privacy Act compliance.
 * Example: 'juandelacruz@gmail.com' -> 'j*********z@gmail.com'
 *
 * @param string|null $email
 * @return string
 */
if (!function_exists('mask_email')) {
    function mask_email(?string $email): string {
        if (!$email || !str_contains($email, '@')) {
            return '***@***.com';
        }
        [$user, $domain] = explode('@', $email, 2);
        $len = strlen($user);
        if ($len <= 2) {
            $maskedUser = substr($user, 0, 1) . '*';
        } else {
            $maskedUser = substr($user, 0, 1) . str_repeat('*', max(1, $len - 2)) . substr($user, -1);
        }
        return $maskedUser . '@' . $domain;
    }
}

/**
 * Mask sensitive data (such as account numbers or student reference IDs).
 * Shows only the last N characters.
 * Example: mask_sensitive('2024-00123-MN-0', 4) -> '***********MN-0'
 *
 * @param string|null $str
 * @param int $visibleChars
 * @return string
 */
if (!function_exists('mask_sensitive')) {
    function mask_sensitive(?string $str, int $visibleChars = 4): string {
        $str = (string)($str ?? '');
        $len = strlen($str);
        if ($len <= $visibleChars) {
            return str_repeat('*', $len);
        }
        return str_repeat('*', $len - $visibleChars) . substr($str, -$visibleChars);
    }
}

/**
 * Validate input against a strict whitelist of allowed choices.
 *
 * @param mixed $value
 * @param array $allowedValues
 * @param string $default
 * @return string
 */
if (!function_exists('whitelist_input')) {
    function whitelist_input($value, array $allowedValues, string $default = ''): string {
        return in_array($value, $allowedValues, true) ? (string)$value : $default;
    }
}
