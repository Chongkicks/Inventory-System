<?php
require_once 'auth.php';
include 'db.php';
auth_sync_session_user($conn);
require_login('../frontend/login.html');
require_once __DIR__ . '/../includes/product-sizes.php';
require_once __DIR__ . '/../includes/uniform-sizes.php';
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/db-procedures.php';

// Sanitize the submitted stock transaction values.
$productId = security_int($_POST['productID'] ?? 0, 0);
$type = security_clean_text($_POST['type'] ?? '', 30);
$quantity = security_int($_POST['quantity'] ?? 0, 0, 999999);
$uniformSize = security_clean_text($_POST['uniformSize'] ?? '', 10);
$sizeQuantityChanges = isset($_POST['sizeQuantities']) && is_array($_POST['sizeQuantities'])
    ? normalize_uniform_size_quantities((array) $_POST['sizeQuantities'])
    : [];
$remarks = security_clean_text($_POST['remarks'] ?? '', 255);
$productsPage = pagination_current_page($_POST['products_page'] ?? 1);

// Stock actions can return JSON to AJAX forms or redirect back to the modal.
$respondStock = function (string $message, bool $ok = false, array $extra = []) use ($productsPage) {
    $params = [
        'message' => $message,
        'products_page' => $productsPage,
        'stock' => 1
    ];

    action_response($message, pagination_url('../index.php', $params, 'products'), $ok, $extra);
};

// All stock changes must be submitted through a valid form token.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !csrf_is_valid()) {
    $respondStock('Security token expired. Please try again.');
}

if ($productId <= 0 || !in_array($type, ['stock_in', 'stock_out'], true)) {
    $respondStock('Invalid stock transaction');
}

// Staff accounts can record sales/stock-out only; stock-in is admin-only.
if ($type === 'stock_in' && !auth_is_admin()) {
    $respondStock('Stock-in is for admin accounts only');
}

$stmt = $conn->prepare("SELECT * FROM products WHERE productID = ? AND dateDeleted IS NULL");
$stmt->bind_param("i", $productId);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();

if (!$product) {
    $respondStock('Product not found');
}

$productCategory = trim((string) ($product['category'] ?? ''));
$currentQuantity = (int) $product['quantity'];

if (is_size_tracked_category($productCategory)) {
    // Uniform products update individual size balances first, then recompute total stock.
    $sizeQuantities = fetch_uniform_size_quantities($conn, $productId);
    $changedSizes = [];

    if (!empty($sizeQuantityChanges)) {
        foreach ($sizeQuantityChanges as $sizeLabel => $quantityChange) {
            $quantityChange = max(0, (int) $quantityChange);

            if ($quantityChange <= 0) {
                continue;
            }

            $signedChange = $type === 'stock_in' ? $quantityChange : -$quantityChange;
            $newSizeQuantity = (int) ($sizeQuantities[$sizeLabel] ?? 0) + $signedChange;

            // Prevent sales from taking a size below zero.
            if ($newSizeQuantity < 0) {
                $respondStock('Stock-out quantity exceeds available stock for size ' . $sizeLabel);
            }

            $sizeQuantities[$sizeLabel] = $newSizeQuantity;
            $changedSizes[] = [
                'label' => $sizeLabel,
                'change' => $signedChange,
                'balance' => $newSizeQuantity
            ];
        }
    } elseif (in_array($uniformSize, uniform_size_labels(), true) && $quantity > 0) {
        $signedChange = $type === 'stock_in' ? $quantity : -$quantity;
        $newSizeQuantity = (int) ($sizeQuantities[$uniformSize] ?? 0) + $signedChange;

        if ($newSizeQuantity < 0) {
            $respondStock('Stock-out quantity exceeds available stock');
        }

        $sizeQuantities[$uniformSize] = $newSizeQuantity;
        $changedSizes[] = [
            'label' => $uniformSize,
            'change' => $signedChange,
            'balance' => $newSizeQuantity
        ];
    }

    if (empty($changedSizes)) {
        $respondStock('Enter at least one size quantity');
    }

    $newQuantity = uniform_size_total($sizeQuantities);
    save_uniform_size_quantities($conn, $productId, $sizeQuantities);
} else {
    // Non-size products use a single signed quantity change.
    $uniformSize = '';
    $quantity = max(0, $quantity);

    if ($quantity <= 0) {
        $respondStock('Quantity is required');
    }

    $change = $type === 'stock_in' ? $quantity : -$quantity;
    $newQuantity = $currentQuantity + $change;

    if ($newQuantity < 0) {
        $respondStock('Stock-out quantity exceeds available stock');
    }

    $transactionBalance = $newQuantity;
}

// Store the new total stock on the products table.
db_call($conn, "CALL sp_updateProductQuantity(?, ?)", "ii", [$productId, $newQuantity]);

$productName = $product['productName'];

if (is_size_tracked_category($productCategory)) {
    // Log one history row per changed size for clearer stock audit trails.
    foreach ($changedSizes as $changedSize) {
        $stockLabel = $productName . ' - ' . $changedSize['label'];
        $change = (int) $changedSize['change'];
        $transactionBalance = (int) $changedSize['balance'];
        $transactionRemarks = $remarks !== '' ? $remarks : 'Total stock: ' . $newQuantity;
        db_call($conn, "CALL sp_insertTransaction(?, ?, ?, ?, ?, ?)", "issiis", [$productId, $stockLabel, $type, $change, $transactionBalance, $transactionRemarks]);
    }
} else {
    // Flat-stock products need only one history row.
    db_call($conn, "CALL sp_insertTransaction(?, ?, ?, ?, ?, ?)", "issiis", [$productId, $productName, $type, $change, $transactionBalance, $remarks]);
}

$totalChange = $newQuantity - $currentQuantity;
$formattedChange = ($totalChange > 0 ? '+' : '') . $totalChange;
$stockLabel = $type === 'stock_in' ? 'Stock in' : 'Stock out';
$notificationTitle = $stockLabel . ' saved';
$notificationLevel = 'success';
$notificationMessage = $productName . ': ' . $stockLabel . ' ' . $formattedChange . ', balance ' . $newQuantity . '.';

require_once __DIR__ . '/pusher.php';
// Broadcast the saved stock change so open tables/selects can refresh.
trigger_inventory_notification([
    'title' => $notificationTitle,
    'message' => $notificationMessage,
    'level' => $notificationLevel,
    'type' => $type,
    'productID' => $productId,
    'quantityChange' => $totalChange,
    'quantityAfter' => $newQuantity
], inventory_request_socket_id());

$respondStock('Stock transaction saved', true, ['closeModal' => true, 'reset' => true]);
?>
