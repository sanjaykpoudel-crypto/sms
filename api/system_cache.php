<?php
/**
 * system_cache.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Lightweight, zero-dependency cache layer for the ERP system.
 *
 * Provides:
 *   - sysinfo_get($key)              — Fetch a single system_info value (cached)
 *   - sysinfo_get_batch($keys)       — Fetch multiple system_info values at once
 *   - sysinfo_prefetch()             — Load ALL system_info into cache in 1 query
 *   - account_cache_get($id, $type)  — Per-request account resolution cache
 *   - account_cache_set($id, $type, $account_id)
 *   - perf_start($label)             — Start a performance timer
 *   - perf_end($label)               — End timer, log if slow
 *   - perf_get_all()                 — Get all recorded timings
 *
 * Cache Strategy (tiered):
 *   L1: Static PHP variable (per-request, zero overhead)
 *   L2: $_SESSION (per-session, survives requests)
 *   L3: APCu (cross-request in-memory, if available)
 *   L4: Database (always available fallback)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 */

// ── L1: Per-request static stores ────────────────────────────────────────────

/** @var array<string,string|null> Per-request system_info cache */
$_SYSINFO_CACHE = [];

/** @var bool Whether we've already loaded all system_info keys */
$_SYSINFO_LOADED_ALL = false;

/** @var array<string,string|null> Per-request account resolution cache */
$_ACCOUNT_CACHE = [];

/** @var array<string,float> Performance timer start times */
$_PERF_TIMERS = [];

/** @var array<string,float> Performance timer results (ms) */
$_PERF_RESULTS = [];

// ── APCu detection ────────────────────────────────────────────────────────────
define('APCU_AVAILABLE', function_exists('apcu_fetch') && ini_get('apc.enabled'));
define('APCU_SYSINFO_TTL', 300);   // 5 minutes for system_info in APCu
define('APCU_ACCOUNT_TTL', 600);   // 10 minutes for account lookups in APCu

// ─────────────────────────────────────────────────────────────────────────────
// SYSTEM INFO CACHE
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Prefetch ALL system_info rows in a single query and populate all caches.
 * Call this once at the start of any request that will need multiple settings.
 */
function sysinfo_prefetch(): void
{
    global $_SYSINFO_CACHE, $_SYSINFO_LOADED_ALL;
    if ($_SYSINFO_LOADED_ALL) return;

    // Check APCu first
    if (APCU_AVAILABLE) {
        $cached = apcu_fetch('erp_sysinfo_all', $success);
        if ($success && is_array($cached)) {
            $_SYSINFO_CACHE = $cached;
            $_SYSINFO_LOADED_ALL = true;
            return;
        }
    }

    // Load from DB
    try {
        $db = db();
        $rows = $db->fetchAll("SELECT meta_field, meta_value FROM system_info");
        foreach ($rows as $row) {
            $_SYSINFO_CACHE[$row['meta_field']] = $row['meta_value'];
        }
        $_SYSINFO_LOADED_ALL = true;

        // Store in APCu
        if (APCU_AVAILABLE) {
            apcu_store('erp_sysinfo_all', $_SYSINFO_CACHE, APCU_SYSINFO_TTL);
        }
    } catch (Exception $e) {
        // Silent fail — individual lookups will still work
    }
}

/**
 * Get a single system_info value with full cache hierarchy.
 *
 * @param string $key       meta_field to look up
 * @param mixed  $default   Value to return if key not found
 * @return string|null
 */
function sysinfo_get(string $key, $default = null)
{
    global $_SYSINFO_CACHE, $_SYSINFO_LOADED_ALL;

    // L1: In-memory
    if (array_key_exists($key, $_SYSINFO_CACHE)) {
        return $_SYSINFO_CACHE[$key] ?? $default;
    }

    // If we've loaded all, the key doesn't exist
    if ($_SYSINFO_LOADED_ALL) {
        return $default;
    }

    // L2: APCu
    if (APCU_AVAILABLE) {
        $val = apcu_fetch("erp_si_{$key}", $success);
        if ($success) {
            $_SYSINFO_CACHE[$key] = $val;
            return $val ?? $default;
        }
    }

    // L3: Database
    try {
        $db = db();
        $row = $db->fetchOne("SELECT meta_value FROM system_info WHERE meta_field = ?", [$key]);
        $val = $row['meta_value'] ?? null;
        $_SYSINFO_CACHE[$key] = $val;

        if (APCU_AVAILABLE) {
            apcu_store("erp_si_{$key}", $val, APCU_SYSINFO_TTL);
        }

        return $val ?? $default;
    } catch (Exception $e) {
        return $default;
    }
}

