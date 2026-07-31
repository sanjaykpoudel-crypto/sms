<?php
/**
 * perf_monitor.php
 * ─────────────────────────────────────────────────────────────────────────────
 * ERP Performance Monitor — Admin-only diagnostic endpoint.
 *
 * Usage:
 *   GET /api/perf_monitor.php               — Returns current performance stats
 *   GET /api/perf_monitor.php?log=slow      — Shows last 200 slow operations
 *   GET /api/perf_monitor.php?log=queries   — Shows last 200 slow queries
 *   GET /api/perf_monitor.php?clear=perf    — Clears perf.log
 *   GET /api/perf_monitor.php?clear=queries — Clears slow_queries.log
 *   GET /api/perf_monitor.php?indexes=1     — Lists all missing recommended indexes
 *
 * Access: Admin session only.
 * ─────────────────────────────────────────────────────────────────────────────
 */

ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');

// Admin-only guard
$role = strtolower($_SESSION['role'] ?? $_SESSION['userdata']['role'] ?? '');
if (!isset($_SESSION['user_id']) || !in_array($role, ['admin', 'superadmin'])) {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized. Admin access required.']);
    exit;
}

require_once __DIR__ . '/../database/DBConnection.php';

$action   = $_GET['log']    ?? null;
$clear    = $_GET['clear']  ?? null;
$indexes  = $_GET['indexes'] ?? null;
$db       = db();

$scratch_dir = dirname(__DIR__) . '/scratch/';
$perf_log    = $scratch_dir . 'perf.log';
$query_log   = $scratch_dir . 'slow_queries.log';

// ── CLEAR LOGS ────────────────────────────────────────────────────────────────
if ($clear === 'perf') {
    @file_put_contents($perf_log, '');
    echo json_encode(['status' => 'ok', 'message' => 'perf.log cleared.']);
    exit;
}
if ($clear === 'queries') {
    @file_put_contents($query_log, '');
    echo json_encode(['status' => 'ok', 'message' => 'slow_queries.log cleared.']);
    exit;
}

// ── READ SLOW PERF LOG ────────────────────────────────────────────────────────
if ($action === 'slow') {
    $lines = [];
    if (file_exists($perf_log)) {
        $lines = array_filter(array_map('trim', file($perf_log)));
        $lines = array_slice(array_reverse(array_values($lines)), 0, 200);
    }
    echo json_encode(['status' => 'ok', 'log' => 'perf.log', 'entries' => count($lines), 'lines' => $lines]);
    exit;
}

// ── READ SLOW QUERIES LOG ─────────────────────────────────────────────────────
if ($action === 'queries') {
    $lines = [];
    if (file_exists($query_log)) {
        $lines = array_filter(array_map('trim', file($query_log)));
        $lines = array_slice(array_reverse(array_values($lines)), 0, 200);
    }
    echo json_encode(['status' => 'ok', 'log' => 'slow_queries.log', 'entries' => count($lines), 'lines' => $lines]);
    exit;
}

// ── INDEX CHECK ───────────────────────────────────────────────────────────────
if ($indexes) {
    $recommended = [
        ['table' => 'transaction_headers', 'index' => 'idx_th_date_type_del',   'columns' => 'txn_date, txn_type, is_deleted'],
        ['table' => 'journal_entries',      'index' => 'idx_je_acc_date_type',   'columns' => 'account_id, entry_date, entry_type'],
        ['table' => 'transaction_lines',    'index' => 'idx_tl_header_item',     'columns' => 'header_id, item_id'],
        ['table' => 'customer_invoices',    'index' => 'idx_ci_cust_status',     'columns' => 'customer_id, payment_status'],
        ['table' => 'vendor_bills',         'index' => 'idx_vb_vendor_status',   'columns' => 'vendor_id, balance_due'],
        ['table' => 'pos_entry',            'index' => 'idx_pe_datetime_del',    'columns' => 'date_time, is_deleted'],
        ['table' => 'items',                'index' => 'idx_items_del_act_stock','columns' => 'is_deleted, is_active, current_stock'],
        ['table' => 'system_info',          'index' => 'idx_si_meta_field',      'columns' => 'meta_field'],
    ];

    $results = [];
    $pdo = $db->getConnection();
    foreach ($recommended as $rec) {
        try {
            $stmt = $pdo->prepare("SHOW INDEX FROM `{$rec['table']}` WHERE Key_name = ?");
            $stmt->execute([$rec['index']]);
            $exists = $stmt->fetch() !== false;
            $results[] = array_merge($rec, ['exists' => $exists, 'status' => $exists ? '✓ exists' : '✗ missing']);
        } catch (Exception $e) {
            $results[] = array_merge($rec, ['exists' => null, 'status' => 'error: ' . $e->getMessage()]);
        }
    }
    echo json_encode(['status' => 'ok', 'index_check' => $results]);
    exit;
}

