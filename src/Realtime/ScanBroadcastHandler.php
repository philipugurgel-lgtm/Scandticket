<?php
declare(strict_types=1);

namespace ScandTicket\Realtime;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use SplObjectStorage;

final class ScanBroadcastHandler implements MessageComponentInterface
{
    private SplObjectStorage $clients;
    private array $eventSubscriptions = [];
    private int $messagesSent = 0;
    private int $maxConnections;

    public function __construct(int $maxConnections = 1000)
    {
        $this->clients = new SplObjectStorage();
        $this->maxConnections = $maxConnections;
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        if ($this->clients->count() >= $this->maxConnections) {
            $conn->send(json_encode(['type' => 'error', 'message' => 'Server at capacity.']));
            $conn->close();
            return;
        }
        $meta = new ClientMeta(connectedAt: time(), lastPongAt: time(), remoteAddress: $this->getRemoteAddress($conn));
        $this->clients->attach($conn, $meta);
        $conn->send(json_encode(['type' => 'welcome', 'server_time' => microtime(true)]));
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        $data = json_decode((string) $msg, true);
        if (!is_array($data) || !isset($data['type'])) return;
        match ($data['type']) {
            'subscribe'   => $this->handleSubscribe($from, $data),
            'unsubscribe' => $this->handleUnsubscribe($from, $data),
            'pong'        => $this->handlePong($from),
            default       => null,
        };
    }

    public function onClose(ConnectionInterface $conn): void
    {
        foreach ($this->eventSubscriptions as $eid => $subs) {
            $subs->detach($conn);
            if ($subs->count() === 0) unset($this->eventSubscriptions[$eid]);
        }
        $this->clients->detach($conn);
    }

    public function onError(ConnectionInterface $conn, \Throwable $e): void
    {
        $conn->close();
    }

    private function handleSubscribe(ConnectionInterface $conn, array $data): void
    {
        $eventId = (int) ($data['event_id'] ?? 0);
        if ($eventId <= 0) { $conn->send(json_encode(['type' => 'error', 'message' => 'Invalid event_id.'])); return; }
        if (!isset($this->eventSubscriptions[$eventId])) $this->eventSubscriptions[$eventId] = new SplObjectStorage();
        $this->eventSubscriptions[$eventId]->attach($conn);
        if ($this->clients->contains($conn)) $this->clients[$conn]->subscribedEvents[$eventId] = true;
        $conn->send(json_encode(['type' => 'subscribed', 'event_id' => $eventId]));
    }

    private function handleUnsubscribe(ConnectionInterface $conn, array $data): void
    {
        $eventId = (int) ($data['event_id'] ?? 0);
        if (isset($this->eventSubscriptions[$eventId])) {
            $this->eventSubscriptions[$eventId]->detach($conn);
            if ($this->eventSubscriptions[$eventId]->count() === 0) unset($this->eventSubscriptions[$eventId]);
        }
        if ($this->clients->contains($conn)) unset($this->clients[$conn]->subscribedEvents[$eventId]);
        $conn->send(json_encode(['type' => 'unsubscribed', 'event_id' => $eventId]));
    }

    private function handlePong(ConnectionInterface $conn): void
    {
        if ($this->clients->contains($conn)) $this->clients[$conn]->lastPongAt = time();
    }

    public function broadcast(string $channel, string $payload): void
    {
        $data = json_decode($payload, true);
        if (!is_array($data)) return;
        $message = json_encode(['type' => $data['type'] ?? 'event', 'data' => $data['data'] ?? $data, 'channel' => $channel, 'server_time' => microtime(true)]);
        if ($channel === 'events:global') { $this->sendToAll($message); return; }
        if (preg_match('/^events:(\d+)$/', $channel, $m)) $this->sendToEventSubscribers((int) $m[1], $message);
    }

    private function sendToAll(string $message): void
    {
        foreach ($this->clients as $conn) { try { $conn->send($message); $this->messagesSent++; } catch (\Throwable) {} }
    }

    private function sendToEventSubscribers(int $eventId, string $message): void
    {
        if (!isset($this->eventSubscriptions[$eventId])) return;
        foreach ($this->eventSubscriptions[$eventId] as $conn) { try { $conn->send($message); $this->messagesSent++; } catch (\Throwable) {} }
    }

    public function pingAll(): void
    {
        $msg = json_encode(['type' => 'ping']);
        foreach ($this->clients as $conn) { try { $conn->send($msg); } catch (\Throwable) {} }
    }

    public function reapStale(): int
    {
        $threshold = time() - 90;
        $stale = [];
        foreach ($this->clients as $conn) { if ($this->clients[$conn]->lastPongAt < $threshold) $stale[] = $conn; }
        foreach ($stale as $conn) { try { $conn->send(json_encode(['type' => 'error', 'message' => 'Connection timed out.'])); $conn->close(); } catch (\Throwable) {} }
        return count($stale);
    }

    public function shutdownAll(string $reason): void
    {
        $msg = json_encode(['type' => 'shutdown', 'message' => $reason]);
        foreach ($this->clients as $conn) { try { $conn->send($msg); $conn->close(); } catch (\Throwable) {} }
    }

    public function getStats(): array
    {
        $totalSubs = 0;
        foreach ($this->eventSubscriptions as $subs) $totalSubs += $subs->count();
        return ['connections' => $this->clients->count(), 'subscriptions' => $totalSubs, 'event_channels' => count($this->eventSubscriptions), 'messages_sent' => $this->messagesSent, 'max_connections' => $this->maxConnections];
    }

    private function getRemoteAddress(ConnectionInterface $conn): string
    {
        if (isset($conn->httpRequest)) { $fwd = $conn->httpRequest->getHeader('X-Forwarded-For'); if (!empty($fwd)) return trim(explode(',', $fwd[0])[0]); }
        return $conn->remoteAddress ?? 'unknown';
    }
}