/**
 * Batch-fetch multiple system_info keys in a single query.
 * Missing keys will be populated with null.
 *
 * @param  string[] $keys
 * @return array<string,string|null>
 */
function sysinfo_get_batch(array $keys): array
{
    global $_SYSINFO_CACHE;

    $result   = [];
    $missing  = [];

    foreach ($keys as $key) {
        if (array_key_exists($key, $_SYSINFO_CACHE)) {
            $result[$key] = $_SYSINFO_CACHE[$key];
        } else {
            $missing[] = $key;
        }
    }

    if (empty($missing)) return $result;

    // Try APCu for remaining
    if (APCU_AVAILABLE) {
        $still_missing = [];
        foreach ($missing as $key) {
            $val = apcu_fetch("erp_si_{$key}", $success);
            if ($success) {
                $result[$key] = $val;
                $_SYSINFO_CACHE[$key] = $val;
            } else {
                $still_missing[] = $key;
            }
        }
        $missing = $still_missing;
    }

    if (!empty($missing)) {
        try {
            $db = db();
            $ph = implode(',', array_fill(0, count($missing), '?'));
            $rows = $db->fetchAll(
                "SELECT meta_field, meta_value FROM system_info WHERE meta_field IN ({$ph})",
                $missing
            );
            $found = [];
            foreach ($rows as $row) {
                $found[$row['meta_field']] = $row['meta_value'];
                $_SYSINFO_CACHE[$row['meta_field']] = $row['meta_value'];
                if (APCU_AVAILABLE) {
                    apcu_store("erp_si_{$row['meta_field']}", $row['meta_value'], APCU_SYSINFO_TTL);
                }
            }
            // Fill nulls for keys not found
            foreach ($missing as $key) {
                $result[$key] = $found[$key] ?? null;
                if (!isset($_SYSINFO_CACHE[$key])) {
                    $_SYSINFO_CACHE[$key] = null;
                }
            }
        } catch (Exception $e) {
            foreach ($missing as $key) {
                $result[$key] = null;
            }
        }
    }

    return $result;
}

/**
 * Invalidate the system_info cache (call after saving system settings).
 */
