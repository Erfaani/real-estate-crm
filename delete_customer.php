<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/db.php';

if (empty($_SESSION['user'])) { header('Location: login.php'); exit; }
if (($_SESSION['role'] ?? '') !== 'admin') { http_response_code(403); exit('دسترسی غیرمجاز'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
if (empty($_POST['csrf']) || $_POST['csrf'] !== ($_SESSION['csrf'] ?? '')) { exit('CSRF نامعتبر'); }

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { exit('شناسه معتبر نیست'); }

$stm = $pdo->prepare("DELETE FROM customers WHERE id=:id");
$stm->execute(['id'=>$id]);

header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
exit;