// ── DEFAULT: SYSTEM STATS ─────────────────────────────────────────────────────
$timer_start = microtime(true);

// DB row counts for key tables
$table_stats = [];
$key_tables = [
    'transaction_headers', 'transaction_lines', 'journal_entries',
    'customer_invoices', 'vendor_bills', 'pos_entry', 'pos_items',
    'items', 'payments', 'accounts',
];
foreach ($key_tables as $tbl) {
    try {
        $row = $db->fetchOne("SELECT COUNT(*) as cnt FROM `{$tbl}`");
        $table_stats[$tbl] = (int)($row['cnt'] ?? 0);
    } catch (Exception $e) {
        $table_stats[$tbl] = 'error';
    }
}

// Dashboard cache stats
$cache_count = 0;
$cache_oldest = null;
try {
    $ccRow = $db->fetchOne("SELECT COUNT(*) as cnt, MIN(expires_at) as oldest FROM dashboard_kpi_cache");
    $cache_count  = (int)($ccRow['cnt'] ?? 0);
    $cache_oldest = $ccRow['oldest'] ?? null;
} catch (Exception $e) {}

// PHP memory
$memory_mb = round(memory_get_peak_usage(true) / 1048576, 2);

// Log file sizes
$perf_size  = file_exists($perf_log)  ? round(filesize($perf_log)  / 1024, 1) : 0;
$query_size = file_exists($query_log) ? round(filesize($query_log) / 1024, 1) : 0;

// APCu stats
$apcu_stats = [];
if (function_exists('apcu_cache_info')) {
    try {
        $info = apcu_cache_info(true);
        $apcu_stats = [
            'available'   => true,
            'num_entries' => $info['num_entries'] ?? 0,
            'mem_used_mb' => round(($info['mem_size'] ?? 0) / 1048576, 2),
            'hit_rate'    => isset($info['num_hits'], $info['num_misses'])
                ? round($info['num_hits'] / max(1, $info['num_hits'] + $info['num_misses']) * 100, 1)
                : null,
        ];
    } catch (Exception $e) {
        $apcu_stats = ['available' => false];
    }
} else {
    $apcu_stats = ['available' => false];
}

// Slow query analysis from MySQL
$slow_queries = [];
try {
    $sq = $db->fetchAll("SHOW STATUS LIKE 'Slow_queries'");
    $slow_queries['mysql_slow_count'] = (int)($sq[0]['Value'] ?? 0);
} catch (Exception $e) {}

$elapsed_ms = round((microtime(true) - $timer_start) * 1000, 2);

ob_end_clean();
echo json_encode([
    'status'         => 'ok',
    'generated_at'   => date('Y-m-d H:i:s'),
    'monitor_time_ms'=> $elapsed_ms,
    'php' => [
        'version'       => PHP_VERSION,
        'memory_peak_mb'=> $memory_mb,
        'opcache'       => function_exists('opcache_get_status'),
        'apcu'          => $apcu_stats,
    ],
    'table_row_counts'    => $table_stats,
    'dashboard_cache' => [
        'active_entries' => $cache_count,
        'oldest_expires' => $cache_oldest,
    ],
    'log_files' => [
        'perf_log_kb'       => $perf_size,
        'slow_queries_kb'   => $query_size,
        'perf_log_url'      => '?log=slow',
        'slow_queries_url'  => '?log=queries',
    ],
    'mysql_slow_queries' => $slow_queries,
    'recommendations' => [
        'Run ?indexes=1 to check if all performance indexes are applied.',
        'Run ?clear=perf to reset the performance log.',
        'Dashboard cache TTL is now 120s (was 30s).',
        'POS auto-sync runs max once per 5 minutes per session.',
        'Use ?nocache=1 or ?sync=1 on the dashboard to force a full sync.',
    ],
]);
