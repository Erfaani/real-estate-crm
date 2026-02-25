<?php
/*******************************************************
 * report.php — داشبورد مدیریتی + گزارش‌ها + مدیریت کاربران
 * + Leaderboard کارشناسان
 *******************************************************/
declare(strict_types=1);

session_start();
require __DIR__ . '/db.php';

if (empty($_SESSION['user']) || (string)($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: index.php");
    exit;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$CSRF = (string)$_SESSION['csrf'];

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// ---------- ورودی‌ها ----------
$start_date   = (string)($_GET['start_date'] ?? date('Y-m-01'));
$end_date     = (string)($_GET['end_date']   ?? date('Y-m-d'));
$sales_filter = trim((string)($_GET['salesperson'] ?? ''));
$file_code    = trim((string)($_GET['file_code'] ?? ''));
$call_status  = (string)($_GET['call_status'] ?? 'all');
$tab          = (string)($_GET['tab'] ?? '');

// ---------- POST: مدیریت کاربران ----------
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf']) || (string)$_POST['csrf'] !== $CSRF) {
        $msg = 'CSRF نامعتبر';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'create_user') {
                $username = trim((string)($_POST['username'] ?? ''));
                $password = (string)($_POST['password'] ?? '');
                $newRole  = trim((string)($_POST['role'] ?? ''));

                if ($username === '' || $password === '' || mb_strlen($password) < 6 ||
                    !in_array($newRole, ['admin','supervisor','sales','instagram_admin'], true)
                ) {
                    $msg = 'ورودی نامعتبر برای ایجاد کاربر.';
                } else {
                    $chk = $pdo->prepare("SELECT id FROM users WHERE username = :u LIMIT 1");
                    $chk->execute(['u' => $username]);
                    if ($chk->fetchColumn()) {
                        $msg = 'این نام کاربری قبلاً ثبت شده است.';
                    } else {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $stm = $pdo->prepare("
                            INSERT INTO users (username, password, role, status, created_at, updated_at)
                            VALUES (:u, :p, :r, 'active', NOW(), NOW())
                        ");
                        $stm->execute(['u'=>$username,'p'=>$hash,'r'=>$newRole]);
                        $msg = 'کاربر با موفقیت افزوده شد.';
                        $tab = 'users';
                    }
                }

            } elseif ($action === 'update_role') {
                $uid  = (int)($_POST['id'] ?? 0);
                $newRole = trim((string)($_POST['role'] ?? ''));

                if ($uid <= 0 || !in_array($newRole, ['admin','supervisor','sales','instagram_admin'], true)) {
                    $msg = 'ورودی نامعتبر برای تغییر نقش.';
                } else {
                    $stm = $pdo->prepare("UPDATE users SET role=:r, updated_at=NOW() WHERE id=:id");
                    $stm->execute(['r'=>$newRole, 'id'=>$uid]);
                    $msg = 'نقش کاربر به‌روزرسانی شد.';
                    $tab = 'users';
                }

            } elseif ($action === 'reset_password') {
                $uid  = (int)($_POST['id'] ?? 0);
                $pass = (string)($_POST['password'] ?? '');

                if ($uid <= 0 || $pass === '' || mb_strlen($pass) < 6) {
                    $msg = 'رمز جدید نامعتبر است (حداقل ۶ کاراکتر).';
                } else {
                    $hash = password_hash($pass, PASSWORD_DEFAULT);
                    $stm = $pdo->prepare("UPDATE users SET password=:p, updated_at=NOW() WHERE id=:id");
                    $stm->execute(['p'=>$hash, 'id'=>$uid]);
                    $msg = 'رمز کاربر تغییر کرد.';
                    $tab = 'users';
                }

            } elseif ($action === 'delete_user') {
                $uid = (int)($_POST['id'] ?? 0);
                $currentUserId = (int)($_SESSION['user_id'] ?? 0);

                if ($uid <= 0) {
                    $msg = 'شناسه کاربر نامعتبر است.';
                } elseif ($uid === $currentUserId) {
                    $msg = 'امکان حذف کاربر جاری وجود ندارد.';
                } else {
                    $stm = $pdo->prepare("DELETE FROM users WHERE id=:id");
                    $stm->execute(['id'=>$uid]);
                    $msg = 'کاربر حذف شد.';
                    $tab = 'users';
                }
            }
        } catch (Throwable $e) {
            error_log("report.php POST ERROR: " . $e->getMessage());
            $msg = 'خطا در عملیات. (جزئیات در لاگ سرور)';
        }
    }
}

