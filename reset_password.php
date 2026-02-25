<?php
/********************************************
 * reset_password.php — تغییر رمز کاربر (فقط مدیر)
 ********************************************/
declare(strict_types=1);
session_start();
require __DIR__ . '/db.php';

// فقط مدیر
if (empty($_SESSION['user']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('دسترسی غیرمجاز');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('درخواست نامعتبر.');
}

// CSRF
if (empty($_POST['csrf']) || $_POST['csrf'] !== ($_SESSION['csrf'] ?? '')) {
    http_response_code(400);
    exit('CSRF نامعتبر');
}

// ورودی‌ها
$id = (int)($_POST['id'] ?? 0);
$pass = trim((string)($_POST['password'] ?? ''));

if ($id <= 0 || $pass === '' || mb_strlen($pass) < 6) {
    http_response_code(400);
    exit('رمز جدید معتبر نیست (حداقل ۶ کاراکتر).');
}

// هش رمز
$hash = password_hash($pass, PASSWORD_DEFAULT);

// بروزرسانی
$stm = $pdo->prepare("UPDATE users SET password = :p WHERE id = :id");
$stm->execute(['p' => $hash, 'id' => $id]);

header("Location: users_manage.php");
exit;
