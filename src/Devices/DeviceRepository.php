<?php
declare(strict_types=1);

namespace ScandTicket\Devices;

use ScandTicket\Auth\DeviceAuthenticator;
use ScandTicket\Core\Container;

final class DeviceRepository
{
    public function create(string $name, array $eventIds = [], array $capabilities = []): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'scandticket_devices';
        $auth = Container::instance()->make(DeviceAuthenticator::class);
        [$rawToken, $hash] = $auth->generateToken();
        $wpdb->insert($table, ['device_uid' => wp_generate_uuid4(), 'name' => sanitize_text_field($name), 'token_hash' => $hash, 'event_ids' => wp_json_encode($eventIds), 'capabilities' => wp_json_encode($capabilities)]);
        return ['id' => (int) $wpdb->insert_id, 'token' => $rawToken, 'name' => $name];
    }

    public function all(bool $activeOnly = true): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'scandticket_devices';
        $where = $activeOnly ? 'WHERE is_active = 1' : '';
        return $wpdb->get_results("SELECT id, device_uid, name, event_ids, capabilities, last_seen_at, is_active, created_at FROM {$table} {$where} ORDER BY created_at DESC");
    }

    public function find(int $id): ?object
    {
        global $wpdb;
        $table = $wpdb->prefix . 'scandticket_devices';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
    }

    public function deactivate(int $id): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'scandticket_devices';
        return (bool) $wpdb->update($table, ['is_active' => 0], ['id' => $id]);
    }

    public function rotateToken(int $id): ?string
    {
        global $wpdb;
        $table = $wpdb->prefix . 'scandticket_devices';
        $auth = Container::instance()->make(DeviceAuthenticator::class);
        [$rawToken, $hash] = $auth->generateToken();
        $updated = $wpdb->update($table, ['token_hash' => $hash, 'updated_at' => current_time('mysql')], ['id' => $id]);
        return $updated ? $rawToken : null;
    }
}