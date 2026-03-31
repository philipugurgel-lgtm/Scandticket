<?php
declare(strict_types=1);

namespace ScandTicket\Queue;

final class JobEnvelope
{
    public function __construct(
        public readonly string $id,
        public readonly array  $payload,
        public readonly int    $attempts,
        public readonly int    $createdAt,
        public readonly int    $claimedAt,
        public readonly string $workerId,
    ) {}

    public static function create(array $payload): self
    {
        return new self(id: self::generateId(), payload: $payload, attempts: 0, createdAt: time(), claimedAt: 0, workerId: '');
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        return new self(
            id: (string) ($data['_id'] ?? self::generateId()),
            payload: (array) ($data['_payload'] ?? $data),
            attempts: (int) ($data['_attempts'] ?? 0),
            createdAt: (int) ($data['_created_at'] ?? time()),
            claimedAt: (int) ($data['_claimed_at'] ?? 0),
            workerId: (string) ($data['_worker_id'] ?? ''),
        );
    }

    public function toJson(): string
    {
        return json_encode([
            '_id' => $this->id, '_payload' => $this->payload, '_attempts' => $this->attempts,
            '_created_at' => $this->createdAt, '_claimed_at' => $this->claimedAt, '_worker_id' => $this->workerId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function claim(string $workerId): self
    {
        return new self(id: $this->id, payload: $this->payload, attempts: $this->attempts + 1, createdAt: $this->createdAt, claimedAt: time(), workerId: $workerId);
    }

    public function forRetry(): self
    {
        return new self(id: $this->id, payload: $this->payload, attempts: $this->attempts, createdAt: $this->createdAt, claimedAt: 0, workerId: '');
    }

    public function isExpired(int $visibilityTimeoutSeconds): bool
    {
        if ($this->claimedAt === 0) return false;
        return (time() - $this->claimedAt) > $visibilityTimeoutSeconds;
    }

    private static function generateId(): string
    {
        return bin2hex(pack('N', time())) . bin2hex(random_bytes(12));
    }
}