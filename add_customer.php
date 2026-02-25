```php
<?php
/**********************************************
 * add_customer.php (نسخهٔ جدید با کنترل نقش/مالکیت)
 * - نقش‌های مجاز: admin, supervisor, sales, instagram_admin
 * - کارشناس (sales) فقط می‌تواند برای «فایل‌های خودش» مشتری اضافه کند
 * - CSRF و PDO
 **********************************************/

declare(strict_types=1);
session_start();
require __DIR__ . '/db.php';

// نیاز به ورود
if (empty($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

$role      = $_SESSION['role'] ?? '';
$fullname  = trim((string)($_SESSION['fullname'] ?? ''));
$username  = trim((string)($_SESSION['user'] ?? ''));

$isAdmin      = ($role === 'admin');
$isSupervisor = ($role === 'supervisor');
$isSales      = ($role === 'sales');
$isInstagram  = ($role === 'instagram_admin');

// فقط نقش‌های فوق مجازند
if (!($isAdmin || $isSupervisor || $isSales || $isInstagram)) {
    http_response_code(403);
    exit('دسترسی غیرمجاز');
}

// فقط POST معتبر
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('درخواست نامعتبر.');
}

// CSRF
$csrf = $_POST['csrf'] ?? '';
if ($csrf === '' || $csrf !== ($_SESSION['csrf'] ?? '')) {
    http_response_code(400);
    exit('CSRF نامعتبر');
}

// ورودی‌ها
$fileId = (int)($_POST['file_id'] ?? 0);
$name   = trim((string)($_POST['name'] ?? ''));
$phone  = trim((string)($_POST['phone'] ?? ''));

if ($fileId <= 0 || $name === '' || $phone === '') {
    http_response_code(400);
    exit('همه فیلدها باید پر باشن.');
}

// اگر نقش کارشناس است، باید صاحب همین فایل باشد
if ($isSales) {
    $q = $pdo->prepare("SELECT salesperson FROM property_files WHERE id = :id LIMIT 1");
    $q->execute(['id' => $fileId]);
    $salesperson = (string)$q->fetchColumn();
    if ($salesperson === '') {
        http_response_code(404);
        exit('فایل ملکی یافت نشد.');
    }
    $ownerName = ($fullname !== '') ? $fullname : $username;
    if (trim($salesperson) !== $ownerName) {
        http_response_code(403);
        exit('شما فقط می‌توانید برای فایل‌های خودتان مشتری اضافه کنید.');
    }
}

// درج مشتری
$stmt = $pdo->prepare("
    INSERT INTO customers (property_file_id, name, phone, report, contacted, updated_at)
    VALUES (:fid, :n, :p, '', 0, NOW())
");
$stmt->execute([
    'fid' => $fileId,
    'n'   => $name,
    'p'   => $phone
]);

// بازگشت به همان فایل و بازکردن بخش مشتری‌ها
header("Location: index.php#collapseFile{$fileId}");
exit;
```
