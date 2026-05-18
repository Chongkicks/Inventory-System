<?php
require_once __DIR__ . '/includes/auth.php';

// Protect the dashboard before loading inventory data.
require_login('frontend/login.html');

include __DIR__ . '/backend/db.php';
require_once __DIR__ . '/includes/product-categories.php';
require_once __DIR__ . '/includes/product-sizes.php';
require_once __DIR__ . '/includes/pagination.php';
require_once __DIR__ . '/includes/realtime-client.php';
require_once __DIR__ . '/includes/security.php';

auth_sync_session_user($conn);
require_login('frontend/login.html');

// Basic page state used by navigation, role checks, search, and pagination.
$isAdmin = auth_is_admin();
$roleLabel = auth_user_role_label();
$productsPerPage = 5;
$productsPageRequest = pagination_current_page($_GET['products_page'] ?? 1);
$productSearch = trim($_GET['product_search'] ?? '');
$productCategories = product_categories();
$uniformSizes = uniform_size_labels();
$editingProductId = $isAdmin ? max(0, (int) ($_GET['edit'] ?? 0)) : 0;
$editingProduct = null;
$editingUniformSizeQuantities = array_fill_keys($uniformSizes, 0);
$editingQuantity = 0;
$editingPrice = 0.0;

// Summary metrics shown by the dashboard.
$totalProducts = (int) $conn->query("SELECT COUNT(*) AS total FROM products WHERE dateDeleted IS NULL")->fetch_assoc()['total'];
$totalQuantity = (int) $conn->query("SELECT COALESCE(SUM(quantity), 0) AS total FROM products WHERE dateDeleted IS NULL")->fetch_assoc()['total'];
$inventoryValue = (float) $conn->query("SELECT COALESCE(SUM(quantity * price), 0) AS total FROM products WHERE dateDeleted IS NULL")->fetch_assoc()['total'];

// If an admin opens ?edit=ID, prefill the modal with that product's current values.
if ($editingProductId > 0) {
    $editingStmt = $conn->prepare("SELECT * FROM products WHERE productID = ? AND dateDeleted IS NULL LIMIT 1");
    $editingStmt->bind_param("i", $editingProductId);
    $editingStmt->execute();
    $editingProduct = $editingStmt->get_result()->fetch_assoc() ?: null;

    if ($editingProduct && is_size_tracked_category($editingProduct['category'] ?? '')) {
        $editingUniformSizeQuantities = fetch_uniform_size_quantities($conn, (int) $editingProduct['productID']);
    }

    if ($editingProduct) {
        $editingQuantity = is_size_tracked_category($editingProduct['category'] ?? '')
            ? uniform_size_total($editingUniformSizeQuantities)
            : (int) $editingProduct['quantity'];
        $editingPrice = (float) ($editingProduct['price'] ?? 0);
    }
}

$editingTotalCost = $editingQuantity * $editingPrice;

// Count rows for the paginated product table, respecting search filters.
if ($productSearch !== '') {
    $productCountStmt = $conn->prepare("SELECT COUNT(*) AS total FROM products WHERE dateDeleted IS NULL AND (productName LIKE ? OR category LIKE ?)");
    $productLike = '%' . $productSearch . '%';
    $productCountStmt->bind_param("ss", $productLike, $productLike);
    $productCountStmt->execute();
    $productTableTotal = (int) $productCountStmt->get_result()->fetch_assoc()['total'];
} else {
    $productTableTotal = $totalProducts;
}

[$productsPage, $productsTotalPages, $productsOffset] = pagination_page_state($productsPageRequest, $productTableTotal, $productsPerPage);