function sysinfo_invalidate(): void
{
    global $_SYSINFO_CACHE, $_SYSINFO_LOADED_ALL;
    $_SYSINFO_CACHE = [];
    $_SYSINFO_LOADED_ALL = false;

    if (APCU_AVAILABLE) {
        apcu_delete('erp_sysinfo_all');
        // Clear individual keys too (APCu doesn't have prefix-delete, use iterator)
        $iter = new APCUIterator('/^erp_si_/');
        if ($iter) apcu_delete($iter);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// ACCOUNT RESOLUTION CACHE
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Cache key for account resolution.
 */
function _acct_cache_key(string $masterId, string $type): string
{
    return "{$masterId}:{$type}";
}

/**
 * Get a cached account ID for a master record + type.
 * Returns null if not cached.
 */
function account_cache_get(string $masterId, string $type): ?string
{
    global $_ACCOUNT_CACHE;
    $key = _acct_cache_key($masterId, $type);

    if (array_key_exists($key, $_ACCOUNT_CACHE)) {
        return $_ACCOUNT_CACHE[$key];
    }

    if (APCU_AVAILABLE) {
        $val = apcu_fetch("erp_acct_{$key}", $success);
        if ($success) {
            $_ACCOUNT_CACHE[$key] = $val;
            return $val;
        }
    }

    return null;
}

/**
 * Store a resolved account ID in cache.
 */
function account_cache_set(string $masterId, string $type, ?string $accountId): void
{
    global $_ACCOUNT_CACHE;
    $key = _acct_cache_key($masterId, $type);
    $_ACCOUNT_CACHE[$key] = $accountId;

    if (APCU_AVAILABLE) {
        apcu_store("erp_acct_{$key}", $accountId, APCU_ACCOUNT_TTL);
    }
}

/**
 * Invalidate account cache for a specific record (e.g. after editing an item/customer).
 */
function account_cache_invalidate(string $masterId): void
{
    global $_ACCOUNT_CACHE;
    $prefix = "{$masterId}:";
    foreach (array_keys($_ACCOUNT_CACHE) as $k) {
        if (strpos($k, $prefix) === 0) unset($_ACCOUNT_CACHE[$k]);
    }
    if (APCU_AVAILABLE) {
        $iter = new APCUIterator("/^erp_acct_{$masterId}:/");
        if ($iter) apcu_delete($iter);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// PERFORMANCE MONITORING
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Start a performance timer for a named section.
 */
function perf_start(string $label): void
{
    global $_PERF_TIMERS;
    $_PERF_TIMERS[$label] = microtime(true);
}

/**
 * End a performance timer. Logs to file if duration exceeds threshold.
 *
 * @param string $label       Section label
 * @param float  $warn_ms     Log warning if exceeds this many ms (default 200ms)
 * @return float              Duration in milliseconds
 */
function perf_end(string $label, float $warn_ms = 200.0): float
{
    global $_PERF_TIMERS, $_PERF_RESULTS;
    $start = $_PERF_TIMERS[$label] ?? microtime(true);
    $ms = round((microtime(true) - $start) * 1000, 2);
    $_PERF_RESULTS[$label] = $ms;

    if ($ms >= $warn_ms) {
        perf_log_slow($label, $ms);
    }

    return $ms;
}

/**
 * Get all recorded performance timings.
 * @return array<string,float>
 */
function perf_get_all(): array
{
    global $_PERF_RESULTS;
    return $_PERF_RESULTS;
}

/**
 * Log a slow operation to scratch/perf.log.
 */
function perf_log_slow(string $label, float $ms): void
{
    $logFile = dirname(__DIR__) . '/scratch/perf.log';
    $entry = sprintf(
        "[%s] SLOW(%sms) %s | URL: %s | User: %s\n",
        date('Y-m-d H:i:s'),
        number_format($ms, 1),
        $label,
        $_SERVER['REQUEST_URI'] ?? 'cli',
        $_SESSION['user_id'] ?? 'anon'
    );
    @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

/**
 * Log a slow SQL query to scratch/slow_queries.log.
 */
function perf_log_query(string $sql, array $params, float $ms): void
{
    if ($ms < 100) return; // Only log queries > 100ms
    $logFile = dirname(__DIR__) . '/scratch/slow_queries.log';
    $entry = sprintf(
        "[%s] %sms | %s | Params: %s\n",
        date('Y-m-d H:i:s'),
        number_format($ms, 1),
        preg_replace('/\s+/', ' ', trim($sql)),
        json_encode($params)
    );
    @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

// ─────────────────────────────────────────────────────────────────────────────
// DASHBOARD CACHE HELPERS (extends existing cache_get/cache_set in dashboard)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Get dashboard KPI from APCu (faster than DB table lookup).
 */
function dash_apcu_get(string $key): ?array
{
    if (!APCU_AVAILABLE) return null;
    $val = apcu_fetch("erp_dash_{$key}", $success);
    return $success ? $val : null;
}

/**
 * Set dashboard KPI in APCu.
 */
function dash_apcu_set(string $key, array $value, int $ttl = 120): void
{
    if (!APCU_AVAILABLE) return;
    apcu_store("erp_dash_{$key}", $value, $ttl);
}

/**
 * Invalidate all dashboard cache entries.
 */
function dash_apcu_clear(): void
{
    if (!APCU_AVAILABLE) return;
    $iter = new APCUIterator('/^erp_dash_/');
    if ($iter) apcu_delete($iter);
}
