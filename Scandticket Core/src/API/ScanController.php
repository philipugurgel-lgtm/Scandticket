<?php
declare(strict_types=1);

namespace ScandTicket\API;

use ScandTicket\Core\Container;
use ScandTicket\Auth\DeviceAuthenticator;
use ScandTicket\Scanner\ScanProcessor;
use ScandTicket\Security\InputValidator;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

final class ScanController
{
    private ?array $authenticatedDevice = null;

    public function deviceAuth(WP_REST_Request $request): bool|WP_Error
    {
        $result = Container::instance()->make(DeviceAuthenticator::class)->authenticate($request);
        if (is_wp_error($result)) return $result;
        $this->authenticatedDevice = $result;
        return true;
    }

    public function scan(WP_REST_Request $request): WP_REST_Response
    {
        $result = Container::instance()->make(ScanProcessor::class)->process($request->get_json_params(), $this->authenticatedDevice);
        if (is_wp_error($result)) return $this->buildErrorResponse($result);
        return new WP_REST_Response($result, 202);
    }

    public function batchScan(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_json_params();
        $scans = $body['scans'] ?? [];
        $valid = Container::instance()->make(InputValidator::class)->validateBatchScans($scans);
        if (is_wp_error($valid)) return $this->buildErrorResponse($valid);
        $results = Container::instance()->make(ScanProcessor::class)->processBatch($scans, $this->authenticatedDevice);
        return new WP_REST_Response(['results' => $results], 207);
    }

    private function buildErrorResponse(WP_Error $error): WP_REST_Response
    {
        $data = $error->get_error_data() ?: [];
        $status = (int) ($data['status'] ?? 400);
        $body = ['code' => $error->get_error_code(), 'message' => $error->get_error_message(), 'step' => $data['step'] ?? null, 'retry' => $data['retry'] ?? false];
        if (isset($data['fraud_score'])) $body['fraud_score'] = $data['fraud_score'];
        $response = new WP_REST_Response($body, $status);
        if ($status === 429) $response->header('Retry-After', (string) ($data['retry_after'] ?? 60));
        elseif ($status === 503) $response->header('Retry-After', '1');
        return $response;
    }
}