// Fetch only the products needed for the current table page.
if ($productSearch !== '') {
    $productsStmt = $conn->prepare("SELECT * FROM products WHERE dateDeleted IS NULL AND (productName LIKE ? OR category LIKE ?) ORDER BY productName ASC LIMIT {$productsPerPage} OFFSET {$productsOffset}");
    $productsStmt->bind_param("ss", $productLike, $productLike);
    $productsStmt->execute();
    $products = $productsStmt->get_result();
} else {
    $products = $conn->query("SELECT * FROM products WHERE dateDeleted IS NULL ORDER BY productName ASC LIMIT {$productsPerPage} OFFSET {$productsOffset}");
}
$productPagerQuery = pagination_query_params(['products_page', 'edit', 'stock']);

// Escape helper for all HTML output.
function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Convert a products row into the dashboard card/select shape.
function dashboard_product_view(mysqli $conn, array $row)
{
    $productId = (int) ($row['productID'] ?? 0);
    $productName = trim((string) ($row['productName'] ?? ''));
    $category = trim((string) ($row['category'] ?? ''));
    $price = (float) ($row['price'] ?? 0);
    $baseQuantity = max(0, (int) ($row['quantity'] ?? 0));
    $sizeQuantities = [];
    $stockChips = [];

    if (is_size_tracked_category($category)) {
        $sizeQuantities = fetch_uniform_size_quantities($conn, $productId);
        $baseQuantity = uniform_size_total($sizeQuantities);
        $stockChips = uniform_size_chip_labels($sizeQuantities);
    }

    return [
        'productID' => $productId,
        'productName' => $productName,
        'category' => $category,
        'price' => $price,
        'quantity' => $baseQuantity,
        'inventoryValue' => $baseQuantity * $price,
        'sizeQuantities' => $sizeQuantities,
        'stockChips' => $stockChips,
        'actionLabel' => is_size_tracked_category($category) ? 'Manage Sizes' : 'Edit',
        'searchText' => strtolower($productName . ' ' . $category),
    ];
}

// Apply search and category filters to the in-memory dashboard product list.
function dashboard_filter_products(array $products, string $search, string $categoryFilter)
{
    $search = trim($search);
    $categoryFilter = trim($categoryFilter);

    return array_values(array_filter($products, function (array $product) use ($search, $categoryFilter) {
        if ($categoryFilter !== '' && $categoryFilter !== 'All' && $product['category'] !== $categoryFilter) {
            return false;
        }

        if ($search === '') {
            return true;
        }

        $searchLower = strtolower($search);

        return str_contains($product['searchText'], $searchLower);
    }));
}

// Sort dashboard products by the selected dropdown option.
function dashboard_sort_products(array &$products, string $sortBy)
{
    usort($products, function (array $left, array $right) use ($sortBy) {
        switch ($sortBy) {
            case 'Category':
                $comparison = strcasecmp($left['category'], $right['category']);
                if ($comparison !== 0) {
                    return $comparison;
                }

                return strcasecmp($left['productName'], $right['productName']);

            case 'Stock':
                if ($left['quantity'] === $right['quantity']) {
                    return strcasecmp($left['productName'], $right['productName']);
                }

                return $right['quantity'] <=> $left['quantity'];

            case 'Name':
            default:
                return strcasecmp($left['productName'], $right['productName']);
        }
    });
}

// Validate filter/sort query values before using them.
$allowedFilterCategories = array_merge(['All'], $productCategories);
$categoryFilter = trim($_GET['category_filter'] ?? 'All');

if (!in_array($categoryFilter, $allowedFilterCategories, true)) {
    $categoryFilter = 'All';
}

$allowedSortBy = ['Name', 'Category', 'Stock'];
$sortBy = trim($_GET['sort_by'] ?? 'Name');

if (!in_array($sortBy, $allowedSortBy, true)) {
    $sortBy = 'Name';
}

$dashboardPerPageOptions = [8, 12, 16, 24];
$dashboardPerPage = (int) ($_GET['dashboard_per_page'] ?? 8);

if (!in_array($dashboardPerPage, $dashboardPerPageOptions, true)) {
    $dashboardPerPage = 8;
}

