```php
<?php
/********************************************
 * edit_customer.php (نسخهٔ جدید با سطح دسترسی)
 * - فقط مدیر (admin) و ادمین اینستاگرام (instagram_admin)
 *   می‌توانند مشتری را ویرایش کنند.
 ********************************************/
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

declare(strict_types=1);
session_start();
require __DIR__ . '/db.php';

// بررسی لاگین
if (empty($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

// نقش‌ها
$role = $_SESSION['role'] ?? '';
$isAdmin = ($role === 'admin');
$isInstagram = ($role === 'instagram_admin');

// فقط مدیر و ادمین اینستاگرام مجاز هستند
if (!($isAdmin || $isInstagram)) {
    http_response_code(403);
    exit('دسترسی غیرمجاز');
}

// بررسی متد
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('درخواست نامعتبر.');
}

// بررسی CSRF
$csrf = $_POST['csrf'] ?? '';
if ($csrf === '' || $csrf !== ($_SESSION['csrf'] ?? '')) {
    http_response_code(400);
    exit('CSRF نامعتبر');
}

// ورودی‌ها
$id    = (int)($_POST['id'] ?? 0);
$name  = trim((string)($_POST['name'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));

if ($id <= 0 || $name === '' || $phone === '') {
    http_response_code(400);
    exit('همه فیلدها باید پر باشن.');
}

// بروزرسانی
$stmt = $pdo->prepare("
    UPDATE customers 
    SET name = :n, phone = :p, updated_at = NOW()
    WHERE id = :id
");
$stmt->execute([
    'n' => $name,
    'p' => $phone,
    'id' => $id
]);

// بازگشت
$back = $_SERVER['HTTP_REFERER'] ?? 'index.php';
header("Location: {$back}");
exit;
```
