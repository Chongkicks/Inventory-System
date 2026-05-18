<?php

// Backward-compatible redirect for old links to the admin add product page.
$query = $_SERVER['QUERY_STRING'] ?? '';

header('Location: admin/add-product.php' . ($query !== '' ? '?' . $query : ''));
exit;

?>
