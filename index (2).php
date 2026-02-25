<?php
/***********************
 * index.php (RBAC + Details JOIN + PDF) — FULL FIXED
 * - LEFT JOIN to property_details
 * - HY093 safe: no duplicate named placeholders
 * - location2 + owner_info only for admin OR owner (SQL + UI)
 * - session ini_set BEFORE session_start
 ***********************/
declare(strict_types=1);

// --- خطاها (در تولید: نمایش خاموش، لاگ روشن) ---
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');

// --- امنیت نشست (قبل از session_start) ---
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
// اگر HTTPS داری فعال کن:
// ini_set('session.cookie_secure', '1');

session_start();

require __DIR__ . '/db.php';

// --- نیاز به ورود ---
if (empty($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

// --- CSRF ---
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$CSRF = $_SESSION['csrf'];

// --- نقش‌ها ---
$role        = $_SESSION['role'] ?? '';
$fullname    = trim((string)($_SESSION['fullname'] ?? ''));
$username    = trim((string)($_SESSION['user'] ?? ''));
$userId      = (int)($_SESSION['user_id'] ?? 0);

$isAdmin       = ($role === 'admin');
$isSupervisor  = ($role === 'supervisor');
$isSales       = ($role === 'sales');
$isInstagram   = ($role === 'instagram_admin');

$currentName = ($fullname !== '') ? $fullname : $username;

// --- هدرهای امنیتی ---
header("X-Frame-Options: SAMEORIGIN");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");


// --- ورودی‌ها ---
$search  = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
$page    = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 20;
$offset  = ($page - 1) * $perPage;

// --- شمارش کل ---
$countSql = "SELECT COUNT(*) FROM property_files";
$countParams = [];
if ($search !== '') {
    // HY093 safe: placeholders جدا
    $countSql .= " WHERE code LIKE :cs1 OR salesperson LIKE :cs2";
    $countParams['cs1'] = "%{$search}%";
    $countParams['cs2'] = "%{$search}%";
}
$stmCount = $pdo->prepare($countSql);
$stmCount->execute($countParams);
$totalRows  = (int)$stmCount->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// --- لیست فایل‌ها (JOIN با details) ---
// HY093 safe: هیچ placeholder تکراری نداریم (admin1/admin2 و owner1/owner2)
$listParams = [
    'admin1' => $isAdmin ? 1 : 0,
    'admin2' => $isAdmin ? 1 : 0,
    'owner1' => $currentName,
    'owner2' => $currentName,
];

$listSql = "
SELECT
    pf.id,
    pf.code,
    pf.salesperson,
    pf.created_at,
    pf.pf_status,
    pf.pf_city,
    pf.pf_area,
    CONCAT_WS(' - ', pf.pf_city, pf.pf_area) AS location,

    pd.document_status,
    pd.area,
    pd.floor,
    pd.price,
    pd.year_built,
    pd.unit_in_floor,
    pd.rooms,
    pd.storage_area,
    pd.parking_area,
    pd.yard_area,
    pd.file_type,
    pd.register_status,

    CASE WHEN (:admin1 = 1 OR pf.salesperson = :owner1) THEN pd.location2 ELSE NULL END AS location2,
    CASE WHEN (:admin2 = 1 OR pf.salesperson = :owner2) THEN pd.owner_info ELSE NULL END AS owner_info

FROM property_files pf
LEFT JOIN property_details pd ON pd.property_file_id = pf.id
";

if ($search !== '') {
    // HY093 safe: placeholders جدا
    $listSql .= " WHERE pf.code LIKE :s1 OR pf.salesperson LIKE :s2";
    $listParams['s1'] = "%{$search}%";
    $listParams['s2'] = "%{$search}%";
}

// LIMIT/OFFSET را عددی بگذار (سازگارتر روی MariaDB + PDO)
$limit = (int)$perPage;
$off   = (int)$offset;
$listSql .= " ORDER BY pf.created_at DESC LIMIT {$limit} OFFSET {$off}";

$stmFiles = $pdo->prepare($listSql);
$stmFiles->execute($listParams);
$files = $stmFiles->fetchAll(PDO::FETCH_ASSOC);


// --- مشتری‌ها و شمارش تماس‌های امروز ---
$customersByFile = [];
$todayMap = [];

if (!empty($files)) {
    $ids = array_map('intval', array_column($files, 'id'));
    $ph  = implode(',', array_fill(0, count($ids), '?'));

    // همه مشتری‌ها
    $stmCust = $pdo->prepare("
        SELECT id, property_file_id, name, phone, report, contacted, updated_at
        FROM customers
        WHERE property_file_id IN ($ph)
        ORDER BY id DESC
    ");
    $stmCust->execute($ids);
    foreach ($stmCust as $row) {
        $customersByFile[(int)$row['property_file_id']][] = $row;
    }

    // تماس‌های امروز
    $todayStart = date('Y-m-d 00:00:00');
    $todayEnd   = date('Y-m-d 23:59:59');

    $stmToday = $pdo->prepare("
        SELECT property_file_id, COUNT(*) AS c
        FROM customers
        WHERE contacted = 1
          AND updated_at BETWEEN ? AND ?
          AND property_file_id IN ($ph)
        GROUP BY property_file_id
    ");
    $stmToday->execute(array_merge([$todayStart, $todayEnd], $ids));
    foreach ($stmToday as $r) {
        $todayMap[(int)$r['property_file_id']] = (int)$r['c'];
    }
}

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>مدیریت فایل‌های ملکی</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <link rel="stylesheet" href="assets/style.css">
  <style>
    :root { --main-orange:#db6534; --hover-orange:#b34f29; }
    body { background:#fafafa; }
    .header-logo { height:45px; }
    .rotate-icon { transition:transform .2s ease; color:var(--main-orange); }
    .rotate-icon.open { transform:rotate(180deg); }
    .card { border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,.05); }
    .card-header { background:#fff; border-bottom:2px solid var(--main-orange); }
    .badge { font-size:.85rem; }
    .pagination .page-link { border-radius:10px; }
    .muted { color:#6c757d; }
    .section-title { border-right:4px solid var(--main-orange); padding-right:.5rem; margin:1rem 0 .75rem; font-weight:600; }
  </style>
</head>
<body>
<div class="container my-4">

  <!-- Header -->
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div class="d-flex align-items-center gap-2">
      <img src="assets/logo.png" class="header-logo" alt="لوگو">
      <h5 class="mb-0">📁 مدیریت فایل‌های ملکی</h5>
    </div>

    <div class="d-flex gap-2">
      <?php if ($isAdmin || $isSupervisor): ?>
        <a href="attendance.php" class="btn btn-outline-secondary">🗓 حضور و غیاب</a>
      <?php endif; ?>
      <?php if ($isAdmin): ?>
        <a href="report.php" class="btn btn-outline-primary">📊 داشبورد مدیریت</a>
      <?php endif; ?>

      <a href="advanced_search.php" class="btn btn-outline-success">🔎 جست‌وجوی پیشرفته</a>
      <a href="logout.php" class="btn btn-outline-danger">خروج</a>
    </div>
  </div>

  <!-- Search -->
  <form method="GET" class="mb-3">
    <div class="input-group flex-wrap">
      <input type="text" name="search" class="form-control mb-2 mb-md-0"
             placeholder="جستجوی کد فایل یا کارشناس..."
             value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
      <button class="btn btn-primary ms-md-2 mb-2 mb-md-0" type="submit">🔍 جستجو</button>
      <a href="index.php" class="btn btn-success">📄 همه فایل‌ها</a>
    </div>
  </form>

  <!-- Add File -->
  <?php if ($isAdmin || $isSupervisor || $isSales): ?>
    <form method="POST" action="add_file.php" class="mb-4">
      <input type="hidden" name="csrf" value="<?= $CSRF ?>">
      <div class="row g-2">
        <div class="col-12 col-md-5">
          <input type="text" name="code" class="form-control" placeholder="کد فایل جدید" required>
        </div>
        <div class="col-12 col-md-5">
          <input type="text" name="salesperson" class="form-control"
                 placeholder="کارشناس فروش"
                 value="<?= htmlspecialchars($currentName) ?>"
                 <?= $isSales ? 'readonly' : '' ?>
                 required>
        </div>
        <div class="col-12 col-md-2">
          <button class="btn btn-primary w-100" type="submit">➕ افزودن فایل</button>
        </div>
      </div>
    </form>
  <?php endif; ?>

  <?php if (empty($files)): ?>
    <div class="alert alert-info">هیچ فایلی یافت نشد.</div>
  <?php endif; ?>

  <?php $modalBucket = []; ?>

  <?php foreach ($files as $file): ?>
    <?php
      $fid = (int)$file['id'];
      $customers = $customersByFile[$fid] ?? [];
      $todayCalls = $todayMap[$fid] ?? 0;
      $totalCustomers = count($customers);

      $ownsFile = (trim((string)$file['salesperson']) === $currentName);

      $canSeePhonesForFile  = $isAdmin || ($isSales && $ownsFile) || ($isSupervisor && $ownsFile);
      $canSeePrivateForFile = $isAdmin || $ownsFile;

      $hasDetailsRow = !empty($file['document_status'])
                    || !empty($file['area'])
                    || !empty($file['price'])
                    || !empty($file['year_built'])
                    || !empty($file['file_type'])
                    || !empty÷÷÷($file['rooms']);
    ?>÷

    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
        <div class="me-2">
          📌 <strong><?= htmlspecialchars((string)$file['code']) ?></strong>
          | 👤 <?= htmlspecialchars((string)$file['salesperson']) ?>
          | 🗓 <?= htmlspecialchars(date('Y/m/d', strtotime((string)$file['created_at']))) ?>
        </div>

        <div class="d-flex align-items-center flex-wrap gap-2 mt-2 mt-md-0">
          <span class="badge bg-success">تماس امروز: <?= $todayCalls ?></span>
          <span class="badge bg-secondary">👥 مشتری: <?= $totalCustomers ?></span>

          <a href="print_file.php?id=<?= $fid ?>" target="_blank" class="btn btn-sm btn-success">🖨️ پرینت PDF</a>

          <?php if ($isAdmin): ?>
            <form method="POST" action="delete_file.php" class="d-inline" onsubmit="return confirm('حذف این فایل ملکی؟');">
              <input type="hidden" name="id" value="<?= $fid ?>">
              <input type="hidden" name="csrf" value="<?= $CSRF ?>">
              <button class="btn btn-sm btn-outline-danger" type="submit">🗑 حذف</button>
            </form>
          <?php endif; ?>

          <?php if ($isAdmin): ?>
            <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#editFile<?= $fid ?>">✏️ ویرایش</button>
          <?php endif; ?>

          <button class="btn btn-sm btn-outline-primary d-flex align-items-center gap-1"
                  data-bs-toggle="collapse"
                  data-bs-target="#collapseFile<?= $fid ?>"
                  aria-expanded="false"
                  aria-controls="collapseFile<?= $fid ?>">
            لیست مشتری‌ها <span class="rotate-icon ms-1">&#9660;</span>
          </button>
        </div>
      </div>

      <div id="collapseFile<?= $fid ?>" class="collapse">
        <div class="card-body">

          <div class="section-title">📋 مشخصات و ویژگی‌ها</div>

          <?php if ($hasDetailsRow): ?>
            <div class="table-responsive mb-3">
              <table class="table table-bordered align-middle">
                <tbody>
                  <tr>
                    <th>موقعیت</th>
                    <td colspan="5"><?= htmlspecialchars((string)($file['location'] ?? '')) ?></td>
                  </tr>

                  <tr>
                    <th>وضعیت سند</th><td><?= htmlspecialchars((string)($file['document_status'] ?? '')) ?></td>
                    <th>متراژ</th><td><?= htmlspecialchars((string)($file['area'] ?? '')) ?></td>
                    <th>طبقه</th><td><?= htmlspecialchars((string)($file['floor'] ?? '')) ?></td>
                  </tr>

                  <tr>
                    <th>قیمت</th><td><?= htmlspecialchars((string)($file['price'] ?? '')) ?></td>
                    <th>سال ساخت</th><td><?= htmlspecialchars((string)($file['year_built'] ?? '')) ?></td>
                    <th>واحد در طبقه</th><td><?= htmlspecialchars((string)($file['unit_in_floor'] ?? '')) ?></td>
                  </tr>

                  <tr>
                    <th>خواب</th><td><?= htmlspecialchars((string)($file['rooms'] ?? '')) ?></td>
                    <th>انباری</th><td><?= htmlspecialchars((string)($file['storage_area'] ?? '')) ?></td>
                    <th>پارکینگ</th><td><?= htmlspecialchars((string)($file['parking_area'] ?? '')) ?></td>
                  </tr>

                  <tr>
                    <th>حیاط</th><td><?= htmlspecialchars((string)($file['yard_area'] ?? '')) ?></td>
                    <th>نوع فایل</th><td><?= htmlspecialchars((string)($file['file_type'] ?? '')) ?></td>
                    <th>تأییدیه ثبت</th><td><?= htmlspecialchars((string)($file['register_status'] ?? '')) ?></td>
                  </tr>

                  <tr>
                    <th>آدرس دقیق (لوکیشن۲)</th>
                    <td colspan="5">
                      <?php if ($canSeePrivateForFile): ?>
                        <?= nl2br(htmlspecialchars((string)($file['location2'] ?? '—'))) ?>
                      <?php else: ?>
                        <span class="muted">🔒 محرمانه</span>
                      <?php endif; ?>
                    </td>
                  </tr>

                  <tr>
                    <th>مشخصات مالک</th>
                    <td colspan="5">
                      <?php if ($canSeePrivateForFile): ?>
                        <?= nl2br(htmlspecialchars((string)($file['owner_info'] ?? '—'))) ?>
                      <?php else: ?>
                        <span class="muted">🔒 محرمانه</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="alert alert-light border mb-3">هنوز مشخصات این فایل تکمیل نشده است.</div>
          <?php endif; ?>

          <?php if ($isAdmin || $isSupervisor || ($isSales && $ownsFile)): ?>
            <button class="btn btn-outline-secondary mb-4" data-bs-toggle="modal" data-bs-target="#editDetails<?= $fid ?>">
              🛠️ تکمیل / ویرایش مشخصات
            </button>
          <?php endif; ?>

          <?php if ($isAdmin || $isSupervisor || $isSales || $isInstagram): ?>
            <div class="section-title">➕ افزودن مشتری</div>
            <form method="POST" action="add_customer.php" class="row g-2 mb-3">
              <input type="hidden" name="csrf" value="<?= $CSRF ?>">
              <input type="hidden" name="file_id" value="<?= $fid ?>">
              <div class="col-12 col-md-4">
                <input type="text" name="name" class="form-control" placeholder="نام و نام‌خانوادگی" required>
              </div>
              <div class="col-12 col-md-4">
                <input type="text" name="phone" class="form-control" placeholder="شماره تماس" required>
              </div>
              <div class="col-12 col-md-4">
                <button type="submit" class="btn btn-primary w-100">افزودن</button>
              </div>
            </form>
          <?php endif; ?>

          <input class="form-control mb-2" type="text"
                 onkeyup="filterTable(this, 'table<?= $fid ?>')"
                 placeholder="🔍 جستجوی مشتری">

          <div class="table-responsive">
            <table class="table table-bordered table-hover" id="table<?= $fid ?>">
              <thead>
                <tr>
                  <th>نام</th>
                  <th>شماره</th>
                  <th>گزارش تماس</th>
                  <th>وضعیت</th>
                  <th style="width:260px">عملیات</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($customers as $c): ?>
                <tr>
                  <td><?= htmlspecialchars((string)$c['name']) ?></td>
                  <td>
                    <?php if ($canSeePhonesForFile): ?>
                      <?= htmlspecialchars((string)$c['phone']) ?>
                    <?php else: ?>
                      <span class="muted">🔒 محرمانه</span>
                    <?php endif; ?>
                  </td>
                  <td><?= nl2br(htmlspecialchars((string)($c['report'] ?? ''))) ?></td>
                  <td>
                    <?= $c['contacted']
                        ? '<span class="text-success">✅ گرفته شد</span>'
                        : '<span class="text-warning">⏳ منتظر تماس</span>'; ?>
                  </td>
                  <td class="d-flex flex-wrap gap-1">
                    <?php if ($isAdmin || $isSupervisor || $isSales): ?>
                      <button class="btn btn-sm btn-success"
                              data-bs-toggle="modal"
                              data-bs-target="#reportModal<?= (int)$c['id'] ?>">✏️ ثبت گزارش</button>
                    <?php endif; ?>

                    <?php if ($isAdmin): ?>
                      <form method="POST" action="delete_customer.php" class="d-inline"
                            onsubmit="return confirm('حذف مشتری؟');">
                        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                        <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                        <button class="btn btn-sm btn-danger" type="submit">🗑 حذف</button>
                      </form>
                    <?php endif; ?>

                    <?php if ($isAdmin || $isSupervisor): ?>
                      <button class="btn btn-sm btn-warning"
                              data-bs-toggle="modal"
                              data-bs-target="#editCustomer<?= (int)$c['id'] ?>">✏️ ویرایش</button>
                    <?php endif; ?>
                  </td>
                </tr>

                <?php
                ob_start(); ?>
                <!-- Modal ثبت گزارش -->
                <div class="modal fade" id="reportModal<?= (int)$c['id'] ?>" tabindex="-1" aria-hidden="true">
                  <div class="modal-dialog">
                    <div class="modal-content">
                      <form method="POST" action="update_status.php">
                        <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                        <div class="modal-header">
                          <h5 class="modal-title">ثبت گزارش تماس</h5>
                          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="بستن"></button>
                        </div>
                        <div class="modal-body">
                          <input type="hidden" name="customer_id" value="<?= (int)$c['id'] ?>">
                          <div class="mb-2">
                            <strong>نام:</strong> <?= htmlspecialchars((string)$c['name']) ?>
                            <?php if ($canSeePhonesForFile): ?>
                              <span class="ms-3"><strong>شماره:</strong> <?= htmlspecialchars((string)$c['phone']) ?></span>
                            <?php endif; ?>
                          </div>
                          <div class="mb-3">
                            <label class="form-label">گزارش تماس</label>
                            <textarea name="report" class="form-control" rows="4" placeholder="گزارش تماس..." required></textarea>
                          </div>
                          <div class="mb-3">
                            <label class="form-label">وضعیت تماس</label>
                            <select name="status" class="form-select" required>
                              <option value="منتظر تماس بعدی">منتظر تماس بعدی</option>
                              <option value="موفق">موفق</option>
                              <option value="ناموفق">ناموفق</option>
                            </select>
                          </div>
                        </div>
                        <div class="modal-footer">
                          <button name="contacted" value="1" class="btn btn-primary">ثبت</button>
                          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">بستن</button>
                        </div>
                      </form>
                    </div>
                  </div>
                </div>

                <!-- Modal ویرایش مشتری -->
                <div class="modal fade" id="editCustomer<?= (int)$c['id'] ?>" tabindex="-1" aria-hidden="true">
                  <div class="modal-dialog">
                    <div class="modal-content">
                      <form method="POST" action="edit_customer.php">
                        <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                        <div class="modal-header">
                          <h5 class="modal-title">ویرایش مشتری</h5>
                          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="بستن"></button>
                        </div>
                        <div class="modal-body">
                          <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                          <div class="mb-3">
                            <label class="form-label">نام</label>
                            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars((string)$c['name']) ?>" required>
                          </div>
                          <div class="mb-3">
                            <label class="form-label">شماره تماس</label>
                            <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars((string)$c['phone']) ?>" required>
                          </div>
                        </div>
                        <div class="modal-footer">
                          <button type="submit" class="btn btn-primary">ذخیره</button>
                          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">بستن</button>
                        </div>
                      </form>
                    </div>
                  </div>
                </div>
                <?php
                $modalBucket[] = ob_get_clean();
                ?>
                <?php endforeach; ?>

                <?php if (!$customers): ?>
                  <tr><td colspan="5" class="text-center text-muted">مشتری ثبت نشده است.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

        </div>
      </div>

      <?php
      // مودال ویرایش فایل (ادمین)
      if ($isAdmin) {
        ob_start(); ?>
        <div class="modal fade" id="editFile<?= $fid ?>" tabindex="-1" aria-hidden="true">
          <div class="modal-dialog">
            <div class="modal-content">
              <form method="POST" action="edit_file.php">
                <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                <div class="modal-header">
                  <h5 class="modal-title">ویرایش فایل ملکی</h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="بستن"></button>
                </div>
                <div class="modal-body">
                  <input type="hidden" name="id" value="<?= $fid ?>">
                  <div class="mb-3">
                    <label class="form-label">کد فایل</label>
                    <input type="text" name="code" class="form-control" value="<?= htmlspecialchars((string)$file['code']) ?>" required>
                  </div>
                  <div class="mb-3">
                    <label class="form-label">کارشناس فروش</label>
                    <input type="text" name="salesperson" class="form-control" value="<?= htmlspecialchars((string)$file['salesperson']) ?>" required>
                  </div>
                </div>
                <div class="modal-footer">
                  <button type="submit" class="btn btn-primary">ذخیره</button>
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">بستن</button>
                </div>
              </form>
            </div>
          </div>
        </div>
        <?php
        $modalBucket[] = ob_get_clean();
      }

      // مودال تکمیل مشخصات (ادمین/سوپروایزر)
      if ($isAdmin || $isSupervisor) {
        ob_start(); ?>
        <div class="modal fade" id="editDetails<?= $fid ?>" tabindex="-1" aria-hidden="true">
          <div class="modal-dialog modal-lg">
            <div class="modal-content">
              <form method="POST" action="save_property_details.php">
                <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                <input type="hidden" name="property_file_id" value="<?= $fid ?>">
                <div class="modal-header">
                  <h5 class="modal-title">تکمیل / ویرایش مشخصات فایل</h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="بستن"></button>
                </div>
                <div class="modal-body">
                  <div class="row g-3">
                    <div class="col-md-4">
                      <label class="form-label">وضعیت سند</label>
                      <input name="document_status" class="form-control" value="<?= htmlspecialchars((string)($file['document_status'] ?? '')) ?>">
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">متراژ</label>
                      <input name="area" type="number" class="form-control" value="<?= htmlspecialchars((string)($file['area'] ?? '')) ?>">
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">طبقه</label>
                      <input name="floor" class="form-control" value="<?= htmlspecialchars((string)($file['floor'] ?? '')) ?>">
                    </div>

                    <div class="col-md-4">
                      <label class="form-label">قیمت</label>
                      <input name="price" type="number" class="form-control" value="<?= htmlspecialchars((string)($file['price'] ?? '')) ?>">
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">سال ساخت</label>
                      <input name="year_built" class="form-control" value="<?= htmlspecialchars((string)($file['year_built'] ?? '')) ?>">
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">واحد در طبقه</label>
                      <input name="unit_in_floor" class="form-control" value="<?= htmlspecialchars((string)($file['unit_in_floor'] ?? '')) ?>">
                    </div>

                    <div class="col-md-4">
                      <label class="form-label">تعداد خواب</label>
                      <input name="rooms" type="number" class="form-control" value="<?= htmlspecialchars((string)($file['rooms'] ?? '')) ?>">
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">متراژ انباری</label>
                      <input name="storage_area" type="number" class="form-control" value="<?= htmlspecialchars((string)($file['storage_area'] ?? '')) ?>">
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">متراژ پارکینگ</label>
                      <input name="parking_area" type="number" class="form-control" value="<?= htmlspecialchars((string)($file['parking_area'] ?? '')) ?>">
                    </div>

                    <div class="col-md-4">
                      <label class="form-label">متراژ حیاط</label>
                      <input name="yard_area" type="number" class="form-control" value="<?= htmlspecialchars((string)($file['yard_area'] ?? '')) ?>">
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">نوع فایل</label>
                      <input name="file_type" class="form-control" value="<?= htmlspecialchars((string)($file['file_type'] ?? '')) ?>">
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">کد (اختیاری)</label>
                      <input name="code" class="form-control" value="<?= htmlspecialchars((string)($file['code'] ?? '')) ?>">
                    </div>

                    <div class="col-md-8">
                      <label class="form-label">مشخصات مالک</label>
                      <textarea name="owner_info" rows="3" class="form-control"><?= htmlspecialchars((string)($file['owner_info'] ?? '')) ?></textarea>
                      <div class="form-text muted">محرمانه (فقط ادمین/مالک فایل می‌بیند).</div>
                    </div>

                    <div class="col-md-4">
                      <label class="form-label">تأییدیه ثبت</label>
                      <input name="register_status" class="form-control" value="<?= htmlspecialchars((string)($file['register_status'] ?? '')) ?>">
                    </div>

                    <div class="col-md-12">
                      <label class="form-label">آدرس دقیق (لوکیشن۲)</label>
                      <textarea name="location2" rows="2" class="form-control"><?= htmlspecialchars((string)($file['location2'] ?? '')) ?></textarea>
                      <div class="form-text muted">محرمانه (فقط ادمین/مالک فایل می‌بیند).</div>
                    </div>
                  </div>
                </div>
                <div class="modal-footer">
                  <button type="submit" class="btn btn-primary">ذخیره</button>
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">بستن</button>
                </div>
              </form>
            </div>
          </div>
        </div>
        <?php
        $modalBucket[] = ob_get_clean();
      }
      ?>
    </div>
  <?php endforeach; ?>

  <!-- صفحه‌بندی -->
  <?php if ($totalPages > 1): ?>
    <nav class="mt-4">
      <ul class="pagination justify-content-center flex-wrap gap-1">
        <?php
          $buildLink = function(int $p) use ($search) {
              $q = ['page' => $p];
              if ($search !== '') $q['search'] = $search;
              return 'index.php?' . http_build_query($q);
          };
        ?>
        <li class="page-item <?= $page<=1?'disabled':'' ?>">
          <a class="page-link" href="<?= $buildLink(max(1,$page-1)) ?>">قبلی</a>
        </li>
        <?php
          $start = max(1, $page-2);
          $end   = min($totalPages, $page+2);
          for ($i=$start; $i<=$end; $i++):
        ?>
          <li class="page-item <?= $i===$page?'active':'' ?>">
            <a class="page-link" href="<?= $buildLink($i) ?>"><?= $i ?></a>
          </li>
        <?php endfor; ?>
        <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>">
          <a class="page-link" href="<?= $buildLink(min($totalPages,$page+1)) ?>">بعدی</a>
        </li>
      </ul>
    </nav>
  <?php endif; ?>

</div>

<!-- رندر مودال‌ها در انتهای بدنه -->
<?php if (!empty($modalBucket)) echo implode("\n", $modalBucket); ?>

<script>
// کلیک روی هدر کارت = باز/بستن (به غیر از دکمه‌ها/لینک‌ها/فرم‌ها)
document.querySelectorAll('.card-header').forEach(header => {
  header.addEventListener('click', (e) => {
    const target = e.target;
    if (target.closest('button, a, form, input, textarea, select')) return;
    const collapseBtn = header.querySelector('[data-bs-target^="#collapseFile"]');
    if (collapseBtn) collapseBtn.click();
  });
});

// چرخش آیکون Collapse
document.querySelectorAll('[id^="collapseFile"]').forEach(el => {
  el.addEventListener('show.bs.collapse', () => {
    const icon = el.parentElement.querySelector('.rotate-icon');
    if (icon) icon.classList.add('open');
  });
  el.addEventListener('hide.bs.collapse', () => {
    const icon = el.parentElement.querySelector('.rotate-icon');
    if (icon) icon.classList.remove('open');
  });
});

// فیلتر جدول مشتری‌ها
function filterTable(input, tableId) {
  const filter = input.value.toUpperCase();
  const table = document.getElementById(tableId);
  if (!table) return;
  const rows = table.getElementsByTagName("tr");
  for (let i = 1; i < rows.length; i++) {
    const tds = rows[i].getElementsByTagName("td");
    if (!tds || tds.length < 2) continue;
    const txtName  = (tds[0].textContent || tds[0].innerText).toUpperCase();
    const txtPhone = (tds[1].textContent || tds[1].innerText).toUpperCase();
    rows[i].style.display = (txtName.indexOf(filter) > -1 || txtPhone.indexOf(filter) > -1) ? "" : "none";
  }
}
</script>
</body>
</html>
