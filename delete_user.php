<?php
session_start();
require __DIR__ . '/db.php';

if (empty($_SESSION['user']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('دسترسی غیرمجاز.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('درخواست نامعتبر.');
}

if (empty($_POST['csrf']) || $_POST['csrf'] !== ($_SESSION['csrf'] ?? '')) {
    exit('CSRF نامعتبر');
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    exit('شناسه نامعتبر.');
}

// حذف کاربر بدون حذف فایل‌ها
$pdo->prepare("DELETE FROM users WHERE id = :id")->execute(['id' => $id]);

header("Location: users_manage.php");
exit;
