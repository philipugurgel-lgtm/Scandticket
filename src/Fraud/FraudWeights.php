<?php
declare(strict_types=1);

namespace ScandTicket\Fraud;

final class FraudWeights
{
    private const DEFAULTS = ['duplicate' => 0.40, 'rapid' => 0.20, 'multi_device' => 0.30, 'timestamp' => 0.10];
    private static ?array $cache = null;

    public static function get(): array
    {
        if (self::$cache !== null) return self::$cache;
        $weights = self::DEFAULTS;
        $option = get_option('scandticket_fraud_weights', null);
        if ($option !== null) {
            $overrides = is_string($option) ? json_decode($option, true) : $option;
            if (is_array($overrides)) $weights = self::merge($weights, $overrides);
        }
        if (defined('SCANDTICKET_FRAUD_WEIGHTS') && is_array(SCANDTICKET_FRAUD_WEIGHTS)) {
            $weights = self::merge($weights, SCANDTICKET_FRAUD_WEIGHTS);
        }
        self::$cache = $weights;
        return $weights;
    }

    public static function defaults(): array { return self::DEFAULTS; }

    public static function save(array $input): array
    {
        $validated = self::merge(self::DEFAULTS, $input);

        // Guard against misconfiguration: weights summing above 1.0 cause every
        // scan to reach the block threshold once any single signal fires at full
        // strength. Reject the entire update and keep the current safe values.
        $sum = array_sum($validated);
        if ($sum > 1.0 + 1e-9) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Fraud weights sum to %.4f, which exceeds 1.0. ' .
                    'All weights must sum to at most 1.0 to prevent blocking every scan.',
                    $sum,
                )
            );
        }

        update_option('scandticket_fraud_weights', wp_json_encode($validated));
        self::$cache = null;
        return $validated;
    }

    public static function reset(): void { self::$cache = null; }

    private static function merge(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (!array_key_exists($key, $base) || !is_numeric($value)) continue;
            $base[$key] = min(1.0, max(0.0, (float) $value));
        }
        return $base;
    }
}