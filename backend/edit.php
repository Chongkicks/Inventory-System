<?php
require_once 'auth.php';
include 'db.php';
auth_sync_session_user($conn);
require_login('../frontend/login.html');
require_admin('../index.php');
require_once __DIR__ . '/../includes/product-sizes.php';
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/db-procedures.php';

// Sanitize submitted edit data and preserve the current dashboard page/search state.
$id = security_int($_POST['productID'] ?? 0, 0);
$name = security_clean_text($_POST['productName'] ?? '', 255);
$price = security_decimal($_POST['price'] ?? 0);
$productsPage = pagination_current_page($_POST['products_page'] ?? 1);
$productSearch = security_clean_text($_POST['product_search'] ?? '', 120);
$productsPerPage = security_int($_POST['products_per_page'] ?? 8, 1, 100);
$transactionsPage = pagination_current_page($_POST['transactions_page'] ?? 1);
$redirectBase = '../index.php';
$listAnchor = 'products';
$formAnchor = 'products';

// One response path supports both classic redirects and AJAX modals.
$respond = function (string $message = 'Invalid product', bool $ok = false, array $extra = []) use ($redirectBase, $formAnchor, $productsPage, $transactionsPage, $productsPerPage, $productSearch) {
    $redirectParams = [
        'message' => $message,
        'products_page' => $productsPage,
        'transactions_page' => $transactionsPage,
        'products_per_page' => $productsPerPage
    ];

    if ($productSearch !== '') {
        $redirectParams['product_search'] = $productSearch;
    }

    action_response($message, pagination_url($redirectBase, $redirectParams, $formAnchor), $ok, $extra);
};

// Product edits must come from a valid submitted form.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !csrf_is_valid()) {
    $respond('Security token expired. Please try again.');
}

if ($id <= 0 || $name === '') {
    $respond();
}

$lookup = $conn->prepare("SELECT category, quantity FROM products WHERE productID = ? AND dateDeleted IS NULL LIMIT 1");
$lookup->bind_param("i", $id);
$lookup->execute();
$product = $lookup->get_result()->fetch_assoc();

// Stop when the selected product was deleted or does not exist.
if (!$product) {
    $respond('Product not found');
}

$productCategory = trim((string) ($product['category'] ?? ''));
$duplicateStmt = $conn->prepare("SELECT productID FROM products WHERE productID <> ? AND dateDeleted IS NULL AND LOWER(TRIM(productName)) = LOWER(?) AND LOWER(TRIM(category)) = LOWER(?) LIMIT 1");
$duplicateStmt->bind_param("iss", $id, $name, $productCategory);
$duplicateStmt->execute();

// Product names must remain unique inside the same category.
if ($duplicateStmt->get_result()->num_rows > 0) {
    $respond('Product already exists');
}

$qty = (int) ($product['quantity'] ?? 0);

if (is_size_tracked_category($productCategory)) {
    // Size-tracked products compute stock from product_sizes, not the products.quantity input.
    $qty = uniform_size_total(fetch_uniform_size_quantities($conn, $id));
}

// Save details through the stored procedure so DB-level checks still run.
try {
    db_call($conn, "CALL sp_updateProductDetails(?, ?, ?)", "isd", [$id, $name, $price]);
} catch (mysqli_sql_exception $exception) {
    $respond(str_contains($exception->getMessage(), 'Product already exists') ? 'Product already exists' : 'Unable to update product');
}

$type = 'updated';
$change = 0;
$remarks = 'Product details updated';
// Log a zero-quantity transaction so edits still appear in history.
db_call($conn, "CALL sp_insertTransaction(?, ?, ?, ?, ?, ?)", "issiis", [$id, $name, $type, $change, $qty, $remarks]);

require_once __DIR__ . '/pusher.php';
// Notify open dashboards after the product details change.
trigger_inventory_notification([
    'title' => 'Product updated',
    'message' => $name . ' details were updated.',
    'level' => 'info',
    'type' => 'updated',
    'productID' => $id,
    'quantityAfter' => $qty
], inventory_request_socket_id());

$respond('Product updated', true, ['closeModal' => true]);
?>
