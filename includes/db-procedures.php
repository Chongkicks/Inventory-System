<?php

// Stored procedures can leave pending result sets; clear them before the next query.
function db_clear_more_results(mysqli $conn): void
{
    while ($conn->more_results()) {
        $conn->next_result();

        if ($result = $conn->store_result()) {
            $result->free();
        }
    }
}

// Execute a stored procedure or prepared statement that does not return an insert id.
function db_call(mysqli $conn, string $sql, string $types = '', array $params = []): bool
{
    $stmt = $conn->prepare($sql);

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    $ok = $stmt->execute();
    $stmt->close();
    db_clear_more_results($conn);

    return $ok;
}

// Execute a procedure that SELECTs LAST_INSERT_ID() AS insertID.
function db_call_insert_id(mysqli $conn, string $sql, string $types = '', array $params = []): int
{
    $stmt = $conn->prepare($sql);

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    db_clear_more_results($conn);

    return (int) ($row['insertID'] ?? 0);
}

?>
