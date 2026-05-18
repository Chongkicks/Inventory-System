<?php

require_once __DIR__ . '/../includes/session.php';

// Clear the current login session and return to the login screen.
inventory_start_session();
session_unset();
session_destroy();

header('Location:../frontend/login.html?msg=logout');
exit;

?>
