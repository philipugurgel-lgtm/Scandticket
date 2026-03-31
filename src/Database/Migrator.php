<?php
declare(strict_types=1);

namespace ScandTicket\Database;

final class Migrator
{
    public function up(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = $wpdb->prefix . 'scandticket_devices';
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            device_uid VARCHAR(64) NOT NULL,
            name VARCHAR(255) NOT NULL,
            token_hash VARCHAR(128) NOT NULL,
            event_ids TEXT DEFAULT NULL,
            capabilities TEXT DEFAULT NULL,
            last_seen_at DATETIME DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_device_uid (device_uid),
            KEY idx_token_hash (token_hash),
            KEY idx_active (is_active)
        ) {$charset};";
        dbDelta($sql);

        $table = $wpdb->prefix . 'scandticket_staff';
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED DEFAULT NULL,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) DEFAULT NULL,
            role VARCHAR(50) NOT NULL DEFAULT 'scanner',
            pin_hash VARCHAR(128) DEFAULT NULL,
            event_ids TEXT DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user_id (user_id),
            KEY idx_role (role),
            KEY idx_active (is_active)
        ) {$charset};";
        dbDelta($sql);

        $table = $wpdb->prefix . 'scandticket_checkins';
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_id BIGINT UNSIGNED NOT NULL,
            event_id BIGINT UNSIGNED NOT NULL,
            attendee_id BIGINT UNSIGNED DEFAULT NULL,
            device_id BIGINT UNSIGNED DEFAULT NULL,
            staff_id BIGINT UNSIGNED DEFAULT NULL,
            status ENUM('checked_in','checked_out','denied','error') NOT NULL,
            scanned_at DATETIME NOT NULL,
            processed_at DATETIME DEFAULT NULL,
            idempotency_key VARCHAR(128) NOT NULL,
            metadata JSON DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idx_idempotency (idempotency_key),
            KEY idx_ticket_event (ticket_id, event_id),
            KEY idx_event_status (event_id, status),
            KEY idx_scanned_at (scanned_at),
            KEY idx_device (device_id)
        ) {$charset};";
        dbDelta($sql);

        $table = $wpdb->prefix . 'scandticket_scan_log';
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_id BIGINT UNSIGNED DEFAULT NULL,
            event_id BIGINT UNSIGNED DEFAULT NULL,
            device_id BIGINT UNSIGNED DEFAULT NULL,
            action VARCHAR(32) NOT NULL,
            result VARCHAR(32) NOT NULL,
            fraud_score DECIMAL(4,3) DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(512) DEFAULT NULL,
            payload JSON DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_event_action (event_id, action),
            KEY idx_created (created_at),
            KEY idx_device_created (device_id, created_at)
        ) {$charset};";
        dbDelta($sql);

        $table = $wpdb->prefix . 'scandticket_nonces';
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            nonce_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_nonce_hash (nonce_hash),
            KEY idx_expires (expires_at)
        ) {$charset};";
        dbDelta($sql);
    }
}