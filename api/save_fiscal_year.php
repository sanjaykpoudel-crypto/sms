<?php
/**
 * Create/Update Fiscal Year Master Record
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please login.']);
    exit;
}

require_once '../database/DBConnection.php';
require_once 'reference_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit;
}

$db = db();

try {
    $id = $_POST['id'] ?? null;
    $name = trim($_POST['name'] ?? '');
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $status = $_POST['status'] ?? 'open';
    $notes = trim($_POST['notes'] ?? '');

    // 1. Permission checks
    if ($id) {
        if (!has_permission('edit_fiscal_year')) {
            throw new Exception("You do not have permission to edit fiscal years.");
        }
    } else {
        if (!has_permission('create_fiscal_year')) {
            throw new Exception("You do not have permission to create fiscal years.");
        }
    }

    // 2. Validate basic inputs
    if (empty($name)) throw new Exception("Fiscal Year Name is required (e.g. FY 2025/26).");
    if (empty($start_date)) throw new Exception("Start Date is required.");
    if (empty($end_date)) throw new Exception("End Date is required.");
    if (strtotime($start_date) > strtotime($end_date)) {
        throw new Exception("Start Date cannot be after End Date.");
    }

    // 3. Check for name uniqueness
    $name_check = $db->fetchOne("SELECT id FROM fiscal_years WHERE name = :name AND id != :id", [
        'name' => $name,
        'id' => $id ?: ''
    ]);
    if ($name_check) {
        throw new Exception("A Fiscal Year with the name '{$name}' already exists.");
    }

    // 4. Check for date range overlaps
    $overlap_check = $db->fetchOne("
        SELECT name, start_date, end_date FROM fiscal_years 
        WHERE id != :id 
          AND (
            (:start BETWEEN start_date AND end_date) OR
            (:end BETWEEN start_date AND end_date) OR
            (start_date BETWEEN :start_inner AND :end_inner)
          )
    ", [
        'id' => $id ?: '',
        'start' => $start_date,
        'end' => $end_date,
        'start_inner' => $start_date,
        'end_inner' => $end_date
    ]);
    if ($overlap_check) {
        throw new Exception("The date range overlaps with existing Fiscal Year '{$overlap_check['name']}' ({$overlap_check['start_date']} to {$overlap_check['end_date']}).");
    }

    // 5. Check active status constraint: Only one fiscal year can be active (open/reopened) at a time
    if ($status === 'open' || $status === 'reopened') {
        $should_check = true;
        if ($id) {
            $current = $db->fetchOne("SELECT status FROM fiscal_years WHERE id = ?", [$id]);
            $current_status = $current['status'] ?? '';
            if ($current_status === 'open' || $current_status === 'reopened') {
                $should_check = false; // Already active in the DB; we are not activating a new one
            }
        }
        
        if ($should_check) {
            $active_check = $db->fetchOne("
                SELECT name FROM fiscal_years 
                WHERE id != :id 
                  AND status IN ('open', 'reopened')
            ", ['id' => $id ?: '']);
            
            if ($active_check) {
                throw new Exception("Only one fiscal year can be active (open/reopened) at a time. Fiscal Year '{$active_check['name']}' is currently active.");
            }
        }
    }

    // 6. Save or update
    if ($id) {
        // Edit existing
        $db->update('fiscal_years', [
            'name' => $name,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'status' => $status,
            'notes' => $notes
        ], "id = :id", ['id' => $id]);
        
        $message = "Fiscal Year updated successfully.";
    } else {
        // Create new
        $id = generate_uuid();
        $db->insert('fiscal_years', [
            'id' => $id,
            'name' => $name,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'status' => $status,
            'notes' => $notes
        ]);
        
        $message = "Fiscal Year created successfully.";
    }

    echo json_encode(['status' => 'success', 'message' => $message, 'id' => $id]);
    
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
