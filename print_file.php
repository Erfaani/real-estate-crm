<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/db.php';

if (empty($_SESSION['user'])) { exit('نیاز به ورود'); }

$role     = (string)($_SESSION['role'] ?? '');
$fullname = trim((string)($_SESSION['fullname'] ?? ''));
$username = trim((string)($_SESSION['user'] ?? ''));

$isAdmin      = ($role === 'admin');
$isSupervisor = ($role === 'supervisor');
$isSales      = ($role === 'sales');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { exit('شناسه معتبر نیست'); }

$currentName = $fullname !== '' ? $fullname : $username;

// --- فایل + مشخصات (JOIN) ---
$canPrivateSql = $isAdmin ? "1=1" : "(pf.salesperson = :currentName)";
$params = ['id' => $id];
if (!$isAdmin) $params['currentName'] = $currentName;

$sql = "
SELECT
  pf.*,
  pd.document_status, pd.area, pd.floor, pd.price, pd.year_built, pd.unit_in_floor,
  pd.rooms, pd.storage_area, pd.parking_area, pd.yard_area, pd.file_type,
  pd.register_status,

  CASE WHEN $canPrivateSql THEN pd.location2 ELSE NULL END AS location2,
  CASE WHEN $canPrivateSql THEN pd.owner_info ELSE NULL END AS owner_info
FROM property_files pf
LEFT JOIN property_details pd ON pd.property_file_id = pf.id
WHERE pf.id = :id
LIMIT 1
";
$stm = $pdo->prepare($sql);
$stm->execute($params);
$row = $stm->fetch(PDO::FETCH_ASSOC);

if (!$row) { exit('فایل یافت نشد'); }

// مالکیت
$ownsFile = (trim((string)$row['salesperson']) === $currentName);

// نمایش شماره مشتری؟
$canSeePhones = $isAdmin || ($isSales && $ownsFile) || ($isSupervisor && $ownsFile);

// نمایش فیلدهای محرمانه (آدرس دقیق + مشخصات مالک)
// طبق خواسته شما: فقط ادمین یا کارشناسِ صاحب فایل
$canSeePrivate = $isAdmin || ($isSales && $ownsFile);

