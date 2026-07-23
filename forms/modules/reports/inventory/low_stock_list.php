<?php
/**
 * Low Stock Report — Delegates to Less Stock Report with low-stock filter.
 */
$_GET['filter'] = 'low_stock';
require __DIR__ . '/less_stock_list.php';