// Build the full dashboard list once, including size totals for uniform products.
$inventoryPageRequest = pagination_current_page($_GET['inventory_page'] ?? 1);

$allDashboardProducts = [];
$productsResult = $conn->query("SELECT * FROM products WHERE dateDeleted IS NULL ORDER BY productName ASC");

while ($row = $productsResult->fetch_assoc()) {
    $allDashboardProducts[] = dashboard_product_view($conn, $row);
}

// Products at or below this quantity are marked as low stock in the table.
$lowStockThreshold = 10;

$stockProductOptions = $allDashboardProducts;
dashboard_sort_products($stockProductOptions, 'Name');

// Filter/sort the dashboard cards separately from the small product table.
$filteredDashboardProducts = dashboard_filter_products($allDashboardProducts, $productSearch, $categoryFilter);
dashboard_sort_products($filteredDashboardProducts, $sortBy);

$inventoryProducts = $filteredDashboardProducts;

[$inventoryPage, $inventoryTotalPages, $inventoryOffset] = pagination_page_state($inventoryPageRequest, count($inventoryProducts), $dashboardPerPage);

$inventoryPageProducts = array_slice($inventoryProducts, $inventoryOffset, $dashboardPerPage);

$inventoryPagerQuery = pagination_query_params(['inventory_page']);

// Modal close URLs preserve the current product list page and anchor.
$productFormCloseUrl = pagination_url('index.php', array_merge($productPagerQuery, ['products_page' => $productsPage]), 'products');
$productEditorOpen = $isAdmin && $editingProduct !== null;
$stockModalOpen = isset($_GET['stock']);
$stockFormCloseUrl = pagination_url('index.php', array_merge($productPagerQuery, ['products_page' => $productsPage]), 'products');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Marketsmart Inventory System | Dashboard</title>
    <link rel="stylesheet" href="frontend/style.css?v=20260517-16">
