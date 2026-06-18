<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please login.']);
    exit;
}
header('Content-Type: application/json');
require_once '../database/DBConnection.php';

$db = db();
$action = $_GET['action'] ?? '';

// Fetch settings
$info = $db->fetchAll("SELECT meta_field, meta_value FROM system_info");
$settings = [];
foreach($info as $row) {
    $settings[$row['meta_field']] = $row['meta_value'];
}

$mysql_bin = $settings['mysql_bin'] ?? 'C:\xampp\mysql\bin\\';
$git_path = $settings['git_path'] ?? 'git';
$backup_folder = $settings['backup_folder'] ?? 'database';
$db_name = 'sms_db';
$db_user = 'root';
$db_pass = '';

// Ensure backup folder exists (absolute or relative to sms/ root)
$sms_root = dirname(__DIR__);
$absolute_backup_path = (strpos($backup_folder, ':') !== false) ? $backup_folder : ($sms_root . DIRECTORY_SEPARATOR . $backup_folder);

if (!is_dir($absolute_backup_path)) {
    mkdir($absolute_backup_path, 0777, true);
}

try {
    switch ($action) {
        case 'export_db':
            $filename = $db_name . '_' . date('Y-m-d_H-i-s') . '.sql';
            $target = $absolute_backup_path . DIRECTORY_SEPARATOR . $filename;
            
            $cmd = "\"{$mysql_bin}mysqldump\" -u {$db_user} " . ($db_pass ? "-p{$db_pass} " : "") . "{$db_name} > \"{$target}\" 2>&1";
            exec($cmd, $output, $return_var);
            
            if ($return_var === 0) {
                echo json_encode(['status' => 'success', 'message' => "Database exported to $filename", 'output' => implode("\n", $output)]);
            } else {
                throw new Exception("Export failed. Command: $cmd\nOutput: " . implode("\n", $output));
            }
            break;

        case 'import_latest':
            $files = glob($absolute_backup_path . DIRECTORY_SEPARATOR . "*.sql");
            if (empty($files)) throw new Exception("No .sql files found in backup folder.");
            
            usort($files, function($a, $b) {
                return filemtime($b) - filemtime($a);
            });
            
            $latest = $files[0];
            $cmd = "\"{$mysql_bin}mysql\" -u {$db_user} " . ($db_pass ? "-p{$db_pass} " : "") . "{$db_name} < \"{$latest}\" 2>&1";
            exec($cmd, $output, $return_var);
            
            if ($return_var === 0) {
                echo json_encode(['status' => 'success', 'message' => "Database restored from " . basename($latest), 'output' => implode("\n", $output)]);
            } else {
                throw new Exception("Import failed. Output: " . implode("\n", $output));
            }
            break;

        case 'git_push':
            $user = $settings['git_username'] ?? '';
            $pass = $settings['git_password'] ?? '';
            
            // Check if git is initialized
            if (!is_dir($absolute_backup_path . DIRECTORY_SEPARATOR . '.git')) {
                exec("cd \"{$absolute_backup_path}\" && \"{$git_path}\" init 2>&1", $output);
            }
            
            // Try to get remote URL to inject credentials
            $remote_url = "";
            exec("cd \"{$absolute_backup_path}\" && \"{$git_path}\" remote get-url origin 2>&1", $remote_output, $remote_res);
            if ($remote_res === 0 && !empty($user) && !empty($pass)) {
                $url = $remote_output[0];
                if (strpos($url, 'https://') === 0) {
                    $clean_url = str_replace('https://', '', $url);
                    // If it already has credentials, strip them
                    if (strpos($clean_url, '@') !== false) {
                        $clean_url = substr($clean_url, strpos($clean_url, '@') + 1);
                    }
                    $remote_url = "https://{$user}:{$pass}@{$clean_url}";
                    exec("cd \"{$absolute_backup_path}\" && \"{$git_path}\" remote set-url origin \"{$remote_url}\"");
                }
            }

            $commands = [
                "cd \"{$absolute_backup_path}\"",
                "\"{$git_path}\" add .",
                "\"{$git_path}\" commit -m \"Auto Backup " . date('Y-m-d H:i:s') . "\"",
                "\"{$git_path}\" push origin main"
            ];
            
            $full_output = [];
            foreach($commands as $c) {
                exec($c . " 2>&1", $output, $res);
                $full_output[] = "> $c\n" . implode("\n", $output);
                $output = []; // clear for next command
            }
            
            echo json_encode(['status' => 'success', 'message' => "Git push attempted", 'output' => implode("\n---\n", $full_output)]);
            break;

        case 'git_pull':
            $cmd = "cd \"{$absolute_backup_path}\" && \"{$git_path}\" pull origin main 2>&1";
            exec($cmd, $output, $return_var);
            
            echo json_encode(['status' => ($return_var === 0 ? 'success' : 'error'), 'message' => "Git pull attempted", 'output' => implode("\n", $output)]);
            break;

        default:
            throw new Exception("Invalid action: $action");
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}




