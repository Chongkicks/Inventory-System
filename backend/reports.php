<?php
require_once __DIR__ . '/auth.php';
include __DIR__ . '/db.php';
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/realtime-client.php';

auth_sync_session_user($conn);
require_login('../frontend/login.html');
$isAdmin = auth_is_admin();

// Escape output before rendering values inside HTML.
function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Convert stored transaction types like stock_out to display labels.
function transaction_label($type)
{
    return ucwords(str_replace('_', ' ', $type));
}

// Add a plus sign for positive stock changes.
function quantity_change_label($value)
{
    $quantity = (int) $value;

    return $quantity > 0 ? '+' . $quantity : (string) $quantity;
}

// Accept datetime-local values with or without seconds.
function parse_datetime_local($value)
{
    $value = trim((string) $value);

    if ($value === '') {
        return null;
    }

    foreach (['Y-m-d\TH:i:s', 'Y-m-d\TH:i'] as $format) {
        $dateTime = DateTimeImmutable::createFromFormat($format, $value);

        if ($dateTime instanceof DateTimeImmutable) {
            return $dateTime;
        }
    }

    return null;
}

// Convert a datetime-local query value into a SQL datetime string.
function transaction_query_value($value)
{
    $value = trim((string) $value);

    if ($value === '') {
        return null;
    }

    $dateTime = parse_datetime_local($value);

    if (!$dateTime) {
        return null;
    }

    return $dateTime->format('Y-m-d H:i:s');
}

// Run history queries with optional bound parameters.
function run_transaction_query(mysqli $conn, string $sql, array $params = [])
{
    $paramCount = count($params);

    if ($paramCount === 0) {
        return $conn->query($sql);
    }

    $stmt = $conn->prepare($sql);

    $types = str_repeat('s', $paramCount);
    $bindParams = array_merge([$types], $params);
    $refs = [];

    foreach ($bindParams as $key => $value) {
        $refs[$key] = &$bindParams[$key];
    }

    call_user_func_array([$stmt, 'bind_param'], $refs);
    $stmt->execute();

    return $stmt->get_result();
}

// Read history filters from the query string and normalize pagination.
$historyPerPage = 5;
$historyPageRequest = pagination_current_page($_GET['transactions_page'] ?? 1);
$historyStartInput = trim($_GET['transaction_start'] ?? '');
$historyEndInput = trim($_GET['transaction_end'] ?? '');
$historySearchInput = trim($_GET['transaction_search'] ?? '');

$historyConditions = ['dateDeleted IS NULL'];
$historyParams = [];

// Build a WHERE clause only for filters the user actually filled in.
$historyStartSql = transaction_query_value($historyStartInput);

if ($historyStartSql !== null) {
    $historyConditions[] = 'dateCreated >= ?';
    $historyParams[] = $historyStartSql;
}

$historyEndSql = transaction_query_value($historyEndInput);

if ($historyEndSql !== null) {
    $historyConditions[] = 'dateCreated <= ?';
    $historyParams[] = $historyEndSql;
}

if ($historySearchInput !== '') {
    $historyConditions[] = '(productName LIKE ? OR remarks LIKE ? OR type LIKE ?)';
    $searchPattern = '%' . $historySearchInput . '%';
    $historyParams[] = $searchPattern;
    $historyParams[] = $searchPattern;
    $historyParams[] = $searchPattern;
}

$historyWhere = implode(' AND ', $historyConditions);
$totalHistory = (int) run_transaction_query($conn, "SELECT COUNT(*) AS total FROM transactions WHERE {$historyWhere}", $historyParams)->fetch_assoc()['total'];

// Fetch the current history page after the total count clamps the page number.
[$historyPage, $historyTotalPages, $historyOffset] = pagination_page_state($historyPageRequest, $totalHistory, $historyPerPage);

