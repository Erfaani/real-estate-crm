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

$role     = (string)($_SESSION['role'] ?? '');
$fullname = trim((string)($_SESSION['fullname'] ?? ''));
$username = trim((string)($_SESSION['user'] ?? ''));

$isAdmin = ($role === 'admin');
$isSales = ($role === 'sales');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('درخواست نامعتبر.');
}

$csrf = (string)($_POST['csrf'] ?? '');
if ($csrf === '' || $csrf !== (string)($_SESSION['csrf'] ?? '')) {
    http_response_code(400);
    exit('CSRF نامعتبر');
}

$id          = (int)($_POST['id'] ?? 0);
$code        = trim((string)($_POST['code'] ?? ''));
$salesperson = trim((string)($_POST['salesperson'] ?? ''));

if ($id <= 0 || $code === '') {
    http_response_code(400);
    exit('اطلاعات ناقص است.');
}

try {
    // فایل موجود؟
    $stm = $pdo->prepare("SELECT id, code, salesperson FROM property_files WHERE id = :id LIMIT 1");
    $stm->execute(['id' => $id]);
    $file = $stm->fetch(PDO::FETCH_ASSOC);

    if (!$file) {
        http_response_code(404);
        exit('فایل یافت نشد.');
    }

    $ownerName = ($fullname !== '') ? $fullname : $username;
    $ownsFile  = (trim((string)$file['salesperson']) === $ownerName);

    // مجوز
    if (!($isAdmin || ($isSales && $ownsFile))) {
        http_response_code(403);
        exit('دسترسی غیرمجاز');
    }

    // اگر ادمین است، salesperson الزامی است
    if ($isAdmin && $salesperson === '') {
        http_response_code(400);
        exit('نام کارشناس فروش الزامی است.');
    }

    // چک نرم: کد تکراری نباشد (به جز همین رکورد)
    $chk = $pdo->prepare("SELECT id FROM property_files WHERE code = :c AND id <> :id LIMIT 1");
    $chk->execute(['c' => $code, 'id' => $id]);
    if ($chk->fetchColumn()) {
        header("Location: index.php?toast=" . urlencode("❌ این کد فایل قبلاً ثبت شده است"));
        exit;
    }

    // آپدیت
    if ($isAdmin) {
        $upd = $pdo->prepare("UPDATE property_files SET code = :c, salesperson = :s WHERE id = :id");
        $upd->execute(['c' => $code, 's' => $salesperson, 'id' => $id]);
    } else {
        // sales فقط code را تغییر می‌دهد
        $upd = $pdo->prepare("UPDATE property_files SET code = :c WHERE id = :id");
        $upd->execute(['c' => $code, 'id' => $id]);
    }

} catch (PDOException $e) {
    // خطای UNIQUE در DB (اگر همزمان کسی همین code را ثبت کرد)
    if ((int)$e->getCode() === 23000) {
        header("Location: index.php?toast=" . urlencode("❌ این کد فایل قبلاً ثبت شده است"));
        exit;
    }

    error_log("edit_file.php PDOException: " . $e->getMessage());
    header("Location: index.php?toast=" . urlencode("❌ خطا در ویرایش فایل"));
    exit;

} catch (Throwable $e) {
    error_log("edit_file.php ERROR: " . $e->getMessage());
    header("Location: index.php?toast=" . urlencode("❌ خطا در ویرایش فایل"));
    exit;
}

// برگشت مطمئن به همان فایل
header("Location: index.php#collapseFile{$id}");
exit;
