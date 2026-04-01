<?php
declare(strict_types=1);

namespace ScandTicket\Core;

/**
 * Shared HTTP utility for client IP resolution with trusted-proxy support.
 *
 * By default only REMOTE_ADDR is used, which prevents IP spoofing via
 * X-Forwarded-For headers. To enable proxy header trust, define the
 * SCANDTICKET_TRUSTED_PROXIES constant in wp-config.php with an array
 * of trusted proxy IP addresses:
 *
 *   define('SCANDTICKET_TRUSTED_PROXIES', ['10.0.0.1', '192.168.1.1']);
 *
 * Proxy headers are only trusted when REMOTE_ADDR exactly matches one
 * of the listed proxies, preventing spoofing from arbitrary clients.
 */
final class HttpHelper
{
    /**
     * Resolve the real client IP address.
     *
     * Order of precedence when behind a trusted proxy:
     *   CF-Connecting-IP → X-Forwarded-For (first token) → X-Real-IP → REMOTE_ADDR
     *
     * Without configured trusted proxies only REMOTE_ADDR is returned.
     */
    public static function getClientIp(): string
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';

        if (!filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
            return '0.0.0.0';
        }

        $trustedProxies = defined('SCANDTICKET_TRUSTED_PROXIES')
            ? (array) SCANDTICKET_TRUSTED_PROXIES
            : [];

        if (!empty($trustedProxies) && in_array($remoteAddr, $trustedProxies, true)) {
            foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $header) {
                if (!empty($_SERVER[$header])) {
                    $ip = trim(strtok((string) $_SERVER[$header], ','));
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        return $ip;
                    }
                }
            }
        }

        return $remoteAddr;
    }
}
