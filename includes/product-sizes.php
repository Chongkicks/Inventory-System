<?php

require_once __DIR__ . '/uniform-sizes.php';

// Categories listed here store stock per size instead of one flat quantity.
function size_tracked_categories()
{
    return [
        'PE Uniform',
        'Uniform',
    ];
}

// Check whether a product should use the product_sizes table.
function is_size_tracked_category($category)
{
    return in_array((string) $category, size_tracked_categories(), true);
}

// Build a complete size map so missing inputs are saved as zero.
function normalize_uniform_size_quantities(array $sizeQuantities)
{
    $normalized = [];

    foreach (uniform_size_labels() as $sizeLabel) {
        $normalized[$sizeLabel] = max(0, (int) ($sizeQuantities[$sizeLabel] ?? 0));
    }

    return $normalized;
}

// Read size quantities for one product in the fixed display order.
function fetch_uniform_size_quantities(mysqli $conn, int $productId)
{
    $sizeQuantities = array_fill_keys(uniform_size_labels(), 0);
    $stmt = $conn->prepare("SELECT sizeLabel, quantity FROM product_sizes WHERE productID = ? ORDER BY FIELD(sizeLabel, 'XS', 'S', 'M', 'L', 'XL', 'XXL')");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $sizeLabel = (string) ($row['sizeLabel'] ?? '');

        if (array_key_exists($sizeLabel, $sizeQuantities)) {
            $sizeQuantities[$sizeLabel] = (int) $row['quantity'];
        }
    }

    return $sizeQuantities;
}

// Replace saved size rows with the current submitted values.
function save_uniform_size_quantities(mysqli $conn, int $productId, array $sizeQuantities)
{
    $normalized = normalize_uniform_size_quantities($sizeQuantities);
    $deleteStmt = $conn->prepare("DELETE FROM product_sizes WHERE productID = ?");
    $deleteStmt->bind_param("i", $productId);
    $deleteStmt->execute();

    $insertStmt = $conn->prepare("INSERT INTO product_sizes (productID, sizeLabel, quantity) VALUES (?, ?, ?)");
    $totalQuantity = 0;

    foreach ($normalized as $sizeLabel => $quantity) {
        $totalQuantity += $quantity;

        if ($quantity <= 0) {
            continue;
        }

        $insertStmt->bind_param("isi", $productId, $sizeLabel, $quantity);
        $insertStmt->execute();
    }

    return $totalQuantity;
}

// Total all normalized size quantities for dashboard stock counts.
function uniform_size_total(array $sizeQuantities)
{
    return array_sum(normalize_uniform_size_quantities($sizeQuantities));
}

// Format size counts for the small chips shown in tables and selects.
function uniform_size_chip_labels(array $sizeQuantities)
{
    $chips = [];

    foreach (normalize_uniform_size_quantities($sizeQuantities) as $sizeLabel => $quantity) {
        $chips[] = $sizeLabel . ': ' . $quantity;
    }

    return $chips;
}
