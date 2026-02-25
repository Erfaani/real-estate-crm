<?php
declare(strict_types=1);

// لاگ خطا مثل index.php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');

session_start();
require __DIR__ . '/db.php';

if (empty($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

$role = $_SESSION['role'] ?? '';
$isAdmin = ($role === 'admin');

if (!$isAdmin) {
    http_response_code(403);
    exit('فقط مدیر مجاز به حذف فایل ملکی است.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('درخواست نامعتبر.');
}

$csrf = $_POST['csrf'] ?? '';
if ($csrf === '' || $csrf !== ($_SESSION['csrf'] ?? '')) {
    http_response_code(400);
    exit('CSRF نامعتبر');
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('شناسه نامعتبر است.');
}

try {
    $check = $pdo->prepare("SELECT id FROM property_files WHERE id = :id LIMIT 1");
    $check->execute(['id' => $id]);

    if (!$check->fetchColumn()) {
        header("Location: index.php?toast=" . urlencode("فایل ملکی پیدا نشد"));
        exit;
    }

    $del = $pdo->prepare("DELETE FROM property_files WHERE id = :id");
    $del->execute(['id' => $id]);

    header("Location: index.php?toast=" . urlencode("✅ فایل ملکی با موفقیت حذف شد"));
    exit;

} catch (Throwable $e) {
    error_log("delete_file.php ERROR: " . $e->getMessage());
    header("Location: index.php?toast=" . urlencode("❌ خطا در حذف فایل ملکی"));
    exit;
}
