<?php
/************************************
 * update_status.php (نسخه حرفه‌ای)
 * - RBAC: admin, supervisor, sales
 * - CSRF
 * - ثبت تاریخچه در contact_reports (با ستون‌های جدید در صورت وجود)
 * - به‌روزرسانی customers (report/contacted/updated_at)
 ************************************/

declare(strict_types=1);

session_start();
require __DIR__ . '/db.php';

// نیاز به ورود
if (empty($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

// نقش‌ها
$role = (string)($_SESSION['role'] ?? '');
$isAdmin = ($role === 'admin');
$isSupervisor = ($role === 'supervisor');
$isSales = ($role === 'sales');

// فقط مدیر/سوپروایزر/کارشناس
if (!($isAdmin || $isSupervisor || $isSales)) {
    http_response_code(403);
    exit('دسترسی غیرمجاز');
}

// فقط POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('درخواست نامعتبر.');
}

// CSRF
$csrf = (string)($_POST['csrf'] ?? '');
if ($csrf === '' || $csrf !== (string)($_SESSION['csrf'] ?? '')) {
    http_response_code(400);
    exit('CSRF نامعتبر');
}

// ورودی‌ها
$customerId = (int)($_POST['customer_id'] ?? 0);
$report     = trim((string)($_POST['report'] ?? ''));
$status     = trim((string)($_POST['status'] ?? 'منتظر تماس بعدی')); // موفق | ناموفق | منتظر تماس بعدی
$contacted  = isset($_POST['contacted']) ? 1 : 0;

if ($customerId <= 0 || $report === '') {
    http_response_code(400);
    exit('اطلاعات ناقصه.');
}

// اعتبار status (مطابق ENUM)
$allowedStatus = ['موفق','ناموفق','منتظر تماس بعدی'];
if (!in_array($status, $allowedStatus, true)) {
    $status = 'منتظر تماس بعدی';
}

try {
    // گرفتن متادیتا: فایل مربوطه و کارشناس صاحب فایل
    $q = $pdo->prepare("
        SELECT c.property_file_id, pf.salesperson
        FROM customers c
        JOIN property_files pf ON pf.id = c.property_file_id
        WHERE c.id = :cid
        LIMIT 1
    ");
    $q->execute(['cid' => $customerId]);
    $meta = $q->fetch(PDO::FETCH_ASSOC);

    if (!$meta) {
        http_response_code(404);
        exit('مشتری/فایل یافت نشد');
    }

    $propertyFileId   = (int)$meta['property_file_id'];
    $fileSalesperson  = (string)$meta['salesperson'];

    // کنترل مالکیت برای sales: فقط روی فایل خودش
    if ($isSales) {
        $fullname = trim((string)($_SESSION['fullname'] ?? ''));
        $username = trim((string)($_SESSION['user'] ?? ''));
        $currentName = $fullname !== '' ? $fullname : $username;

        if (trim($fileSalesperson) !== $currentName) {
            http_response_code(403);
            exit('دسترسی غیرمجاز');
        }
    }

    // --- ثبت تاریخچه تماس‌ها ---
    // اگر ستون‌های جدید وجود داشته باشند، این INSERT موفق می‌شود
    // و اگر وجود نداشت، می‌افتد در catch و نسخه قدیمی را ثبت می‌کند.
    try {
        $ins = $pdo->prepare("
            INSERT INTO contact_reports
              (customer_id, property_file_id, salesperson, report, status, contacted)
            VALUES
              (:cid, :pfid, :sp, :rep, :st, :con)
        ");
        $ins->execute([
            'cid'  => $customerId,
            'pfid' => $propertyFileId,
            'sp'   => $fileSalesperson,
            'rep'  => $report,
            'st'   => $status,
            'con'  => $contacted
        ]);
    } catch (Throwable $e) {
        // fallback: ساختار قدیمی contact_reports
        $ins = $pdo->prepare("
            INSERT INTO contact_reports (customer_id, report, status)
            VALUES (:cid, :rep, :st)
        ");
        $ins->execute([
            'cid' => $customerId,
            'rep' => $report,
            'st'  => $status
        ]);
    }

    // --- به‌روزرسانی رکورد مشتری (آخرین وضعیت) ---
    $upd = $pdo->prepare("
        UPDATE customers
        SET report = :rep,
            contacted = :con,
            updated_at = NOW()
        WHERE id = :cid
    ");
    $upd->execute([
        'rep' => $report,
        'con' => $contacted,
        'cid' => $customerId
    ]);

    // برگشت
    $back = $_SERVER['HTTP_REFERER'] ?? "index.php#collapseFile{$propertyFileId}";
    header("Location: {$back}");
    exit;

} catch (Throwable $e) {
    // لاگ سرور
    error_log("update_status.php ERROR: " . $e->getMessage());

    // برگشت با پیام
    header("Location: index.php?toast=" . urlencode("❌ خطا در ثبت گزارش تماس"));
    exit;
}
