<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/db.php';

if (empty($_SESSION['user'])) { header('Location: login.php'); exit; }

$role = (string)($_SESSION['role'] ?? '');
if (!in_array($role, ['admin','supervisor','sales'], true)) {
  http_response_code(403);
  exit('دسترسی غیرمجاز');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }

if (empty($_POST['csrf']) || $_POST['csrf'] !== ($_SESSION['csrf'] ?? '')) {
  exit('CSRF نامعتبر');
}

// --- ورودی‌ها ---
$code = trim((string)($_POST['code'] ?? ''));
$salesperson = trim((string)($_POST['salesperson'] ?? ''));

// --- اعتبارسنجی ---
if ($code === '') { exit('کد فایل الزامی است'); }

// اگر کارشناس است، نام را از سشن بگیر (اجباری)
if ($role === 'sales') {
  $salesperson = trim((string)(($_SESSION['fullname'] ?? '') ?: ($_SESSION['user'] ?? '')));
}
if ($salesperson === '') {
  exit('نام کارشناس معتبر نیست');
}

try {
  // --- چک نرم برای تکراری بودن کد (UX بهتر) ---
  $stm = $pdo->prepare("SELECT id FROM property_files WHERE code = :c LIMIT 1");
  $stm->execute(['c' => $code]);
  if ($stm->fetchColumn()) {
    exit('این کد فایل قبلاً ثبت شده است');
  }

  // --- ثبت فایل ---
  $stmt = $pdo->prepare("
    INSERT INTO property_files (code, salesperson, created_at)
    VALUES (:c, :s, NOW())
  ");
  $stmt->execute([
    'c' => $code,
    's' => $salesperson,
  ]);

  $newId = (int)$pdo->lastInsertId();

  // --- (اختیاری ولی توصیه‌شده) ایجاد رکورد خالی در property_details ---
  // اگر UNIQUE(property_file_id) داری، این باعث میشه فرم جزئیات همیشه آماده باشه.
  $stmt2 = $pdo->prepare("
    INSERT INTO property_details (property_file_id)
    VALUES (:pid)
  ");
  $stmt2->execute(['pid' => $newId]);

  header("Location: index.php#collapseFile{$newId}");
  exit;

} catch (PDOException $e) {
  // اگر به هر دلیل UNIQUE در DB خطا داد
  if ((int)$e->getCode() === 23000) {
    exit('این کد فایل قبلاً ثبت شده است');
  }

  // لاگ کن، ولی به کاربر جزئیات نده
  error_log('add_file PDOException: ' . $e->getMessage());
  exit('خطا در ثبت فایل. با پشتیبانی تماس بگیرید.');
}
