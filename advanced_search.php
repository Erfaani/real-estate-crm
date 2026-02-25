<?php
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

if (empty($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

$role        = (string)($_SESSION['role'] ?? '');
$username    = trim((string)($_SESSION['user'] ?? ''));
$fullname    = trim((string)($_SESSION['fullname'] ?? ''));
$currentName = ($fullname !== '') ? $fullname : $username;

$isAdmin    = ($role === 'admin');
$isSales    = ($role === 'sales');
$isSupervisor = ($role === 'supervisor');

// -------- تشخیص وجود جدول و ستون‌ها --------
$hasDetails = false;
$cols = [];

try {
    $pdo->query("SELECT 1 FROM property_details LIMIT 1");
    $hasDetails = true;

    $q = $pdo->prepare("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'property_details'
    ");
    $q->execute();
    $cols = array_map(fn($r) => $r['COLUMN_NAME'], $q->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
    $hasDetails = false;
    $cols = [];
}

$has = fn(string $c) => in_array($c, $cols, true);

// -------- ورودی‌ها --------
$g = fn(string $k) => trim((string)($_GET[$k] ?? ''));

$q_code        = $g('code');
$q_sales       = $g('salesperson');
$q_location    = $g('location');          // pf_city/pf_area
$q_rooms       = $g('rooms');
$q_area_min    = $g('area_min');
$q_area_max    = $g('area_max');
$q_price_min   = $g('price_min');
$q_price_max   = $g('price_max');
$q_year_min    = $g('year_min');
$q_year_max    = $g('year_max');
$q_doc_status  = $g('document_status');
$q_file_type   = $g('file_type');

// صفحه‌بندی
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

// -------- ساخت WHERE و پارامترها --------
$where  = ["1=1"];
$params = [];

if ($q_code !== '')  { $where[] = "pf.code LIKE :code";         $params['code']  = "%{$q_code}%"; }
if ($q_sales !== '') { $where[] = "pf.salesperson LIKE :sales"; $params['sales'] = "%{$q_sales}%"; }

if ($q_location !== '') {
    $where[] = "(pf.pf_city LIKE :loc OR pf.pf_area LIKE :loc)";
    $params['loc'] = "%{$q_location}%";
}

if ($hasDetails) {
    if ($q_rooms !== '' && $has('rooms')) {
        $where[] = "pd.rooms = :rooms";
        $params['rooms'] = (int)$q_rooms;
    }

    if ($q_area_min !== '' && $has('area')) {
        $where[] = "CAST(pd.area AS UNSIGNED) >= :amin";
        $params['amin'] = (int)$q_area_min;
    }
    if ($q_area_max !== '' && $has('area')) {
        $where[] = "CAST(pd.area AS UNSIGNED) <= :amax";
        $params['amax'] = (int)$q_area_max;
    }

    if ($q_price_min !== '' && $has('price')) {
        $where[] = "CAST(pd.price AS UNSIGNED) >= :pmin";
        $params['pmin'] = (int)$q_price_min;
    }
    if ($q_price_max !== '' && $has('price')) {
        $where[] = "CAST(pd.price AS UNSIGNED) <= :pmax";
        $params['pmax'] = (int)$q_price_max;
    }

    if ($q_year_min !== '' && $has('year_built')) {
        $where[] = "CAST(pd.year_built AS UNSIGNED) >= :ymin";
        $params['ymin'] = (int)$q_year_min;
    }
    if ($q_year_max !== '' && $has('year_built')) {
        $where[] = "CAST(pd.year_built AS UNSIGNED) <= :ymax";
        $params['ymax'] = (int)$q_year_max;
    }

    if ($q_doc_status !== '' && $has('document_status')) {
        $where[] = "pd.document_status LIKE :ds";
        $params['ds'] = "%{$q_doc_status}%";
    }

    if ($q_file_type !== '' && $has('file_type')) {
        $where[] = "pd.file_type LIKE :ft";
        $params['ft'] = "%{$q_file_type}%";
    }
}

// -------- ساخت SELECT --------
$selectParts = [
    "pf.id",
    "pf.code",
    "pf.salesperson",
    "pf.created_at",
    "CONCAT_WS(' - ', pf.pf_city, pf.pf_area) AS location",
];

if ($hasDetails) {
    $selectParts[] = $has('rooms') ? "pd.rooms" : "NULL AS rooms";
    $selectParts[] = $has('area') ? "pd.area" : "NULL AS area";
    $selectParts[] = $has('price') ? "pd.price" : "NULL AS price";
    $selectParts[] = $has('year_built') ? "pd.year_built" : "NULL AS year_built";
    $selectParts[] = $has('document_status') ? "pd.document_status" : "NULL AS document_status";
    $selectParts[] = $has('file_type') ? "pd.file_type" : "NULL AS file_type";

    // ✅ HY093 FIX: placeholders باید یونیک باشند
    $canPrivateSqlLoc = $isAdmin ? "1=1" : "(pf.salesperson = :cn_loc)";
    $canPrivateSqlOwn = $isAdmin ? "1=1" : "(pf.salesperson = :cn_own)";

    if (!$isAdmin) {
        $params['cn_loc'] = $currentName;
        $params['cn_own'] = $currentName;
    }

    $selectParts[] = $has('location2')
        ? "CASE WHEN $canPrivateSqlLoc THEN pd.location2 ELSE NULL END AS location2"
        : "NULL AS location2";

    $selectParts[] = $has('owner_info')
        ? "CASE WHEN $canPrivateSqlOwn THEN pd.owner_info ELSE NULL END AS owner_info"
        : "NULL AS owner_info";
} else {
    $selectParts[] = "NULL AS rooms";
    $selectParts[] = "NULL AS area";
    $selectParts[] = "NULL AS price";
    $selectParts[] = "NULL AS year_built";
    $selectParts[] = "NULL AS document_status";
    $selectParts[] = "NULL AS file_type";
    $selectParts[] = "NULL AS location2";
    $selectParts[] = "NULL AS owner_info";
}

$selectSql = implode(",\n            ", $selectParts);

// -------- شمارش و واکشی --------
$rows = [];
$totalRows = 0;
$totalPages = 1;
$fatalError = '';

try {
    $countSql = "
        SELECT COUNT(*)
        FROM property_files pf
        ".($hasDetails ? "LEFT JOIN property_details pd ON pd.property_file_id = pf.id" : "")."
        WHERE ".implode(' AND ', $where);

    $stmCount = $pdo->prepare($countSql);
    foreach ($params as $k => $v) {
        $stmCount->bindValue(":$k", $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmCount->execute();
    $totalRows  = (int)$stmCount->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));

    // ✅ LIMIT/OFFSET را عددی در SQL می‌گذاریم تا دردسر bind ندهد
    $limit = (int)$perPage;
    $off   = (int)$offset;

    $listSql = "
        SELECT
            $selectSql
        FROM property_files pf
        ".($hasDetails ? "LEFT JOIN property_details pd ON pd.property_file_id = pf.id" : "")."
        WHERE ".implode(' AND ', $where)."
        ORDER BY pf.created_at DESC
        LIMIT {$limit} OFFSET {$off}
    ";

    $stm = $pdo->prepare($listSql);
    foreach ($params as $k => $v) {
        $stm->bindValue(":$k", $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stm->execute();
    $rows = $stm->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    $rows = [];
    $totalRows = 0;
    $totalPages = 1;
    $fatalError = $e->getMessage();
    error_log("advanced_search error: ".$fatalError);
}

// ساخت لینک صفحه‌بندی با حفظ فیلترها
function buildQuery(array $extra = []): string {
    $q = $_GET;
    foreach ($extra as $k => $v) $q[$k] = $v;
    return 'advanced_search.php?' . http_build_query($q);
}

// دسترسی به اطلاعات حساس (برای نمایش محرمانه)
function canSeePrivate(array $row, string $role, string $currentName): bool {
    if ($role === 'admin') return true;
    // اینجا همسو با index: مالک فایل
    if (trim((string)$row['salesperson']) === $currentName) return true;
    return false;
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<title>جست‌وجوی پیشرفته | فایل‌های ملکی</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<style>
  :root {
    --main-orange: #db6534;
    --main-gray: #f5f5f5;
    --hover-orange: #e77b4b;
    --card-bg: #fff;
    --border-radius: 12px;
  }
  body { background-color: #fafafa; font-family: 'Vazir', sans-serif; }
  .card { border-radius: var(--border-radius); box-shadow: 0 2px 10px rgba(0,0,0,.1); background-color: var(--card-bg); }
  .card-header { background: #fff; border-bottom: 2px solid var(--main-orange); font-weight: 600; }
  .table th { background: #fafafa; }
  .btn-primary { background-color: var(--main-orange); border-color: var(--main-orange); }
  .btn-outline-primary:hover { background-color: var(--main-orange); border-color: var(--main-orange); color: #fff; }
  .badge-muted { background-color: #eee; color: #555; }
  .pagination .page-item.active .page-link { background-color: var(--main-orange); border-color: var(--main-orange); }
  .pagination .page-link { border-radius: 50%; }
  .alert { border-radius: var(--border-radius); }
  .card-body input, .form-control { border-radius: var(--border-radius); }
  .d-flex .btn { border-radius: var(--border-radius); }
  .table th, .table td { text-align: center; vertical-align: middle; }
  .table .btn-outline-primary { border-radius: var(--border-radius); }
  .form-control:focus { border-color: var(--main-orange); box-shadow: 0 0 0 0.25rem rgba(219,101,52,0.25); }
  .card-body { padding: 1.5rem; }
</style>
</head>
<body>
<div class="container my-4">

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">🔎 جست‌وجوی پیشرفته فایل‌های ملکی</h5>
    <div class="d-flex gap-2">
      <a href="index.php" class="btn btn-outline-secondary">بازگشت</a>
      <a href="advanced_search.php" class="btn btn-outline-dark">پاک‌سازی فیلتر</a>
    </div>
  </div>

  <?php if (!$hasDetails): ?>
    <div class="alert alert-warning">
      جدول <code>property_details</code> در دیتابیس پیدا نشد. فعلاً جست‌وجو فقط بر اساس <b>کد فایل</b> و <b>کارشناس</b>
      انجام می‌شود.
    </div>
  <?php endif; ?>

  <?php if ($fatalError !== ''): ?>
    <div class="alert alert-danger">خطا در اجرای جست‌وجو: <?= htmlspecialchars($fatalError) ?></div>
  <?php endif; ?>

  <!-- فیلترها -->
  <form method="GET" class="card mb-3">
    <div class="card-header">فیلترها</div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-12 col-md-3">
          <input type="text" name="code" value="<?= htmlspecialchars($q_code) ?>" class="form-control" placeholder="کد فایل">
        </div>
        <div class="col-12 col-md-3">
          <input type="text" name="salesperson" value="<?= htmlspecialchars($q_sales) ?>" class="form-control" placeholder="کارشناس">
        </div>

        <div class="col-12 col-md-3">
          <input type="text" name="location" value="<?= htmlspecialchars($q_location) ?>" class="form-control" placeholder="موقعیت (شهر/منطقه)">
        </div>

        <?php if ($hasDetails): ?>
          <div class="col-6 col-md-3">
            <input type="number" name="rooms" value="<?= htmlspecialchars($q_rooms) ?>" class="form-control" placeholder="تعداد خواب">
          </div>
          <div class="col-6 col-md-3">
            <input type="number" name="area_min" value="<?= htmlspecialchars($q_area_min) ?>" class="form-control" placeholder="متراژ از">
          </div>
          <div class="col-6 col-md-3">
            <input type="number" name="area_max" value="<?= htmlspecialchars($q_area_max) ?>" class="form-control" placeholder="متراژ تا">
          </div>
          <div class="col-6 col-md-3">
            <input type="number" name="price_min" value="<?= htmlspecialchars($q_price_min) ?>" class="form-control" placeholder="قیمت از">
          </div>
          <div class="col-6 col-md-3">
            <input type="number" name="price_max" value="<?= htmlspecialchars($q_price_max) ?>" class="form-control" placeholder="قیمت تا">
          </div>
          <div class="col-6 col-md-3">
            <input type="number" name="year_min" value="<?= htmlspecialchars($q_year_min) ?>" class="form-control" placeholder="سال ساخت از">
          </div>
          <div class="col-6 col-md-3">
            <input type="number" name="year_max" value="<?= htmlspecialchars($q_year_max) ?>" class="form-control" placeholder="سال ساخت تا">
          </div>
          <div class="col-12 col-md-3">
            <input type="text" name="document_status" value="<?= htmlspecialchars($q_doc_status) ?>" class="form-control" placeholder="وضعیت سند">
          </div>
          <div class="col-12 col-md-3">
            <input type="text" name="file_type" value="<?= htmlspecialchars($q_file_type) ?>" class="form-control" placeholder="نوع فایل">
          </div>
        <?php endif; ?>

        <div class="col-12 col-md-3 d-grid">
          <button class="btn btn-primary">جست‌وجو</button>
        </div>
      </div>
    </div>
  </form>

  <!-- نتایج -->
  <div class="card">
    <div class="card-header d-flex justify-content-between">
      <span>نتایج (<?= number_format($totalRows) ?>)</span>
      <span class="small text-muted">لوکیشن۲ و مشخصات مالک فقط برای مدیر یا کارشناسِ صاحب فایل نمایش داده می‌شود.</span>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-bordered table-striped align-middle">
          <thead>
            <tr>
              <th>کد فایل</th>
              <th>کارشناس</th>
              <th>موقعیت</th>
              <th>خواب</th>
              <th>متراژ</th>
              <th>قیمت</th>
              <th>سال</th>
              <th>سند</th>
              <th>نوع</th>
              <th>لوکیشن۲</th>
              <th>مشخصات مالک</th>
              <th>عملیات</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($rows): ?>
              <?php foreach ($rows as $r):
                    $canPrivate = canSeePrivate($r, $role, $currentName);
              ?>
              <tr>
                <td><?= htmlspecialchars((string)$r['code']) ?></td>
                <td><?= htmlspecialchars((string)$r['salesperson']) ?></td>
                <td><?= htmlspecialchars((string)($r['location'] ?? '—')) ?></td>
                <td><?= htmlspecialchars((string)($r['rooms'] ?? '—')) ?></td>
                <td><?= htmlspecialchars((string)($r['area'] ?? '—')) ?></td>
                <td><?= htmlspecialchars((string)($r['price'] ?? '—')) ?></td>
                <td><?= htmlspecialchars((string)($r['year_built'] ?? '—')) ?></td>
                <td><?= htmlspecialchars((string)($r['document_status'] ?? '—')) ?></td>
                <td><?= htmlspecialchars((string)($r['file_type'] ?? '—')) ?></td>

                <td>
                  <?php if ($canPrivate && !empty($r['location2'])): ?>
                    <?= htmlspecialchars((string)$r['location2']) ?>
                  <?php elseif ($canPrivate): ?>
                    —
                  <?php else: ?>
                    <span class="badge badge-muted">محرمانه</span>
                  <?php endif; ?>
                </td>

                <td class="text-start">
                  <?php if ($canPrivate && !empty($r['owner_info'])): ?>
                    <?= nl2br(htmlspecialchars((string)$r['owner_info'])) ?>
                  <?php elseif ($canPrivate): ?>
                    —
                  <?php else: ?>
                    <span class="badge badge-muted">محرمانه</span>
                  <?php endif; ?>
                </td>

                <td>
                  <a class="btn btn-sm btn-outline-primary" href="index.php">مشاهده در لیست</a>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="12" class="text-center text-muted">نتیجه‌ای یافت نشد.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <?php if ($totalPages > 1): ?>
        <nav class="mt-3">
          <ul class="pagination justify-content-center flex-wrap gap-1">
            <li class="page-item <?= $page<=1?'disabled':'' ?>">
              <a class="page-link" href="<?= buildQuery(['page'=>max(1,$page-1)]) ?>">قبلی</a>
            </li>
            <?php
              $start = max(1, $page-2);
              $end   = min($totalPages, $page+2);
              for ($i=$start; $i<=$end; $i++):
            ?>
              <li class="page-item <?= $i===$page?'active':'' ?>">
                <a class="page-link" href="<?= buildQuery(['page'=>$i]) ?>"><?= $i ?></a>
              </li>
            <?php endfor; ?>
            <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>">
              <a class="page-link" href="<?= buildQuery(['page'=>min($totalPages,$page+1)]) ?>">بعدی</a>
            </li>
          </ul>
        </nav>
      <?php endif; ?>

    </div>
  </div>

</div>
</body>
</html>
