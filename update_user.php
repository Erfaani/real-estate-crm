<?php
/********************************************
 * update_user.php — تغییر نقش کاربر (فقط مدیر)
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

$id   = (int)($_POST['id'] ?? 0);
$role = trim((string)($_POST['role'] ?? ''));

$allowedRoles = ['admin','supervisor','sales','instagram_admin'];
if ($id <= 0 || !in_array($role, $allowedRoles, true)) {
    http_response_code(400);
    exit('اطلاعات نامعتبر.');
}

$stm = $pdo->prepare("UPDATE users SET role = :r WHERE id = :id");
$stm->execute(['r' => $role, 'id' => $id]);

header("Location: users_manage.php");
exit;
