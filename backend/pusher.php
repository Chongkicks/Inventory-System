<?php

require_once __DIR__ . '/../vendor/autoload.php';

if (!function_exists('env_value')) {
    require_once __DIR__ . '/db.php';
}

// Realtime is optional; only enable it when all Pusher credentials exist.
function inventory_realtime_is_configured()
{
    return env_value('app_id') && env_value('key') && env_value('secret');
}

// Lazily create one Pusher client per request.
function inventory_pusher_client()
{
    static $pusher = null;

    if ($pusher instanceof Pusher\Pusher) {
        return $pusher;
    }

    if (!inventory_realtime_is_configured()) {
        return null;
    }

    $pusher = new Pusher\Pusher(
        env_value('key'),
        env_value('secret'),
        env_value('app_id'),
        [
            'cluster' => env_value('cluster', 'ap1'),
            'useTLS' => true
        ]
    );

    return $pusher;
}

// Read the browser socket id so the user who submitted a form does not receive duplicate events.
function inventory_request_socket_id()
{
    $socketId = trim((string) ($_POST['pusher_socket_id'] ?? ''));

    return preg_match('/\A\d+\.\d+\z/', $socketId) ? $socketId : '';
}

// Send a standard inventory notification payload to all subscribed screens.
function trigger_inventory_notification(array $payload, string $socketId = '')
{
    $pusher = inventory_pusher_client();

    if (!$pusher) {
        return false;
    }

    $payload = array_merge([
        'title' => 'Inventory update',
        'message' => 'Inventory data changed.',
        'level' => 'info',
        'refresh' => true,
        'createdAt' => date(DATE_ATOM)
    ], $payload);

    $params = [];
    $socketId = trim($socketId);

    if ($socketId !== '' && preg_match('/\A\d+\.\d+\z/', $socketId)) {
        $params['socket_id'] = $socketId;
    }

    try {
        $pusher->trigger('inventory-channel', 'inventory-notification', $payload, $params);
        return true;
    } catch (Exception $exception) {
        error_log($exception->getMessage());
        return false;
    }
}

?>
