<?php
require_once __DIR__ . '/../database/DBConnection.php';

$db = DBConnection::getInstance();
$pdo = $db->getConnection();

echo "====================================================================\n";
echo " DISCOVERY OF ALL FORM & VIEW ROUTES IN ERP\n";
echo "====================================================================\n\n";

$base_dir = realpath(__DIR__ . '/../forms/modules');
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base_dir));

$view_files = [];
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $rel_path = str_replace([$base_dir . DIRECTORY_SEPARATOR, '\\'], ['', '/'], $file->getPathname());
        $view_files[] = $rel_path;
    }
}
sort($view_files);

echo "Total Form/View Files Found: " . count($view_files) . "\n\n";
foreach ($view_files as $vf) {
    echo " - " . $vf . "\n";
}
