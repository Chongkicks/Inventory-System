<?php

// Print the browser-side Pusher config only when realtime credentials are present.
function realtime_client_scripts()
{
    if (!function_exists('env_value') || !env_value('key')) {
        return;
    }

    $config = [
        'key' => env_value('key'),
        'cluster' => env_value('cluster', 'ap1'),
        'channel' => 'inventory-channel',
        'event' => 'inventory-notification',
        'role' => function_exists('auth_user_role') ? auth_user_role() : 'guest'
    ];

    echo '<script src="https://js.pusher.com/8.2.0/pusher.min.js"></script>' . PHP_EOL;
    echo '<script>window.InventoryRealtime = ' . json_encode(
        $config,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
    ) . ';</script>' . PHP_EOL;
}

?>
