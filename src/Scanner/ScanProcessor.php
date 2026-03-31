<?php
declare(strict_types=1);

namespace ScandTicket\Scanner;

use ScandTicket\Core\Container;
use ScandTicket\Security\HmacService;
use ScandTicket\Security\NonceService;
use ScandTicket\Security\InputValidator;
use ScandTicket\Security\QrPayload;
use ScandTicket\Idempotency\IdempotencyGuard;
use ScandTicket\RateLimit\RateLimiter;
use ScandTicket\Queue\ScanQueue;
use ScandTicket\Fraud\FraudDetector;
use ScandTicket\Fraud\FraudScore;
use ScandTicket\Logging\ScanLogger;
use ScandTicket\Metrics\MetricsCollector;
use WP_Error;

final class ScanProcessor
{
    public function __construct(
        private readonly HmacService       $hmac,
        private readonly NonceService      $nonce,
        private readonly InputValidator    $validator,
        private readonly IdempotencyGuard  $idempotency,
        private readonly RateLimiter       $rateLimiter,
        private readonly ScanQueue         $queue,
        private readonly FraudDetector     $fraud,
        private readonly ScanLogger        $logger,
        private readonly MetricsCollector  $metrics,
    ) {}

    public function process(array $rawInput, array $device): array|WP_Error
    {
        $deviceId = (int) $device['id'];

        // Step 1: Validate input structure
        $payload = $this->validator->parseQrPayload($rawInput);
        if (is_wp_error($payload)) {
            $this->logAndCount($rawInput, $deviceId, 'validate', 'rejected', 'scan_invalid');
            return $this->errorResponse($payload, 'validate', false);
        }

        // Step 2: Verify HMAC signature
        if (!$this->hmac->verify($payload->signingData(), $payload->signature)) {
            $this->logPayloadAndCount($payload, $deviceId, 'hmac', 'failed', 'scan_hmac_failed');
            return $this->errorResponse(new WP_Error('hmac_failed', 'Signature verification failed.', ['status' => 403]), 'hmac', false);
        }

        // Step 3: Rate limit (non-destructive)
        $rateResult = $this->rateLimiter->check($deviceId);
        if (is_wp_error($rateResult)) {
            $this->logPayloadAndCount($payload, $deviceId, 'ratelimit', 'throttled', 'scan_rate_limited');
            return $this->errorResponse($rateResult, 'rate_limit', true);
        }

        // Step 4: Fraud detection (non-destructive)
        $fraudScore = $this->fraud->analyze($payload->toArray(), $device);
        if ($fraudScore->isBlocked()) {
            $this->logPayloadAndCount($payload, $deviceId, 'fraud', 'blocked', 'scan_fraud_blocked', $fraudScore->score);
            return $this->errorResponse(new WP_Error('fraud_detected', 'Scan flagged by fraud detection.', ['status' => 403, 'fraud_score' => $fraudScore->score]), 'fraud', false);
        }

        // === DESTRUCTIVE BOUNDARY ===

        // Step 5: Nonce validation (destructive)
        if (!$this->nonce->validate($payload->nonce)) {
            $this->logPayloadAndCount($payload, $deviceId, 'nonce', 'replayed', 'scan_replayed');
            return $this->errorResponse(new WP_Error('nonce_replayed', 'Replay detected.', ['status' => 409]), 'nonce', false);
        }

        // Step 6: Idempotency lock (destructive)
        $idempotencyKey = $this->idempotency->generateKey($payload->ticketId, $payload->eventId, $deviceId);
        if (!$this->idempotency->acquire($idempotencyKey)) {
            $this->logPayloadAndCount($payload, $deviceId, 'idempotency', 'duplicate', 'scan_duplicate');
            return $this->errorResponse(new WP_Error('duplicate_scan', 'Ticket already scanned for this event.', ['status' => 409]), 'idempotency', false);
        }

        // Step 7: Queue or synchronous processing
        $result = $this->enqueue($payload, $device, $idempotencyKey, $fraudScore);
        if (is_wp_error($result)) {
            $this->rollback($payload, $idempotencyKey);
            return $result;
        }

        $this->logPayloadAndCount($payload, $deviceId, 'scan', 'accepted', 'scan_accepted', $fraudScore->score);
        return $result;
    }