</head>
<body class="dashboard-page<?= ($productEditorOpen || $stockModalOpen) ? ' modal-open' : '' ?>">
    <header class="topbar">
        <div>
            <p class="eyebrow">Marketsmart Inventory System</p>
            <h1>Dashboard</h1>
            <p class="muted">Welcome, <?= e($_SESSION['fullName'] ?? $_SESSION['username'] ?? 'User') ?> (<?= e($roleLabel) ?>)</p>
        </div>
        <nav aria-label="Primary">
            <a class="nav-link" href="index.php?stock=1" data-open-stock-modal>Stock</a>
            <?php if ($isAdmin): ?>
                <a class="nav-link" href="admin/add-product.php">Add Products</a>
                <a class="nav-link" href="admin/users.php">Users</a>
            <?php endif; ?>
            <a class="nav-link" href="backend/reports.php">History</a>
            <a class="nav-link profile-link" href="profile.php">
                <span class="profile-avatar" aria-hidden="true"><?= e(auth_user_initials()) ?></span>
                <span>Profile</span>
            </a>
            <a class="nav-link" href="backend/logout.php" data-logout-link>Logout</a>
        </nav>
    </header>

    <main>
        <section class="layout">
            <div class="panel dashboard-products-panel" id="products">
                <div class="panel-header dashboard-products-header">
                    <div>
                        <h2><?= $editingProduct ? 'Edit Product' : 'Products' ?></h2>
                    </div>
                    <form class="product-search" action="index.php" method="GET" aria-label="Search products" data-live-search-form>
                        <input type="hidden" name="products_page" value="1">
                        <label class="search-field">
                            <span>Search</span>
                            <input type="search" name="product_search" value="<?= e($productSearch) ?>" placeholder="Product name or category" autocomplete="off" data-live-search-input>
                        </label>
                        <div class="search-actions">
                            <button type="submit" class="button secondary">Search</button>
                            <?php if ($productSearch !== ''): ?>
                                <a class="button secondary" href="<?= e(pagination_url('index.php', ['products_page' => 1], 'products')) ?>">Clear</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <?php if ($isAdmin): ?>
                    <div data-product-modal<?= $productEditorOpen ? '' : ' hidden' ?>>
                        <a class="product-modal-backdrop" href="<?= e($productFormCloseUrl) ?>" aria-label="Close product editor" tabindex="-1" data-product-modal-backdrop></a>
                        <form id="product-form" class="form-grid add-product-form is-modal" action="backend/edit.php" method="POST" role="dialog" aria-modal="true" aria-labelledby="product-form-title" data-ajax-form>
                        <?= csrf_field() ?>
                        <input type="hidden" name="products_page" value="<?= $productsPage ?>">
                        <?php if ($productSearch !== ''): ?>
                            <input type="hidden" name="product_search" value="<?= e($productSearch) ?>">
                        <?php endif; ?>
                        <input type="hidden" name="productID" value="<?= (int) ($editingProduct['productID'] ?? 0) ?>">
                        <div class="panel-header product-editor-header">
                            <div>
                                <h2 id="product-form-title">Edit Product</h2>
                            </div>
                        </div>
                        <label>
                            Product Name
                            <input type="text" name="productName" value="<?= e($editingProduct['productName'] ?? '') ?>" required>
                        </label>
                        <label>
                            Category
                            <select name="category" required disabled>
                                <option value="">Select category</option>
                                <?php foreach ($productCategories as $categoryOption): ?>
                                    <option value="<?= e($categoryOption) ?>" <?= (($editingProduct['category'] ?? '') === $categoryOption) ? 'selected' : '' ?>>
                                        <?= e($categoryOption) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="quantity-field" data-product-quantity-field>
                            Current Stock
                            <input type="number" name="quantity" min="0" value="<?= (int) $editingQuantity ?>" required disabled>
                        </label>
                        <div class="uniform-size-panel" data-uniform-size-panel hidden>
                            <div class="uniform-size-grid">
                                <?php foreach ($uniformSizes as $sizeLabel): ?>
                                    <label class="uniform-size-field">
                                        <span>Size <?= e($sizeLabel) ?></span>
                                        <input
                                            type="number"
                                            name="sizeQuantities[<?= e($sizeLabel) ?>]"
                                            min="0"
                                            value="<?= (int) ($editingUniformSizeQuantities[$sizeLabel] ?? 0) ?>"
                                            placeholder="0"
                                            data-uniform-size-input
                                            disabled
                                        >
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <div class="uniform-size-summary" data-uniform-size-summary>
                                <span>Total qty: <strong data-uniform-total-qty><?= (int) $editingQuantity ?></strong></span>
                                <span>Total cost: <strong data-uniform-total-cost>&#8369;<?= number_format($editingTotalCost, 2) ?></strong></span>
                            </div>
                        </div>
                        <label class="price-field">
                            Price
                            <div class="currency-input">
                                <span class="currency-prefix">&#8369;</span>
                                <input type="number" name="price" min="0" step="0.01" value="<?= number_format($editingPrice, 2, '.', '') ?>" required data-product-price-input>
                            </div>
                        </label>
                        <div class="form-actions">
                            <button type="submit">Update Product</button>
                            <a class="button secondary" href="<?= e($productFormCloseUrl) ?>" data-product-modal-close>Cancel</a>
                        </div>
                        </form>
                    </div>
                <?php endif; ?>

                <div class="table-wrap dashboard-products-table-wrap">
                    <table class="products-table dashboard-products-table">
                        <thead>
                            <tr>
                                <th>Product Name</th>
                                <th>Category</th>
                                <th>Current Stock</th>
                                <th>Stock Level</th>
                                <?php if ($isAdmin): ?>
                                    <th>Action</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($products->num_rows > 0): ?>
                                <?php while ($row = $products->fetch_assoc()): ?>
                                    <?php
                                    $productCategory = trim((string) ($row['category'] ?? ''));
                                    $currentStockLabel = (string) ((int) $row['quantity']);
                                    $currentStockValue = (int) $row['quantity'];
                                    $sizeQuantities = [];

                                    if (is_size_tracked_category($productCategory)) {
                                        $sizeQuantities = fetch_uniform_size_quantities($conn, (int) $row['productID']);
                                        $currentStockLabel = '';
                                        $currentStockValue = uniform_size_total($sizeQuantities);
                                    }

                                    if ($currentStockValue <= 0) {
                                        $stockLevelLabel = 'Out of Stock';
                                        $stockLevelClass = 'is-out';
                                    } elseif ($currentStockValue <= $lowStockThreshold) {
                                        $stockLevelLabel = 'Low Stock';
                                        $stockLevelClass = 'is-low';
                                    } else {
                                        $stockLevelLabel = 'In Stock';
                                        $stockLevelClass = 'is-ok';
                                    }

                                    $actionLabel = 'Edit';

                                    $editUrl = pagination_url('index.php', array_merge($productPagerQuery, [
                                        'products_page' => $productsPage,
                                        'edit' => (int) $row['productID']
                                    ]), 'product-form');
                                    $deleteReturnParams = $productPagerQuery;
                                    unset($deleteReturnParams['productID']);
                                    $deleteConfirmMessage = 'Are you sure you want to delete "' . $row['productName'] . '"?';
                                    ?>
                                    <tr>
                                        <td><?= e($row['productName']) ?></td>
                                        <td><?= e($productCategory !== '' ? $productCategory : 'Uncategorized') ?></td>
                                        <td>
                                            <?php if (is_size_tracked_category($productCategory)): ?>
                                                <div class="size-chip-group">
                                                    <?php foreach (uniform_size_chip_labels($sizeQuantities) as $chipLabel): ?>
                                                        <span class="size-chip"><?= e($chipLabel) ?></span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <?= e($currentStockLabel) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="stock-level-pill <?= e($stockLevelClass) ?>"><?= e($stockLevelLabel) ?></span>
                                        </td>
                                        <?php if ($isAdmin): ?>
                                            <td>
                                                <div class="actions">
                                                    <a
                                                        class="button secondary"
                                                        href="<?= e($editUrl) ?>"
                                                        data-edit-product
                                                        data-product-id="<?= (int) $row['productID'] ?>"
                                                        data-product-name="<?= e($row['productName']) ?>"
                                                        data-product-category="<?= e($productCategory) ?>"
                                                        data-product-quantity="<?= (int) $currentStockValue ?>"
                                                        data-product-price="<?= e(number_format((float) $row['price'], 2, '.', '')) ?>"
                                                        data-size-tracked="<?= is_size_tracked_category($productCategory) ? '1' : '0' ?>"
                                                        data-product-sizes="<?= e(json_encode($sizeQuantities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}') ?>"
                                                    ><?= e($actionLabel) ?></a>
                                                    <form action="backend/delete.php" method="POST" onsubmit="return confirm(<?= e(json_encode($deleteConfirmMessage)) ?>);" data-ajax-form>
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="productID" value="<?= (int) $row['productID'] ?>">
                                                        <input type="hidden" name="products_page" value="<?= $productsPage ?>">
                                                        <?php foreach ($deleteReturnParams as $paramName => $paramValue): ?>
                                                            <?php if (is_scalar($paramValue)): ?>
                                                                <input type="hidden" name="<?= e($paramName) ?>" value="<?= e($paramValue) ?>">
                                                            <?php endif; ?>
                                                        <?php endforeach; ?>
                                                        <button type="submit" class="button secondary danger">Delete</button>
                                                    </form>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td class="empty-state" colspan="<?= $isAdmin ? 5 : 4 ?>">No products found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?= pagination_render('index.php', 'products_page', $productsPage, $productTableTotal, $productsPerPage, $productPagerQuery, 'products', 'products') ?>
            </div>
        </section>

        <div data-stock-modal<?= $stockModalOpen ? '' : ' hidden' ?>>
            <a class="stock-modal-backdrop" href="<?= e($stockFormCloseUrl) ?>" aria-label="Close stock modal" tabindex="-1" data-stock-modal-backdrop></a>
            <form class="form-grid stock-form is-modal" action="backend/stock.php" method="POST" role="dialog" aria-modal="true" aria-labelledby="stock-form-title" data-ajax-form>
                <?= csrf_field() ?>
                <input type="hidden" name="products_page" value="<?= $productsPage ?>">
                <div class="panel-header stock-modal-header">
                    <div>
                        <p class="eyebrow">Stock</p>
                        <h2 id="stock-form-title">Stock Transaction</h2>
                    </div>
                </div>
                <label>
                    Transaction Type
                    <select name="type" required data-stock-type-select>
                        <?php if ($isAdmin): ?>
                            <option value="">Select transaction type</option>
                            <option value="stock_in">Stock In</option>
                            <option value="stock_out">Stock Out</option>
                        <?php else: ?>
                            <option value="stock_out" selected>Stock Out (Sales)</option>
                        <?php endif; ?>
                    </select>
                </label>
                <label>
                    Product
                    <select name="productID" required data-stock-product-select>
                        <option value="">Select product</option>
                        <?php foreach ($stockProductOptions as $stockProduct): ?>
                            <?php
                            $stockProductCategory = $stockProduct['category'] !== '' ? $stockProduct['category'] : 'Uncategorized';
                            $stockProductLabel = $stockProduct['productName'] . ' - ' . $stockProductCategory . ' (' . (int) $stockProduct['quantity'] . ')';
                            ?>
                            <option
                                value="<?= (int) $stockProduct['productID'] ?>"
                                data-size-tracked="<?= is_size_tracked_category($stockProduct['category'] ?? '') ? '1' : '0' ?>"
                                data-product-price="<?= e(number_format((float) $stockProduct['price'], 2, '.', '')) ?>"
                                data-product-sizes="<?= e(json_encode($stockProduct['sizeQuantities'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}') ?>"
                            >
                                <?= e($stockProductLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="uniform-size-panel stock-size-panel" data-stock-size-panel hidden>
                    <div class="uniform-size-grid">
                        <?php foreach ($uniformSizes as $sizeLabel): ?>
                            <label class="uniform-size-field">
                                <span>Size <?= e($sizeLabel) ?></span>
                                <input
                                    type="number"
                                    name="sizeQuantities[<?= e($sizeLabel) ?>]"
                                    min="0"
                                    value="0"
                                    placeholder="0"
                                    data-stock-size-input
                                    data-size-label="<?= e($sizeLabel) ?>"
                                    disabled
                                >
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="uniform-size-summary" data-stock-size-summary>
                        <span>Total qty: <strong data-stock-total-qty>0</strong></span>
                        <span>Total cost: <strong data-stock-total-cost>&#8369;0.00</strong></span>
                    </div>
                </div>
                <label class="stock-quantity-field" data-stock-quantity-field>
                    Quantity
                    <input type="number" name="quantity" min="1" value="1" required>
                </label>
                <label class="stock-remarks-field">
                    Remarks
                    <input type="text" name="remarks" maxlength="255">
                </label>
                <div class="form-actions">
                    <button type="submit" <?= empty($stockProductOptions) ? 'disabled' : '' ?>>Save Transaction</button>
                    <a class="button secondary" href="<?= e($stockFormCloseUrl) ?>" data-stock-modal-close>Cancel</a>
                </div>
            </form>
        </div>
    </main>

    <?php realtime_client_scripts(); ?>
    <script src="frontend/nav.js?v=20260518-4"></script>
</body>
</html>
