<?php
require_once 'auth.php';
include 'db.php';
auth_sync_session_user($conn);
require_login('../frontend/login.html');
require_admin('../index.php');
require_once __DIR__ . '/../includes/product-categories.php';
require_once __DIR__ . '/../includes/product-sizes.php';
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/db-procedures.php';

$allowedCategories = product_categories();

// Kunin at i-normalize ang submitted product details mula sa form.
$name = security_clean_text($_POST['productName'] ?? '', 255);
$category = security_clean_text($_POST['category'] ?? '', 120);
$sizeQuantities = (array) ($_POST['sizeQuantities'] ?? []);
$qty = security_int($_POST['quantity'] ?? 0, 0, 999999);
$price = security_decimal($_POST['price'] ?? 0);
$returnPage = security_clean_text($_POST['return_page'] ?? 'add-product', 40);

// Limitahan ang pwedeng balikang page pagkatapos mag-add ng product.
$returnTargets = [
    'add-product' => ['path' => '../admin/add-product.php', 'anchor' => ''],
    'index' => ['path' => '../index.php', 'anchor' => 'products'],
];

if (!array_key_exists($returnPage, $returnTargets)) {
    $returnPage = 'add-product';
}

$redirectBase = $returnTargets[$returnPage]['path'];
$redirectAnchor = $returnTargets[$returnPage]['anchor'];

// Gumawa ng reusable response helper na may redirect fallback at JSON support.
$respond = static function (string $message, bool $ok = false, array $extra = []) use ($redirectBase, $redirectAnchor) {
    action_response($message, pagination_url($redirectBase, ['message' => $message], $redirectAnchor), $ok, $extra);
};

// Tanggapin lang ang valid POST request para iwas CSRF at accidental GET writes.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !csrf_is_valid()) {
    $respond('Security token expired. Please try again.');
}

// Siguraduhing kumpleto at valid ang required product fields bago mag-save.
if ($name === '') {
    $respond('Product name is required');
}

if (!in_array($category, $allowedCategories, true)) {
    $respond('Invalid category');
}

$duplicateStmt = $conn->prepare("SELECT productID FROM products WHERE dateDeleted IS NULL AND LOWER(TRIM(productName)) = LOWER(?) AND LOWER(TRIM(category)) = LOWER(?) LIMIT 1");
$duplicateStmt->bind_param("ss", $name, $category);
$duplicateStmt->execute();

// Iwas duplicate active product sa parehong category.
if ($duplicateStmt->get_result()->num_rows > 0) {
    $respond('Product already exists');
}

// Para sa size-tracked categories, gamitin ang total ng lahat ng entered sizes.
if (is_size_tracked_category($category)) {
    $qty = uniform_size_total($sizeQuantities);
} else {
    $sizeQuantities = [];
}

// I-save ang main product record gamit ang stored procedure.
try {
    $productId = db_call_insert_id($conn, "CALL sp_insertProduct(?, ?, ?, ?)", "ssid", [$name, $category, $qty, $price]);
} catch (mysqli_sql_exception $exception) {
    $respond(str_contains($exception->getMessage(), 'Product already exists') ? 'Product already exists' : 'Unable to add product');
}

if (is_size_tracked_category($category)) {
    // I-save lang ang per-size rows kapag size-tracked ang category.
    save_uniform_size_quantities($conn, $productId, $sizeQuantities);
}

// Mag-log ng transaction para may history ng bagong product entry.
$type = 'created';
$remarks = 'Product added';
db_call($conn, "CALL sp_insertTransaction(?, ?, ?, ?, ?, ?)", "issiis", [$productId, $name, $type, $qty, $qty, $remarks]);

// Trigger realtime notification pagkatapos ma-save ang product.
require_once __DIR__ . '/pusher.php';
trigger_inventory_notification([
    'title' => 'Product added',
    'message' => $name . ' was added with ' . $qty . ' item(s).',
    'level' => 'success',
    'type' => 'created',
    'productID' => $productId,
    'quantityAfter' => $qty
], inventory_request_socket_id());

$respond('Product added', true, ['reset' => true]);
?>