// مشتری‌ها
$cust = $pdo->prepare("
  SELECT name, phone, report, contacted
  FROM customers
  WHERE property_file_id = :id
  ORDER BY id DESC
");
$cust->execute(['id' => $id]);
$customers = $cust->fetchAll(PDO::FETCH_ASSOC);

// برای راحتی نام‌گذاری
$file = $row;
$details = $row;
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <title>پرینت فایل #<?= htmlspecialchars((string)$file['code']) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    @page { size: A4; margin: 14mm; }
    body { font-family: tahoma, sans-serif; color:#222; }

    .header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 12px;
      animation: fadeIn 1s ease-in-out;
    }
    .logo { height: 60px; }
    h1 { font-size: 18px; margin: 0; }
    .small { color: #666; font-size: 12px; }

    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 10px;
      animation: fadeIn 2s ease-in-out;
    }
    th, td {
      border: 1px solid #999;
      padding: 6px 8px;
      font-size: 13px;
      text-align: right;
      vertical-align: top;
    }
    th { background: #eee; }

    .section {
      margin-top: 16px;
      font-weight: 600;
      font-size: 16px;
      color: #db6534;
    }
    .grid {
      width: 100%;
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 8px;
    }

    .footer {
      margin-top: 18px;
      font-size: 12px;
      color: #666;
      display: flex;
      justify-content: space-between;
    }
    .nowrap { white-space: nowrap; }
    .muted { color: #888; }

    @keyframes fadeIn {
      0% { opacity: 0; transform: translateY(20px); }
      100% { opacity: 1; transform: translateY(0); }
    }

    tr:hover {
      background-color: #f9f9f9;
      cursor: pointer;
      transition: background-color 0.3s ease;
    }

    table.customers th, table.customers td { padding: 10px; }
    table.customers thead { background-color: #f1f1f1; }
  </style>
</head>
<body onload="window.print()">
  <div class="header">
    <img class="logo" src="assets/logo.png" alt="لوگو">
    <div>
      <h1>گزارش فایل ملکی</h1>
      <div class="small">
        کد: <?= htmlspecialchars((string)$file['code']) ?>
        | کارشناس: <?= htmlspecialchars((string)$file['salesperson']) ?>
        | تاریخ: <?= date('Y/m/d') ?>
      </div>
    </div>
  </div>

  <div class="section">مشخصات و ویژگی‌ها</div>
  <table class="grid">
    <tbody>
      <tr>
        <th>موقعیت</th>
        <td colspan="5"><?= htmlspecialchars((string)($file['pf_city'] ?? '')) ?><?= ($file['pf_area'] ? ' - ' . htmlspecialchars((string)$file['pf_area']) : '') ?></td>
      </tr>
      <tr>
        <th>وضعیت سند</th><td><?= htmlspecialchars((string)($details['document_status'] ?? '')) ?></td>
        <th>متراژ</th><td><?= htmlspecialchars((string)($details['area'] ?? '')) ?></td>
        <th>طبقه</th><td><?= htmlspecialchars((string)($details['floor'] ?? '')) ?></td>
      </tr>
      <tr>
        <th>قیمت</th><td><?= htmlspecialchars((string)($details['price'] ?? '')) ?></td>
        <th>سال ساخت</th><td><?= htmlspecialchars((string)($details['year_built'] ?? '')) ?></td>
        <th>واحد در طبقه</th><td><?= htmlspecialchars((string)($details['unit_in_floor'] ?? '')) ?></td>
      </tr>
      <tr>
        <th>خواب</th><td><?= htmlspecialchars((string)($details['rooms'] ?? '')) ?></td>
        <th>متراژ انباری</th><td><?= htmlspecialchars((string)($details['storage_area'] ?? '')) ?></td>
        <th>متراژ پارکینگ</th><td><?= htmlspecialchars((string)($details['parking_area'] ?? '')) ?></td>
      </tr>
      <tr>
        <th>متراژ حیاط</th><td><?= htmlspecialchars((string)($details['yard_area'] ?? '')) ?></td>
        <th>نوع فایل</th><td><?= htmlspecialchars((string)($details['file_type'] ?? '')) ?></td>
        <th>کد</th><td><?= htmlspecialchars((string)($details['code'] ?? $file['code'])) ?></td>
      </tr>

      <tr>
        <th>آدرس دقیق (لوکیشن۲)</th>
        <td colspan="5">
          <?php if ($canSeePrivate): ?>
            <?= nl2br(htmlspecialchars((string)($details['location2'] ?? '—'))) ?>
          <?php else: ?>
            <span class="muted">محرمانه</span>
          <?php endif; ?>
        </td>
      </tr>

      <tr>
        <th>مشخصات مالک</th>
        <td colspan="3">
          <?php if ($canSeePrivate): ?>
            <?= nl2br(htmlspecialchars((string)($details['owner_info'] ?? '—'))) ?>
          <?php else: ?>
            <span class="muted">محرمانه</span>
          <?php endif; ?>
        </td>
        <th>تأییدیه ثبت</th>
        <td><?= htmlspecialchars((string)($details['register_status'] ?? '')) ?></td>
      </tr>
    </tbody>
  </table>

  <div class="section">لیست مشتریان و تماس‌ها</div>
  <table class="customers">
    <thead>
      <tr>
        <th>نام</th>
        <th>شماره</th>
        <th>آخرین گزارش</th>
        <th class="nowrap">وضعیت تماس</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($customers as $c): ?>
        <tr>
          <td><?= htmlspecialchars((string)$c['name']) ?></td>
          <td>
            <?php if ($canSeePhones): ?>
              <?= htmlspecialchars((string)$c['phone']) ?>
            <?php else: ?>
              <span class="muted">محرمانه</span>
            <?php endif; ?>
          </td>
          <td><?= nl2br(htmlspecialchars((string)($c['report'] ?? ''))) ?></td>
          <td><?= !empty($c['contacted']) ? 'گرفته شد' : 'منتظر تماس' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$customers): ?>
        <tr><td colspan="4" class="muted">مشتری ثبت نشده است.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>

  <div class="footer">
    <div>سیستم CRM ملک و معمار</div>
    <div>تاریخ چاپ: <?= date('Y/m/d H:i') ?></div>
  </div>
</body>
</html>
