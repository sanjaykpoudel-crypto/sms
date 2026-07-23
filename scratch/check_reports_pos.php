<?php
$dir = __DIR__ . '/../forms/modules/reports';
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
foreach ($it as $file) {
    if ($file->isDir()) continue;
    $content = file_get_contents($file->getPathname());
    if (strpos($content, 'NOT LIKE') !== false && strpos($content, 'POS') !== false) {
        echo "File: " . $file->getFilename() . "\n";
        $lines = explode("\n", $content);
        foreach ($lines as $i => $line) {
            if (strpos($line, 'NOT LIKE') !== false && strpos($line, 'POS') !== false) {
                echo "  Line " . ($i + 1) . ": " . trim($line) . "\n";
            }
        }
    }
}
?>
