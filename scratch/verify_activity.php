<?php
require_once __DIR__ . '/../database/DBConnection.php';
$db = db();

// Check activities table
$count = $db->fetchOne("SELECT COUNT(*) as c FROM activities");
echo "Activities table: OK, rows=" . $count['c'] . "\n";

// Check all module files exist
$files = [
    'forms/modules/activity/activity_list.php',
    'forms/modules/activity/activity_manage.php',
    'forms/modules/activity/view.php',
    'forms/modules/activity/calendar.php',
];
$base = dirname(__DIR__);
foreach ($files as $f) {
    $path = $base . '/' . $f;
    echo ($f . ': ' . (file_exists($path) ? 'EXISTS' : 'MISSING') . "\n");
}

// Check navigation update
$index = file_get_contents($base . '/index.php');
$has_calendar_link = (strpos($index, '?page=activity/calendar') !== false);
$has_activity_list  = (strpos($index, '?page=activity&type=task') !== false);
echo "index.php calendar link: " . ($has_calendar_link ? 'OK' : 'MISSING') . "\n";
echo "index.php task link:     " . ($has_activity_list ? 'OK' : 'MISSING') . "\n";

echo "\nAll checks done.\n";