$historySql = "SELECT * FROM transactions WHERE {$historyWhere} ORDER BY dateCreated DESC, transactionID DESC LIMIT {$historyPerPage} OFFSET {$historyOffset}";
$history = run_transaction_query($conn, $historySql, $historyParams);
$historyPagerQuery = pagination_query_params(['transactions_page', 'report_products_page']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Marketsmart Inventory System | Inventory History</title>
    <link rel="stylesheet" href="../frontend/style.css?v=20260517-16">
</head>
<body class="reports-page">
    <header class="topbar">
        <div>
            <p class="eyebrow">Marketsmart Inventory System</p>
            <h1>Inventory History</h1>
        </div>
        <nav aria-label="Primary">
            <a class="nav-link" href="../index.php">Dashboard</a>
            <a class="nav-link" href="../index.php?stock=1">Stock</a>
            <?php if ($isAdmin): ?>
                <a class="nav-link" href="../admin/add-product.php">Add Products</a>
                <a class="nav-link" href="../admin/users.php">Users</a>
            <?php endif; ?>
            <a class="nav-link is-active" href="reports.php" aria-current="page">History</a>
            <a class="nav-link profile-link" href="../profile.php">
                <span class="profile-avatar" aria-hidden="true"><?= e(auth_user_initials()) ?></span>
                <span>Profile</span>
            </a>
            <a class="nav-link" href="logout.php" data-logout-link>Logout</a>
        </nav>
    </header>

    <main>
        <section class="layout reports-layout">
            <div class="panel" id="history">
                <div class="panel-header">
                    <div>
                        <p class="eyebrow">History</p>
                        <h2>Inventory History</h2>
                    </div>
                    <?php if ($isAdmin): ?>
                        <button onclick="window.print()">Print</button>
                    <?php endif; ?>
                </div>
                <div class="transaction-toolbar">
                    <form class="transaction-filters" action="reports.php" method="GET" aria-label="History search and date filter">
                        <input type="hidden" name="transactions_page" value="1">
                        <div class="filter-row">
                            <label>
                                Search
                                <input type="search" name="transaction_search" placeholder="Product, type, or remarks" value="<?= e($historySearchInput) ?>">
                            </label>
                            <label>
                                From Date & Time
                                <input type="datetime-local" name="transaction_start" value="<?= e($historyStartInput) ?>">
                            </label>
                            <label>
                                To Date & Time
                                <input type="datetime-local" name="transaction_end" value="<?= e($historyEndInput) ?>">
                            </label>
                        </div>
                        <div class="filter-actions">
                            <button type="submit" class="button secondary">Filter</button>
                            <?php if ($historyStartInput !== '' || $historyEndInput !== '' || $historySearchInput !== ''): ?>
                                <a class="button secondary" href="<?= e(pagination_url('reports.php', ['transactions_page' => 1], 'history')) ?>">Clear</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
                <div class="table-wrap">
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Product</th>
                                <th>Type</th>
                                <th>Change</th>
                                <th>Balance</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($history->num_rows > 0): ?>
                                <?php while ($row = $history->fetch_assoc()): ?>
                                    <tr>
                                        <td><?= e(date('M d, Y h:i A', strtotime($row['dateCreated']))) ?></td>
                                        <td><?= e($row['productName']) ?></td>
                                        <td><?= e(transaction_label($row['type'])) ?></td>
                                        <td><?= e(quantity_change_label($row['quantityChange'])) ?></td>
                                        <td><?= e($row['quantityAfter'] ?? '-') ?></td>
                                        <td><?= e($row['remarks'] ?? '') ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td class="empty-state" colspan="6">No history found for the selected date range.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?= pagination_render('reports.php', 'transactions_page', $historyPage, $totalHistory, $historyPerPage, $historyPagerQuery, 'records', 'history') ?>
            </div>
        </section>
    </main>
    <?php realtime_client_scripts(); ?>
    <script src="../frontend/nav.js?v=20260518-4"></script>
</body>
</html>
