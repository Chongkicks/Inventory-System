<?php

require_once __DIR__ . '/../includes/security.php';

// Login/register pages are static HTML, so they request the token from here.
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['token' => csrf_token()]);

?>
