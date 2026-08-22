<?php
$dirs = ['C:/xampp/htdocs/sms', 'C:/xampp/htdocs/sms1', 'C:/xampp/htdocs/sms-new'];

function search_in_files($dir, $pattern) {
    $results = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isDir()) continue;
        $ext = strtolower($file->getExtension());
        if (!in_array($ext, ['sql', 'php', 'json', 'log', 'txt', 'csv'])) continue;
        if ($file->getSize() > 20 * 1024 * 1024) continue; // skip > 20MB
        
        $content = file_get_contents($file->getPathname());
        if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            $results[$file->getPathname()] = count($matches[0]);
            echo "Found " . count($matches[0]) . " in " . $file->getPathname() . "\n";
            foreach (array_slice($matches[0], 0, 5) as $m) {
                $snippet = substr($content, max(0, $m[1] - 50), 200);
                echo "  Snippet: " . preg_replace('/\s+/', ' ', $snippet) . "\n";
            }
        }
    }
    return $results;
}

echo "=== Searching for JV-00002 ===\n";
foreach ($dirs as $d) {
    if (is_dir($d)) search_in_files($d, '/JV-00002/i');
}

echo "\n=== Searching for Opening receivable and payable ===\n";
foreach ($dirs as $d) {
    if (is_dir($d)) search_in_files($d, '/Opening receivable and payable/i');
}

echo "\n=== Searching for 429015 ===\n";
foreach ($dirs as $d) {
    if (is_dir($d)) search_in_files($d, '/429015/i');
}
