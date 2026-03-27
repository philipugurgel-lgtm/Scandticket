<?php
declare(strict_types=1);

namespace ScandTicket\Staff;

final class StaffRepository
{
    public function create(array $data): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'scandticket_staff', ['user_id' => $data['user_id'] ?? null, 'name' => sanitize_text_field($data['name']), 'email' => sanitize_email($data['email'] ?? ''), 'role' => sanitize_text_field($data['role'] ?? 'scanner'), 'pin_hash' => isset($data['pin']) ? wp_hash_password($data['pin']) : null, 'event_ids' => wp_json_encode($data['event_ids'] ?? [])]);
        return (int) $wpdb->insert_id;
    }

    public function all(bool $activeOnly = true): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'scandticket_staff';
        return $wpdb->get_results("SELECT * FROM {$table} " . ($activeOnly ? 'WHERE is_active = 1 ' : '') . "ORDER BY name ASC");
    }

    public function find(int $id): ?object
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}scandticket_staff WHERE id = %d", $id));
    }

    public function deactivate(int $id): bool
    {
        global $wpdb;
        return (bool) $wpdb->update($wpdb->prefix . 'scandticket_staff', ['is_active' => 0], ['id' => $id]);
    }
}