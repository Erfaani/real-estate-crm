<?php
session_start();
require __DIR__ . '/db.php';

if (empty($_SESSION['user']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('فقط مدیر مجاز است.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('درخواست نامعتبر.');
}

if (empty($_POST['csrf']) || $_POST['csrf'] !== ($_SESSION['csrf'] ?? '')) {
    exit('CSRF نامعتبر');
}

$username = trim($_POST['username'] ?? '');
$password = trim($_POST['password'] ?? '');
$role     = trim($_POST['role'] ?? '');

if ($username === '' || $password === '' || $role === '') {
    exit('تمام فیلدها الزامی هستند.');
}

// هش رمز
$hashed = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (:u, :p, :r)");
$stmt->execute(['u' => $username, 'p' => $hashed, 'r' => $role]);

header("Location: users_manage.php");
exit;
