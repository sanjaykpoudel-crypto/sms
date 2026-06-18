<?php
require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json');

if(!isset($_SESSION['userdata'])) {
    http_response_code(401);
    echo json_encode(['message' => 'Unauthorized']);
    exit;
}

$id = isset($_GET['id']) ? $_GET['id'] : '';
if(empty($id)) {
    echo json_encode(['progress' => 0]);
    exit;
}

$file = __DIR__ . "/../../uploads/progress_{$id}.json";
if(file_exists($file)) {
    $data = json_decode(file_get_contents($file), true);
    echo json_encode($data);
    
    // Clear the file if progress is 100%
    if(isset($data['progress']) && $data['progress'] >= 100) {
        // We might want to keep it long enough for the client to see 100%
        // but for now let's just leave it to be deleted by the main process or eventually.
    }
} else {
    echo json_encode(['progress' => 0]);
}
?>