// ---------- KPI ----------
try {
    $total_files     = (int)$pdo->query("SELECT COUNT(*) FROM property_files")->fetchColumn();
    $total_customers = (int)$pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
    $total_calls     = (int)$pdo->query("SELECT COUNT(*) FROM contact_reports")->fetchColumn();

    $stmToday = $pdo->prepare("SELECT COUNT(*) FROM contact_reports WHERE DATE(created_at) = ?");
    $stmToday->execute([date('Y-m-d')]);
    $today_calls = (int)$stmToday->fetchColumn();
} catch (Throwable $e) {
    error_log("report.php KPI ERROR: " . $e->getMessage());
    $total_files = $total_customers = $total_calls = $today_calls = 0;
}

// ---------- بازه زمانی ----------
$sDT = $start_date . " 00:00:00";
$eDT = $end_date   . " 23:59:59";

// ---------- Contacts (history) ----------
$contacts = [];
try {
    $where = ["cr.created_at BETWEEN :s AND :e"];
    $params = ['s' => $sDT, 'e' => $eDT];

    if ($sales_filter !== '') {
        $where[] = "(cr.salesperson LIKE :sp OR pf.salesperson LIKE :sp)";
        $params['sp'] = "%{$sales_filter}%";
    }
    if ($file_code !== '') {
        $where[] = "pf.code LIKE :fc";
        $params['fc'] = "%{$file_code}%";
    }
    if ($call_status !== '' && $call_status !== 'all') {
        $allowed = ['موفق','ناموفق','منتظر تماس بعدی'];
        if (in_array($call_status, $allowed, true)) {
            $where[] = "cr.status = :st";
            $params['st'] = $call_status;
        }
    }

    $sqlContacts = "
        SELECT
          cr.id AS report_id,
          cr.customer_id,
          cr.report,
          cr.status,
          cr.created_at,
          c.name AS customer_name,
          c.phone AS customer_phone,
          pf.code AS file_code,
          pf.salesperson AS file_salesperson
        FROM contact_reports cr
        JOIN customers c ON c.id = cr.customer_id
        JOIN property_files pf ON pf.id = c.property_file_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY cr.created_at DESC
        LIMIT 3000
    ";
    $stmContacts = $pdo->prepare($sqlContacts);
    $stmContacts->execute($params);
    $contacts = $stmContacts->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("report.php CONTACTS ERROR: " . $e->getMessage());
    $contacts = [];
}

// ---------- Leaderboard کارشناسان (در بازه انتخابی) ----------
$leaderboard = [];
try {
    $w = ["cr.created_at BETWEEN :s AND :e"];
    $p = ['s' => $sDT, 'e' => $eDT];

    if ($file_code !== '') {
        $w[] = "pf.code LIKE :fc";
        $p['fc'] = "%{$file_code}%";
    }
    if ($sales_filter !== '') {
        $w[] = "(cr.salesperson LIKE :sp OR pf.salesperson LIKE :sp)";
        $p['sp'] = "%{$sales_filter}%";
    }

    // نکته: salesperson را از cr.salesperson اگر پر بود می‌گیریم، وگرنه از pf.salesperson
    $sqlLB = "
      SELECT
        COALESCE(NULLIF(TRIM(cr.salesperson), ''), pf.salesperson) AS salesperson_name,
        COUNT(*) AS total_calls,
        SUM(cr.status='موفق') AS success_calls,
        SUM(cr.status='ناموفق') AS failed_calls,
        SUM(cr.status='منتظر تماس بعدی') AS pending_calls
      FROM contact_reports cr
      JOIN customers c ON c.id = cr.customer_id
      JOIN property_files pf ON pf.id = c.property_file_id
      WHERE " . implode(' AND ', $w) . "
      GROUP BY salesperson_name
      ORDER BY success_calls DESC, total_calls DESC
      LIMIT 50
    ";
    $stmLB = $pdo->prepare($sqlLB);
    $stmLB->execute($p);
    $leaderboard = $stmLB->fetchAll(PDO::FETCH_ASSOC);

    // نرخ موفقیت را در PHP محاسبه می‌کنیم
    foreach ($leaderboard as &$row) {
        $tot = (int)$row['total_calls'];
        $suc = (int)$row['success_calls'];
        $row['success_rate'] = $tot > 0 ? round(($suc / $tot) * 100, 1) : 0.0;
    }
    unset($row);

} catch (Throwable $e) {
    error_log("report.php LEADERBOARD ERROR: " . $e->getMessage());
    $leaderboard = [];
}

