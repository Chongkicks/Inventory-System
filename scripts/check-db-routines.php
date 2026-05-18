<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

include __DIR__ . '/../backend/db.php';

// List database procedures installed by backend/db.php.
$procedures = $conn->query("
    SELECT ROUTINE_NAME
    FROM INFORMATION_SCHEMA.ROUTINES
    WHERE ROUTINE_SCHEMA = DATABASE()
    AND ROUTINE_TYPE = 'PROCEDURE'
    ORDER BY ROUTINE_NAME
");

// List triggers that enforce validation at the database layer.
$triggers = $conn->query("
    SELECT TRIGGER_NAME
    FROM INFORMATION_SCHEMA.TRIGGERS
    WHERE TRIGGER_SCHEMA = DATABASE()
    ORDER BY TRIGGER_NAME
");

echo 'Procedures: ' . ($procedures ? $procedures->num_rows : 0) . PHP_EOL;

if ($procedures) {
    while ($row = $procedures->fetch_assoc()) {
        echo '- ' . $row['ROUTINE_NAME'] . PHP_EOL;
    }
}

echo 'Triggers: ' . ($triggers ? $triggers->num_rows : 0) . PHP_EOL;

if ($triggers) {
    while ($row = $triggers->fetch_assoc()) {
        echo '- ' . $row['TRIGGER_NAME'] . PHP_EOL;
    }
}

?>