    public function processBatch(array $scans, array $device): array
    {
        $results = [];
        foreach ($scans as $i => $rawInput) {
            if (!is_array($rawInput)) {
                $results[] = ['index' => $i, 'code' => 'invalid_element', 'error' => 'Scan element must be an object.'];
                continue;
            }
            $result = $this->process($rawInput, $device);
            if (is_wp_error($result)) {
                $errorData = $result->get_error_data();
                $results[] = ['index' => $i, 'code' => $result->get_error_code(), 'error' => $result->get_error_message(), 'status' => $errorData['status'] ?? 400, 'retry' => $errorData['retry'] ?? false, 'step' => $errorData['step'] ?? 'unknown'];
            } else {
                $results[] = array_merge(['index' => $i], $result);
            }
        }
        return $results;
    }

    private function enqueue(QrPayload $payload, array $device, string $idempotencyKey, FraudScore $fraudScore): array|WP_Error
    {
        $deviceId = (int) $device['id'];
        $job = [
            'ticket_id' => $payload->ticketId, 'event_id' => $payload->eventId,
            'device_id' => $deviceId, 'idempotency_key' => $idempotencyKey,
            'fraud_score' => $fraudScore->score, 'scanned_at' => gmdate('Y-m-d H:i:s'),
            'metadata' => ['nonce' => $payload->nonce, 'timestamp' => $payload->timestamp, 'signals' => $fraudScore->signalScores()],
        ];

        $jobId = $this->queue->push($job);
        if ($jobId !== false) {
            return $this->successResponse($payload, $jobId, $idempotencyKey, true);
        }

        $this->metrics->increment('scan_sync_fallback');
        $worker = Container::instance()->make(ScanWorker::class);
        try { $success = $worker->processJob($job); } catch (\Throwable $e) {
            do_action('scandticket_sync_worker_exception', $e, $job);
            $success = false;
        }

        if ($success) {
            return $this->successResponse($payload, 'sync:' . bin2hex(random_bytes(8)), $idempotencyKey, false);
        }

        $this->logPayloadAndCount($payload, $deviceId, 'enqueue', 'total_failure', 'scan_total_failure');
        return new WP_Error('processing_failed', 'Scan could not be processed. Both queue and direct processing failed. Please retry.', ['status' => 503, 'retry' => true, 'step' => 'enqueue']);
    }

    private function rollback(QrPayload $payload, string $idempotencyKey): void
    {
        $this->idempotency->release($idempotencyKey);
        $this->nonce->release($payload->nonce);
        do_action('scandticket_pipeline_rollback', $payload->ticketId, $payload->eventId);
    }

    private function successResponse(QrPayload $payload, string $jobId, string $idempotencyKey, bool $queued): array
    {
        return ['status' => 'accepted', 'ticket_id' => $payload->ticketId, 'event_id' => $payload->eventId, 'job_id' => $jobId, 'idempotency_key' => $idempotencyKey, 'queued' => $queued];
    }

    private function errorResponse(WP_Error $error, string $step, bool $retry): WP_Error
    {
        $data = $error->get_error_data() ?: [];
        $data['step'] = $step;
        $data['retry'] = $retry;
        return new WP_Error($error->get_error_code(), $error->get_error_message(), $data);
    }

    private function logAndCount(array $raw, int $deviceId, string $action, string $result, string $metric): void
    {
        $this->logger->logRaw($raw, $deviceId, $action, $result);
        $this->metrics->increment($metric);
    }

    private function logPayloadAndCount(QrPayload $payload, int $deviceId, string $action, string $result, string $metric, ?float $fraudScore = null): void
    {
        $this->logger->logPayload($payload, $deviceId, $action, $result, $fraudScore);
        $this->metrics->increment($metric);
    }
}