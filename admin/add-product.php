<?php
require_once __DIR__ . '/../includes/auth.php';

// Only logged-in admins can open the add product page.
require_login('../frontend/login.html');

include __DIR__ . '/../backend/db.php';
auth_sync_session_user($conn);
require_login('../frontend/login.html');
require_admin('../index.php');

require_once __DIR__ . '/../includes/product-categories.php';
require_once __DIR__ . '/../includes/product-sizes.php';
require_once __DIR__ . '/../includes/realtime-client.php';
require_once __DIR__ . '/../includes/security.php';

// Escape helper for HTML output.
function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Form dropdown and size grid data.
$productCategories = product_categories();
$uniformSizes = uniform_size_labels();
$initialSizeQuantities = array_fill_keys($uniformSizes, 0);
$pageTitle = 'Marketsmart Inventory System | Add Product';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="../frontend/style.css?v=20260517-16">
</head>
<body class="products-page add-product-page">
    <header class="topbar">
        <div>
            <p class="eyebrow">Marketsmart Inventory System</p>
            <h1>Add Product</h1>
            <p class="muted">Create a new inventory item</p>
        </div>
        <nav aria-label="Primary">
            <a class="nav-link" href="../index.php">Dashboard</a>
            <a class="nav-link" href="../index.php?stock=1">Stock</a>
            <a class="nav-link is-active" href="add-product.php" aria-current="page">Add Products</a>
            <a class="nav-link" href="users.php">Users</a>
            <a class="nav-link" href="../backend/reports.php">History</a>
            <a class="nav-link profile-link" href="../profile.php">
                <span class="profile-avatar" aria-hidden="true"><?= e(auth_user_initials()) ?></span>
                <span>Profile</span>
            </a>
            <a class="nav-link" href="../backend/logout.php" data-logout-link>Logout</a>
        </nav>
    </header>

    <main class="products-main">
        <section class="panel">
            <div class="panel-header">
                <div>
                    <p class="eyebrow">Products</p>
                    <h2>Add Product</h2>
                </div>
            </div>

            <form id="product-form" class="form-grid add-product-form" action="../backend/add.php" method="POST" data-ajax-form>
                <?= csrf_field() ?>
                <input type="hidden" name="return_page" value="add-product">
                <label>
                    Product Name
                    <input type="text" name="productName" required>
                </label>
                <label>
                    Category
                    <select name="category" required>
                        <option value="">Select category</option>
                        <?php foreach ($productCategories as $categoryOption): ?>
                            <option value="<?= e($categoryOption) ?>"><?= e($categoryOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="quantity-field" data-product-quantity-field>
                    Current Stock
                    <input type="number" name="quantity" min="0" value="0" required>
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
                                    value="<?= (int) ($initialSizeQuantities[$sizeLabel] ?? 0) ?>"
                                    placeholder="0"
                                    data-uniform-size-input
                                >
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="uniform-size-summary" data-uniform-size-summary>
                        <span>Total qty: <strong data-uniform-total-qty>0</strong></span>
                        <span>Total cost: <strong data-uniform-total-cost>&#8369;0.00</strong></span>
                    </div>
                </div>
                <label class="price-field">
                    Price
                    <div class="currency-input">
                        <span class="currency-prefix">&#8369;</span>
                        <input type="number" name="price" min="0" step="0.01" value="0.00" required data-product-price-input>
                    </div>
                </label>
                <div class="form-actions">
                    <button type="submit">Add Product</button>
                </div>
            </form>
        </section>
    </main>

    <?php realtime_client_scripts(); ?>
    <script src="../frontend/nav.js?v=20260518-4"></script>
</body>
</html>
