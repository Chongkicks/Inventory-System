<?php

// Start PHP sessions, falling back to the project storage folder when XAMPP's session path is unavailable.
function inventory_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $savePath = session_save_path();

    if ($savePath === '' || !is_dir($savePath) || !is_writable($savePath)) {
        $fallbackPath = __DIR__ . '/../storage/sessions';

        if (!is_dir($fallbackPath)) {
            mkdir($fallbackPath, 0775, true);
        }

        session_save_path($fallbackPath);
    }

    session_start();
}

?>
