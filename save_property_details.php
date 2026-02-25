<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/db.php';

if (empty($_SESSION['user'])) { header('Location: login.php'); exit; }

$role      = (string)($_SESSION['role'] ?? '');
$fullname  = trim((string)($_SESSION['fullname'] ?? ''));
$username  = trim((string)($_SESSION['user'] ?? ''));

$isAdmin      = ($role === 'admin');
$isSupervisor = ($role === 'supervisor');
$isSales      = ($role === 'sales');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
if (empty($_POST['csrf']) || $_POST['csrf'] !== ($_SESSION['csrf'] ?? '')) { exit('CSRF نامعتبر'); }

$pid = (int)($_POST['property_file_id'] ?? 0);
if ($pid <= 0) { exit('شناسه فایل معتبر نیست'); }

// --- دریافت فایل و مالکیت ---
$stm = $pdo->prepare("SELECT id, salesperson FROM property_files WHERE id = :id LIMIT 1");
$stm->execute(['id' => $pid]);
$file = $stm->fetch(PDO::FETCH_ASSOC);
if (!$file) { exit('فایل یافت نشد'); }

$currentName = $fullname !== '' ? $fullname : $username;
$ownsFile = (trim((string)$file['salesperson']) === $currentName);

// --- RBAC کلی: چه کسی حق ذخیره دارد؟ ---
if (!($isAdmin || $isSupervisor || ($isSales && $ownsFile))) {
  http_response_code(403);
  exit('دسترسی غیرمجاز');
}

// --- RBAC فیلدهای محرمانه (آدرس دقیق و مشخصات مالک) ---
// طبق خواسته شما: فقط admin و کارشناسِ صاحب فایل
$canEditPrivate = ($isAdmin || ($isSales && $ownsFile) || (!$isSales && $ownsFile)); 
// نکته: اگر supervisor هم می‌تونه مالک فایل باشد، با ownsFile مجاز می‌شود.
// اگر می‌خواهی supervisor حتی اگر مالک بود هم نتواند، خط بالا را فقط admin یا (sales && ownsFile) کن.

// --- جمع‌آوری داده‌های عمومی ---
$publicFields = [
  'document_status','area','floor','price','year_built','unit_in_floor',
  'rooms','storage_area','parking_area','yard_area','file_type',
  'code','register_status'
];

$data = [];
foreach ($publicFields as $f) {
  $data[$f] = trim((string)($_POST[$f] ?? ''));
}

// --- فیلدهای خصوصی ---
$location2 = trim((string)($_POST['location2'] ?? ''));  // آدرس دقیق
$ownerInfo = trim((string)($_POST['owner_info'] ?? ''));

// اگر کاربر مجاز نیست، مقدار قبلی را حفظ می‌کنیم (از DB می‌خوانیم و override می‌کنیم)
if (!$canEditPrivate) {
  $stmPriv = $pdo->prepare("SELECT location2, owner_info FROM property_details WHERE property_file_id = :id LIMIT 1");
  $stmPriv->execute(['id' => $pid]);
  $prev = $stmPriv->fetch(PDO::FETCH_ASSOC) ?: [];
  $location2 = (string)($prev['location2'] ?? '');
  $ownerInfo = (string)($prev['owner_info'] ?? '');
}

// --- UPSERT (با UNIQUE(property_file_id)) ---
// توجه: این کوئری نیاز دارد UNIQUE KEY روی property_file_id وجود داشته باشد.
$sql = "
INSERT INTO property_details
(
  property_file_id,
  document_status, area, floor, price, year_built, unit_in_floor,
  rooms, storage_area, parking_area, yard_area,
  owner_info, location2,
  file_type, code, register_status
)
VALUES
(
  :pid,
  :document_status, :area, :floor, :price, :year_built, :unit_in_floor,
  :rooms, :storage_area, :parking_area, :yard_area,
  :owner_info, :location2,
  :file_type, :code, :register_status
)
ON DUPLICATE KEY UPDATE
  document_status = VALUES(document_status),
  area           = VALUES(area),
  floor          = VALUES(floor),
  price          = VALUES(price),
  year_built     = VALUES(year_built),
  unit_in_floor  = VALUES(unit_in_floor),
  rooms          = VALUES(rooms),
  storage_area   = VALUES(storage_area),
  parking_area   = VALUES(parking_area),
  yard_area      = VALUES(yard_area),
  owner_info     = VALUES(owner_info),
  location2      = VALUES(location2),
  file_type      = VALUES(file_type),
  code           = VALUES(code),
  register_status= VALUES(register_status)
";

$params = $data + [
  'pid'        => $pid,
  'owner_info' => $ownerInfo,
  'location2'  => $location2,
];

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

header("Location: index.php#collapseFile{$pid}");
exit;
