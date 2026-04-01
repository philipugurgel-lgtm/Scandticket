<?php
declare(strict_types=1);

namespace ScandTicket\Auth;

use ScandTicket\Core\Config;
use ScandTicket\Core\HttpHelper;
use ScandTicket\Core\RedisAdapter;
use ScandTicket\Core\RedisKeys;
use WP_Error;
use WP_REST_Request;

final class DeviceAuthenticator
{
    private const FAILURE_DELAY_US = 50_000;

    public function authenticate(WP_REST_Request $request): array|WP_Error
    {
        $startTime = hrtime(true);
        $ip = self::getClientIp();

        $bruteForceCheck = $this->checkBruteForceLimit($ip);
        if (is_wp_error($bruteForceCheck)) {
            $this->auditLog(null, $ip, 'failed_bruteforce');
            $this->enforceMinDelay($startTime);
            return $bruteForceCheck;
        }

        $header = $request->get_header('Authorization');
        if ($header === null || !str_starts_with($header, 'Bearer ')) {
            $this->recordFailedAttempt($ip);
            $this->auditLog(null, $ip, 'failed_missing');
            $this->enforceMinDelay($startTime);
            return new WP_Error('auth_missing', 'Authorization: Bearer <token> header is required.', ['status' => 401]);
        }

        $token = substr($header, 7);
        if (strlen($token) < 32 || !ctype_alnum($token)) {
            $this->recordFailedAttempt($ip);
            $this->auditLog(null, $ip, 'failed_invalid');
            $this->enforceMinDelay($startTime);
            return new WP_Error('auth_invalid_format', 'Token format is invalid.', ['status' => 401]);
        }

        $hash = hash('sha256', $token);
        $device = $this->findDeviceByTokenHash($hash);

        if ($device === null) {
            $this->recordFailedAttempt($ip);
            $this->auditLog(null, $ip, 'failed_not_found');
            $this->enforceMinDelay($startTime);
            return new WP_Error('auth_failed', 'Device authentication failed.', ['status' => 401]);
        }

        $deviceId = (int) $device->id;

        if (!((bool) $device->is_active)) {
            $this->recordFailedAttempt($ip);
            $this->auditLog($deviceId, $ip, 'failed_inactive');
            $this->enforceMinDelay($startTime);
            return new WP_Error('device_inactive', 'Device has been deactivated. Contact an administrator.', ['status' => 403]);
        }

        $ttl = (int) Config::get('token_ttl', 86400 * 30);
        if ($ttl > 0) {
            $createdAt = strtotime($device->created_at);
            $age = time() - $createdAt;
            if ($age > $ttl) {
                $this->recordFailedAttempt($ip);
                $this->auditLog($deviceId, $ip, 'failed_expired');
                $this->enforceMinDelay($startTime);
                $daysExpired = (int) ceil(($age - $ttl) / 86400);
                return new WP_Error('token_expired', sprintf('Device token expired %d day(s) ago. Rotate the token in the admin panel.', $daysExpired), ['status' => 401, 'expired_days_ago' => $daysExpired]);
            }
        }

        $this->touchDevice($deviceId);
        $this->auditLog($deviceId, $ip, 'success');
        $this->clearBruteForceCounter($ip);

        return (array) $device;
    }

    public function generateToken(): array
    {
        $raw  = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        return [$raw, $hash];
    }

    public function revoke(int $deviceId): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'scandticket_devices';
        $result = (bool) $wpdb->update($table, ['is_active' => 0, 'updated_at' => current_time('mysql')], ['id' => $deviceId]);
        $redis = RedisAdapter::connection();
        $redis?->del(RedisKeys::deviceSeen($deviceId));
        return $result;
    }

    private function checkBruteForceLimit(string $ip): true|WP_Error
    {
        $redis = RedisAdapter::connection();
        if ($redis === null) {
            // Redis unavailable: brute-force counting cannot be persisted.
            // Fire an action so operators can alert on this degraded state.
            do_action('scandticket_auth_bruteforce_redis_unavailable', $ip);
            return true;
        }
        $key = RedisKeys::authBruteForce($ip);
        $window = (int) Config::get('auth_bruteforce_window', 900);
        $limit  = (int) Config::get('auth_bruteforce_limit', 20);
        $now = microtime(true);

        $redis->zRemRangeByScore($key, '-inf', sprintf('%.6f', $now - $window));
        $count = $redis->zCard($key);

        if ($count >= $limit) {
            $oldest = $redis->zRange($key, 0, 0);
            $retryAfter = !empty($oldest) ? max(1, (int) ($window - ($now - (float) $oldest[0]))) : $window;
            return new WP_Error('auth_rate_limited', sprintf('Too many failed authentication attempts. Try again in %d seconds.', $retryAfter), ['status' => 429, 'retry_after' => $retryAfter]);
        }
        return true;
    }

    private function recordFailedAttempt(string $ip): void
    {
        $redis = RedisAdapter::connection();
        if ($redis === null) return;
        $key = RedisKeys::authBruteForce($ip);
        $now = microtime(true);
        $window = (int) Config::get('auth_bruteforce_window', 900);
        $member = sprintf('%.6f:%s', $now, bin2hex(random_bytes(4)));
        $redis->zAdd($key, $now, $member);
        $redis->expire($key, $window + 60);
    }

    private function clearBruteForceCounter(string $ip): void
    {
        $redis = RedisAdapter::connection();
        $redis?->del(RedisKeys::authBruteForce($ip));
    }

    private function findDeviceByTokenHash(string $hash): ?object
    {
        global $wpdb;
        $table = $wpdb->prefix . 'scandticket_devices';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE token_hash = %s LIMIT 1", $hash));
    }

    private function touchDevice(int $deviceId): void
    {
        $redis = RedisAdapter::connection();
        if ($redis !== null) {
            $key = RedisKeys::deviceSeen($deviceId);
            $shouldUpdate = $redis->setNxEx($key, '1', 60);
            if (!$shouldUpdate) return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'scandticket_devices';
        $wpdb->update($table, ['last_seen_at' => current_time('mysql')], ['id' => $deviceId]);
    }

    private function auditLog(?int $deviceId, string $ip, string $result): void
    {
        if ($result === 'success') {
            $redis = RedisAdapter::connection();
            if ($redis !== null) {
                $key = RedisKeys::authAuditDebounce($deviceId ?? 0);
                if (!$redis->setNxEx($key, '1', 300)) return;
            }
        }
        global $wpdb;
        $table = $wpdb->prefix . 'scandticket_scan_log';
        $wpdb->insert($table, [
            'ticket_id' => null, 'event_id' => null, 'device_id' => $deviceId,
            'action' => 'auth', 'result' => mb_substr($result, 0, 32),
            'ip_address' => $ip,
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr(sanitize_text_field($_SERVER['HTTP_USER_AGENT']), 0, 512) : null,
            'payload' => wp_json_encode(['outcome' => $result, 'ts' => time()]),
        ]);
    }

    private function enforceMinDelay(int $startTime): void
    {
        $elapsed = (hrtime(true) - $startTime) / 1_000;
        $remaining = self::FAILURE_DELAY_US - (int) $elapsed;
        if ($remaining > 0) usleep($remaining);
    }

    private static function getClientIp(): string
    {
        return HttpHelper::getClientIp();
    }
}