// ---------- Chart 7 days ----------
$chart_data = [];
try {
    $stmCountDay = $pdo->prepare("SELECT COUNT(*) FROM contact_reports WHERE DATE(created_at) = ?");
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $stmCountDay->execute([$date]);
        $chart_data[] = ['date' => $date, 'count' => (int)$stmCountDay->fetchColumn()];
    }
} catch (Throwable $e) {
    error_log("report.php CHART ERROR: " . $e->getMessage());
    $chart_data = [];
}

// ---------- Print ----------
if (isset($_GET['print']) && (string)$_GET['print'] === '1') {
    ?>
    <!DOCTYPE html>
    <html lang="fa" dir="rtl">
    <head>
      <meta charset="UTF-8">
      <title>چاپ گزارش تماس‌ها</title>
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <style>
        @page { size: A4; margin: 14mm; }
        body { font-family: tahoma, sans-serif; color:#222; }
        h2 { margin:0 0 10px 0; }
        .small { color:#666; font-size:12px; margin-bottom:10px; }
        table { width:100%; border-collapse: collapse; font-size:12px; }
        th, td { border:1px solid #999; padding:6px 8px; vertical-align: top; }
        th { background:#eee; text-align:right; }
      </style>
    </head>
    <body onload="window.print()">
      <h2>گزارش تماس‌ها (تاریخچه)</h2>
      <div class="small">
        بازه: <?= h($start_date) ?> تا <?= h($end_date) ?>
        <?php if ($sales_filter !== ''): ?> | کارشناس: <?= h($sales_filter) ?><?php endif; ?>
        <?php if ($file_code !== ''): ?> | کد فایل: <?= h($file_code) ?><?php endif; ?>
        <?php if ($call_status !== '' && $call_status !== 'all'): ?> | وضعیت: <?= h($call_status) ?><?php endif; ?>
      </div>

      <table>
        <thead>
          <tr>
            <th>نام مشتری</th>
            <th>شماره تماس</th>
            <th>وضعیت</th>
            <th>گزارش</th>
            <th>کد فایل</th>
            <th>کارشناس</th>
            <th>زمان تماس</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($contacts as $c): ?>
            <tr>
              <td><?= h((string)$c['customer_name']) ?></td>
              <td><?= h((string)$c['customer_phone']) ?></td>
              <td><?= h((string)$c['status']) ?></td>
              <td><?= nl2br(h((string)($c['report'] ?? ''))) ?></td>
              <td><?= h((string)$c['file_code']) ?></td>
              <td><?= h((string)$c['file_salesperson']) ?></td>
              <td><?= h((string)$c['created_at']) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$contacts): ?>
            <tr><td colspan="7" style="text-align:center;color:#777;">رکوردی برای چاپ نیست</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </body>
    </html>
    <?php
    exit;
}

// ---------- Users tab data ----------
$users = [];
try {
    $users = $pdo->query("SELECT id, username, role, status FROM users ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("report.php USERS ERROR: " . $e->getMessage());
    $users = [];
}

$roleLabels = [
    'admin' => 'مدیر',
    'supervisor' => 'سوپروایزر',
    'sales' => 'کارشناس فروش',
    'instagram_admin' => 'ادمین اینستاگرام'
];

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>گزارش‌گیری و مدیریت | CRM</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root { --main-orange: #db6534; --hover-orange:#b34f29; }
.card-header { background-color: var(--main-orange); color: #fff; font-weight: bold; }
.nav-pills .nav-link.active { background: var(--main-orange); }
.badge-soft { background:#fff; color:#333; border:1px solid #ddd; }
</style>
</head>
<body>
<div class="container my-4">

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4>📊 داشبورد مدیریتی CRM</h4>
    <div class="d-flex gap-2">
      <a href="index.php" class="btn btn-secondary">🏠 بازگشت</a>
    </div>
  </div>

  <?php if ($msg): ?>
    <div class="alert alert-info"><?= h($msg) ?></div>
  <?php endif; ?>

  <ul class="nav nav-pills mb-3" id="pills-tab" role="tablist">
    <li class="nav-item" role="presentation">
      <button class="nav-link active" id="pills-analytics-tab" data-bs-toggle="pill" data-bs-target="#pills-analytics" type="button" role="tab">📈 آمار و نمودار</button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="pills-contacts-tab" data-bs-toggle="pill" data-bs-target="#pills-contacts" type="button" role="tab">📞 گزارش تماس‌ها</button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="pills-users-tab" data-bs-toggle="pill" data-bs-target="#pills-users" type="button" role="tab">👥 مدیریت کاربران</button>
    </li>
  </ul>

  <div class="tab-content" id="pills-tabContent">

    <!-- TAB: analytics -->
    <div class="tab-pane fade show active" id="pills-analytics" role="tabpanel">

      <div class="row g-3 mb-4">
        <div class="col-6 col-md-3"><div class="card text-center"><div class="card-header">📁 فایل‌های ملکی</div><div class="card-body fs-4"><?= (int)$total_files ?></div></div></div>
        <div class="col-6 col-md-3"><div class="card text-center"><div class="card-header">👥 مشتریان</div><div class="card-body fs-4"><?= (int)$total_customers ?></div></div></div>
        <div class="col-6 col-md-3"><div class="card text-center"><div class="card-header">📞 تماس‌های ثبت‌شده</div><div class="card-body fs-4"><?= (int)$total_calls ?></div></div></div>
        <div class="col-6 col-md-3"><div class="card text-center"><div class="card-header">📅 تماس‌های امروز</div><div class="card-body fs-4"><?= (int)$today_calls ?></div></div></div>
      </div>

      <div class="card mb-4">
        <div class="card-header">📉 نمودار تماس‌های ۷ روز اخیر</div>
        <div class="card-body">
          <canvas id="callsChart" height="100"></canvas>
        </div>
      </div>

      <!-- Leaderboard -->
      <div class="card mb-4">
        <div class="card-header">🏆 رتبه‌بندی کارشناسان (در بازه انتخابی)</div>
        <div class="card-body">
          <div class="small text-muted mb-2">
            بازه: <?= h($start_date) ?> تا <?= h($end_date) ?>
            <?php if ($file_code !== ''): ?> | فیلتر کد فایل: <?= h($file_code) ?><?php endif; ?>
            <?php if ($sales_filter !== ''): ?> | فیلتر کارشناس: <?= h($sales_filter) ?><?php endif; ?>
          </div>

          <div class="table-responsive">
            <table class="table table-bordered table-striped align-middle">
              <thead class="table-light">
                <tr>
                  <th>#</th>
                  <th>کارشناس</th>
                  <th>کل تماس‌ها</th>
                  <th>موفق</th>
                  <th>ناموفق</th>
                  <th>منتظر تماس بعدی</th>
                  <th>نرخ موفقیت</th>
                </tr>
              </thead>
              <tbody>
              <?php if ($leaderboard): ?>
                <?php $rank = 1; foreach ($leaderboard as $r): ?>
                  <?php
                    $tot = (int)$r['total_calls'];
                    $suc = (int)$r['success_calls'];
                    $fail = (int)$r['failed_calls'];
                    $pend = (int)$r['pending_calls'];
                    $rate = (float)$r['success_rate'];
                    $name = trim((string)$r['salesperson_name']);
                    if ($name === '') $name = '—';
                  ?>
                  <tr>
                    <td><?= $rank++ ?></td>
                    <td><?= h($name) ?></td>
                    <td><?= $tot ?></td>
                    <td><?= $suc ?></td>
                    <td><?= $fail ?></td>
                    <td><?= $pend ?></td>
                    <td><?= h((string)$rate) ?>%</td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr><td colspan="7" class="text-center text-muted">داده‌ای برای رتبه‌بندی وجود ندارد.</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </div>

    <!-- TAB: contacts -->
    <div class="tab-pane fade" id="pills-contacts" role="tabpanel">
      <form method="GET" class="row g-2 mb-3 align-items-end">
        <input type="hidden" name="tab" value="contacts">
        <div class="col-12 col-md-3">
          <label class="form-label">از تاریخ</label>
          <input type="date" name="start_date" class="form-control" value="<?= h($start_date) ?>">
        </div>
        <div class="col-12 col-md-3">
          <label class="form-label">تا تاریخ</label>
          <input type="date" name="end_date" class="form-control" value="<?= h($end_date) ?>">
        </div>
        <div class="col-12 col-md-3">
          <label class="form-label">کارشناس</label>
          <input type="text" name="salesperson" class="form-control" value="<?= h($sales_filter) ?>">
        </div>
        <div class="col-12 col-md-3">
          <label class="form-label">کد فایل</label>
          <input type="text" name="file_code" class="form-control" value="<?= h($file_code) ?>">
        </div>
        <div class="col-12 col-md-3">
          <label class="form-label">وضعیت تماس</label>
          <select name="call_status" class="form-select">
            <option value="all" <?= $call_status==='all'?'selected':'' ?>>همه</option>
            <option value="موفق" <?= $call_status==='موفق'?'selected':'' ?>>موفق</option>
            <option value="ناموفق" <?= $call_status==='ناموفق'?'selected':'' ?>>ناموفق</option>
            <option value="منتظر تماس بعدی" <?= $call_status==='منتظر تماس بعدی'?'selected':'' ?>>منتظر تماس بعدی</option>
          </select>
        </div>
        <div class="col-12 col-md-3 d-grid">
          <button class="btn btn-primary">🔍 اعمال فیلتر</button>
        </div>
        <div class="col-12 col-md-3 d-grid">
          <a class="btn btn-success" target="_blank"
             href="report.php?print=1&tab=contacts&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>&salesperson=<?= urlencode($sales_filter) ?>&file_code=<?= urlencode($file_code) ?>&call_status=<?= urlencode($call_status) ?>">
            🖨️ چاپ PDF
          </a>
        </div>
        <div class="col-12 col-md-3 d-grid">
          <a class="btn btn-outline-secondary" href="report.php?tab=contacts">↩️ پاک‌سازی فیلتر</a>
        </div>
      </form>

      <div class="table-responsive mb-4">
        <table class="table table-bordered table-striped">
          <thead class="table-light">
            <tr>
              <th>نام مشتری</th>
              <th>شماره تماس</th>
              <th>وضعیت</th>
              <th>گزارش</th>
              <th>کد فایل</th>
              <th>کارشناس</th>
              <th>زمان تماس</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($contacts): ?>
              <?php foreach ($contacts as $c): ?>
                <tr>
                  <td><?= h((string)$c['customer_name']) ?></td>
                  <td><?= h((string)$c['customer_phone']) ?></td>
                  <td><?= h((string)$c['status']) ?></td>
                  <td><?= nl2br(h((string)($c['report'] ?? ''))) ?></td>
                  <td><?= h((string)$c['file_code']) ?></td>
                  <td><?= h((string)$c['file_salesperson']) ?></td>
                  <td><?= h((string)$c['created_at']) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="7" class="text-center text-muted">رکوردی برای نمایش وجود ندارد.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- TAB: users -->
    <div class="tab-pane fade" id="pills-users" role="tabpanel">
      <div class="row g-3">
        <div class="col-12 col-lg-4">
          <div class="card">
            <div class="card-header">➕ افزودن کاربر جدید</div>
            <div class="card-body">
              <form method="POST" class="row g-2">
                <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                <input type="hidden" name="action" value="create_user">
                <div class="col-12">
                  <label class="form-label">نام کاربری</label>
                  <input type="text" name="username" class="form-control" required>
                </div>
                <div class="col-12">
                  <label class="form-label">رمز عبور</label>
                  <input type="password" name="password" class="form-control" minlength="6" required>
                </div>
                <div class="col-12">
                  <label class="form-label">نقش</label>
                  <select name="role" class="form-select" required>
                    <option value="">انتخاب نقش...</option>
                    <option value="admin">مدیر</option>
                    <option value="supervisor">سوپروایزر</option>
                    <option value="sales">کارشناس فروش</option>
                    <option value="instagram_admin">ادمین اینستاگرام</option>
                  </select>
                </div>
                <div class="col-12">
                  <button class="btn btn-primary w-100">ثبت کاربر</button>
                </div>
              </form>
            </div>
          </div>
        </div>

        <div class="col-12 col-lg-8">
          <div class="card">
            <div class="card-header">فهرست کاربران</div>
            <div class="card-body">
              <div class="table-responsive">
                <table class="table table-bordered align-middle">
                  <thead>
                    <tr>
                      <th>نام کاربری</th>
                      <th>نقش</th>
                      <th>وضعیت</th>
                      <th style="width:320px;">عملیات</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($users as $u): ?>
                      <tr>
                        <td><?= h((string)$u['username']) ?></td>
                        <td>
                          <form method="POST" class="d-flex gap-2">
                            <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                            <input type="hidden" name="action" value="update_role">
                            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                            <select name="role" class="form-select form-select-sm" required>
                              <?php foreach ($roleLabels as $val=>$label): ?>
                                <option value="<?= h($val) ?>" <?= ((string)$u['role'] === $val) ? 'selected' : '' ?>><?= h($label) ?></option>
                              <?php endforeach; ?>
                            </select>
                            <button class="btn btn-sm btn-outline-primary">ثبت نقش</button>
                          </form>
                        </td>
                        <td><span class="badge badge-soft"><?= h((string)($u['status'] ?? 'active')) ?></span></td>
                        <td>
                          <div class="d-flex flex-wrap gap-2">
                            <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#pwModal<?= (int)$u['id'] ?>">🔒 تغییر رمز</button>
                            <form method="POST" onsubmit="return confirm('حذف این کاربر؟');">
                              <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                              <input type="hidden" name="action" value="delete_user">
                              <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                              <button class="btn btn-sm btn-danger">🗑 حذف</button>
                            </form>
                          </div>

                          <div class="modal fade" id="pwModal<?= (int)$u['id'] ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog">
                              <div class="modal-content">
                                <form method="POST">
                                  <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                                  <input type="hidden" name="action" value="reset_password">
                                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                  <div class="modal-header">
                                    <h5 class="modal-title">تغییر رمز: <?= h((string)$u['username']) ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                  </div>
                                  <div class="modal-body">
                                    <label class="form-label">رمز جدید</label>
                                    <input type="password" name="password" class="form-control" minlength="6" required>
                                  </div>
                                  <div class="modal-footer">
                                    <button class="btn btn-primary">ذخیره</button>
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">بستن</button>
                                  </div>
                                </form>
                              </div>
                            </div>
                          </div>

                        </td>
                      </tr>
                    <?php endforeach; ?>
                    <?php if (!$users): ?>
                      <tr><td colspan="4" class="text-center text-muted">کاربری ثبت نشده است.</td></tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>

  </div>
</div>

<script>
// تب انتخابی
(function(){
  const urlParams = new URLSearchParams(window.location.search);
  const tab = urlParams.get('tab');
  if (tab === 'contacts') {
    const t = document.querySelector('#pills-contacts-tab');
    if (t) new bootstrap.Tab(t).show();
  } else if (tab === 'users') {
    const t = document.querySelector('#pills-users-tab');
    if (t) new bootstrap.Tab(t).show();
  }
})();

// Chart
const ctx = document.getElementById('callsChart');
if (ctx) {
  const chartData = <?= json_encode($chart_data, JSON_UNESCAPED_UNICODE) ?>;
  const labels = chartData.map(i => i.date);
  const data = chartData.map(i => i.count);

  new Chart(ctx, {
    type: 'line',
    data: { labels, datasets: [{
      label: 'تعداد تماس‌های روزانه',
      data,
      borderColor: '#db6534',
      backgroundColor: 'rgba(219,101,52,.15)',
      tension: .3,
      fill: true,
      pointRadius: 4
    }]},
    options: { scales: { y: { beginAtZero: true } }, plugins: { legend: { display:false } } }
  });
}
</script>
</body>
</html>
