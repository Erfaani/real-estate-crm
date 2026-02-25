<?php
declare(strict_types=1);

/**
 * health.php — Diagnostic page for PDO + SQL binds
 * Put this file in /crm/health.php temporarily.
 * After debugging: delete it or restrict access.
 */

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');

// IMPORTANT: session ini BEFORE session_start
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
// ini_set('session.cookie_secure', '1'); // enable if HTTPS only

session_start();

require __DIR__ . '/db.php';

header('Content-Type: text/plain; charset=utf-8');

function out(string $s): void { echo $s . "\n"; }

out("== CRM HEALTH CHECK ==");
out("PHP: " . PHP_VERSION);

try {
    $pdoAttrs = [
        'ATTR_ERRMODE'            => $pdo->getAttribute(PDO::ATTR_ERRMODE),
        'ATTR_EMULATE_PREPARES'   => $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),
        'ATTR_DEFAULT_FETCH_MODE' => $pdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE),
        'ATTR_DRIVER_NAME'        => $pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
    ];
    out("PDO attrs: " . json_encode($pdoAttrs, JSON_UNESCAPED_UNICODE));
} catch (Throwable $e) {
    out("PDO attrs error: " . $e->getMessage());
}

// DB versions
try {
    $dbName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
    $dbVer  = (string)$pdo->query("SELECT VERSION()")->fetchColumn();
    out("DB: {$dbName}");
    out("DB version: {$dbVer}");
} catch (Throwable $e) {
    out("DB version check error: " . $e->getMessage());
}

// Table existence
$tables = ['property_files','property_details','customers','contact_reports','users'];
foreach ($tables as $t) {
    try {
        $pdo->query("SELECT 1 FROM `$t` LIMIT 1");
        out("[OK] table exists: $t");
    } catch (Throwable $e) {
        out("[NO] table missing/issue: $t => " . $e->getMessage());
    }
}

// Columns check
try {
    $cols = $pdo->query("
        SELECT TABLE_NAME, COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME IN ('property_files','property_details')
        ORDER BY TABLE_NAME, ORDINAL_POSITION
    ")->fetchAll(PDO::FETCH_ASSOC);

    $map = [];
    foreach ($cols as $r) {
        $map[$r['TABLE_NAME']][] = $r['COLUMN_NAME'];
    }
    out("\n-- Columns(property_files) --");
    out(isset($map['property_files']) ? implode(', ', $map['property_files']) : 'N/A');
    out("-- Columns(property_details) --");
    out(isset($map['property_details']) ? implode(', ', $map['property_details']) : 'N/A');
} catch (Throwable $e) {
    out("Columns check error: " . $e->getMessage());
}

// Simulate session identity (safe defaults)
$role = $_SESSION['role'] ?? 'sales';
$fullname = trim((string)($_SESSION['fullname'] ?? 'Test Fullname'));
$username = trim((string)($_SESSION['user'] ?? 'testuser'));
$isAdmin = ($role === 'admin');

$currentName = $fullname !== '' ? $fullname : $username;
out("\nSession-like: role={$role}, currentName={$currentName}, isAdmin=" . ($isAdmin ? '1':'0'));

// Build the exact problematic query
$search = '';
$perPage = 20;
$offset  = 0;

$listParams = [];
$privateCondLoc = "1=1";
$privateCondOwn = "1=1";

if (!$isAdmin) {
    // Use distinct placeholders to avoid repeated named param issues
    $privateCondLoc = "(pf.salesperson = :currentName_loc)";
    $privateCondOwn = "(pf.salesperson = :currentName_own)";
    $listParams['currentName_loc'] = $currentName;
    $listParams['currentName_own'] = $currentName;
}

$sql = "
SELECT
    pf.id, pf.code, pf.salesperson, pf.created_at,
    pf.pf_status, pf.pf_city, pf.pf_area,
    CONCAT_WS(' - ', pf.pf_city, pf.pf_area) AS location,

    pd.document_status, pd.area, pd.floor, pd.price, pd.year_built,
    pd.unit_in_floor, pd.rooms, pd.storage_area, pd.parking_area,
    pd.yard_area, pd.file_type, pd.register_status,

    CASE WHEN $privateCondLoc THEN pd.location2 ELSE NULL END AS location2,
    CASE WHEN $privateCondOwn THEN pd.owner_info ELSE NULL END AS owner_info

FROM property_files pf
LEFT JOIN property_details pd ON pd.property_file_id = pf.id
";

if ($search !== '') {
    $sql .= " WHERE pf.code LIKE :s OR pf.salesperson LIKE :s";
    $listParams['s'] = "%{$search}%";
}

$limit = (int)$perPage;
$off   = (int)$offset;
$sql  .= " ORDER BY pf.created_at DESC LIMIT $limit OFFSET $off";

out("\n-- Test Query --");
out($sql);
out("Params: " . json_encode($listParams, JSON_UNESCAPED_UNICODE));

// Run query
try {
    $stm = $pdo->prepare($sql);
    $ok = $stm->execute($listParams);
    out("Execute OK: " . ($ok ? 'true':'false'));
    $rows = $stm->fetchAll(PDO::FETCH_ASSOC);
    out("Rows fetched: " . count($rows));

    if ($rows) {
        out("First row keys: " . implode(', ', array_keys($rows[0])));
        out("First row sample: " . json_encode($rows[0], JSON_UNESCAPED_UNICODE));
    }
} catch (Throwable $e) {
    out("!!! QUERY FAILED !!!");
    out("Error: " . $e->getMessage());
    out("Code: " . (string)$e->getCode());

    // Extra debug: try turning emulate prepares ON for this request
    try {
        out("\n-- Retest with ATTR_EMULATE_PREPARES = true --");
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
        $stm2 = $pdo->prepare($sql);
        $stm2->execute($listParams);
        $rows2 = $stm2->fetchAll(PDO::FETCH_ASSOC);
        out("Retest OK. Rows: " . count($rows2));
    } catch (Throwable $e2) {
        out("Retest failed too: " . $e2->getMessage());
    }
}

out("\n== DONE ==");
