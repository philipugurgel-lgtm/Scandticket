<?php
declare(strict_types=1);

namespace ScandTicket\API;

use ScandTicket\Core\Container;
use ScandTicket\Devices\DeviceRepository;
use WP_REST_Request;
use WP_REST_Response;

final class DeviceController
{
    public function index(): WP_REST_Response { return new WP_REST_Response(Container::instance()->make(DeviceRepository::class)->all(false), 200); }

    public function create(WP_REST_Request $request): WP_REST_Response
    {
        $name = sanitize_text_field($request->get_param('name') ?? '');
        if (empty($name)) return new WP_REST_Response(['message' => 'Name is required.'], 400);
        $result = Container::instance()->make(DeviceRepository::class)->create($name, $request->get_param('event_ids') ?? []);
        return new WP_REST_Response(['id' => $result['id'], 'name' => $result['name'], 'token' => $result['token'], 'message' => 'Device created. Save the token — it will not be shown again.'], 201);
    }

    public function revoke(WP_REST_Request $request): WP_REST_Response
    {
        Container::instance()->make(DeviceRepository::class)->deactivate((int) $request->get_param('id'));
        return new WP_REST_Response(['message' => 'Device revoked.'], 200);
    }
}