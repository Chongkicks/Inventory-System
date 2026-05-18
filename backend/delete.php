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

// Use POST data only; this route soft-deletes records and should not accept GET.
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestData = $requestMethod === 'POST' ? $_POST : [];

// Preserve pagination/search filters so the user returns to the same product list.
$id = security_int($requestData['productID'] ?? 0, 0);
$productsPage = pagination_current_page($requestData['products_page'] ?? 1);
$productSearch = security_clean_text($requestData['product_search'] ?? '', 120);
$productsPerPage = security_int($requestData['products_per_page'] ?? 5, 1, 100);
$transactionsPage = pagination_current_page($requestData['transactions_page'] ?? 1);

$redirectBase = '../index.php';
$listAnchor = 'products';

// Shared response helper for AJAX and redirect form submissions.
$respond = function (string $message, bool $ok = false, array $extra = []) use ($redirectBase, $listAnchor, $productsPage, $transactionsPage, $productsPerPage, $productSearch) {
    $redirectParams = [
        'message' => $message,
        'products_page' => $productsPage,
        'transactions_page' => $transactionsPage,
        'products_per_page' => $productsPerPage
    ];

    if ($productSearch !== '') {
        $redirectParams['product_search'] = $productSearch;
    }

    action_response($message, pagination_url($redirectBase, $redirectParams, $listAnchor), $ok, $extra);
};

// Require a valid product id and request method before checking CSRF.
if ($requestMethod !== 'POST' || $id <= 0) {
    $respond('Invalid product');
}

if (!csrf_is_valid()) {
    $respond('Security token expired. Please try again.');
}

$lookup = $conn->prepare("SELECT productName, category FROM products WHERE productID = ? AND dateDeleted IS NULL");
$lookup->bind_param("i", $id);
$lookup->execute();
$product = $lookup->get_result()->fetch_assoc();

// Do not log/delete twice when the product is already soft-deleted.
if (!$product) {
    $respond('Product not found');
}

// Soft-delete the product and remove its size rows through the procedure.
db_call($conn, "CALL sp_deleteProduct(?)", "i", [$id]);

$type = 'deleted';
$change = 0;
$quantityAfter = 0;
$remarks = 'Product deleted';
$productName = $product['productName'];
// Keep an audit trail for deleted products.
db_call($conn, "CALL sp_insertTransaction(?, ?, ?, ?, ?, ?)", "issiis", [$id, $productName, $type, $change, $quantityAfter, $remarks]);

require_once __DIR__ . '/pusher.php';
// Tell other open screens to refresh product lists.
trigger_inventory_notification([
    'title' => 'Product deleted',
    'message' => $productName . ' was removed from inventory.',
    'level' => 'danger',
    'type' => 'deleted',
    'productID' => $id,
    'quantityAfter' => $quantityAfter
], inventory_request_socket_id());

$respond('Product deleted', true);
?>
