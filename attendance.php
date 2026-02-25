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

// --- هدرهای امنیتی ---
header("X-Frame-Options: SAMEORIGIN");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");

if (empty($_SESSION['user'])) { header('Location: login.php'); exit; }

$role         = (string)($_SESSION['role'] ?? '');
$isAdmin      = ($role === 'admin');
$isSupervisor = ($role === 'supervisor');

if (!$isAdmin && !$isSupervisor) {
    http_response_code(403);
    exit('دسترسی غیرمجاز');
}

if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
$CSRF = (string)$_SESSION['csrf'];

// --------- ابزارهای کوچک ----------
$validDate = function(string $d): bool {
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
};

// ثبت حضور
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    if (empty($_POST['csrf']) || !hash_equals($CSRF, (string)$_POST['csrf'])) { exit('CSRF نامعتبر'); }

    $user_id = (int)($_POST['user_id'] ?? 0);
    $date    = trim((string)($_POST['date'] ?? ''));
    $shift   = trim((string)($_POST['shift'] ?? 'morning'));
    $status  = trim((string)($_POST['status'] ?? 'present'));

    $shiftOk  = in_array($shift, ['morning','evening'], true);
    $statusOk = in_array($status, ['present','absent'], true);

    if ($user_id > 0 && $validDate($date) && $shiftOk && $statusOk) {

        // وجودی—اگر قبلاً هست، آپدیت؛ اگر نیست، اینسرت
        $chk = $pdo->prepare("SELECT id FROM attendance WHERE user_id=:u AND date=:d AND shift=:s LIMIT 1");
        $chk->execute(['u'=>$user_id,'d'=>$date,'s'=>$shift]);
        $aid = $chk->fetchColumn();

        if ($aid) {
            $upd = $pdo->prepare("UPDATE attendance SET status=:st WHERE id=:id");
            $upd->execute(['st'=>$status, 'id'=>$aid]);
        } else {
            $ins = $pdo->prepare("INSERT INTO attendance (user_id, date, shift, status) VALUES (:u,:d,:s,:st)");
            $ins->execute(['u'=>$user_id,'d'=>$date,'s'=>$shift,'st'=>$status]);
        }

        $msg = 'ثبت شد.';
    } else {
        $msg = 'اطلاعات ناقص یا نامعتبر است.';
    }
}

// لیست کاربران برای انتخاب
// پیشنهاد: فقط نقش‌های عملیاتی را بیاوریم (اگر خواستی همه را نشان بده، WHERE نقش را بردار)
$users = $pdo->query("
    SELECT id, fullname, username, role
    FROM users
    WHERE status='active'
      AND role IN ('sales','instagram_admin','supervisor')
    ORDER BY role, fullname
")->fetchAll(PDO::FETCH_ASSOC);

// فیلتر گزارش
$from = (string)($_GET['from'] ?? date('Y-m-01'));
$to   = (string)($_GET['to']   ?? date('Y-m-d'));

if (!$validDate($from)) $from = date('Y-m-01');
if (!$validDate($to))   $to   = date('Y-m-d');

$stm = $pdo->prepare("
  SELECT a.*, u.fullname, u.username, u.role
  FROM attendance a
  JOIN users u ON u.id=a.user_id
  WHERE a.date BETWEEN :f AND :t
  ORDER BY a.date DESC, a.shift ASC, u.fullname ASC
");
$stm->execute(['f'=>$from, 't'=>$to]);
$rows = $stm->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <title>حضور و غیاب</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
  <style>
    :root {
      --main-orange: #db6534;
      --background-light: #f8f9fa;
      --card-bg: #ffffff;
      --hover-bg: rgba(0, 0, 0, 0.05);
    }

    body {
      background-color: var(--background-light);
      font-family: 'Vazir', sans-serif;
    }

    .card {
      border-radius: 12px;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
      background-color: var(--card-bg);
    }

    .card-header {
      background-color: var(--card-bg);
      border-bottom: 2px solid var(--main-orange);
      font-weight: 600;
    }

    .table thead th {
      background-color: #f8f9fa;
    }

    .table-striped tbody tr:hover {
      background-color: var(--hover-bg);
    }

    .btn-primary {
      background-color: var(--main-orange);
      border-color: var(--main-orange);
    }

    .btn-primary:hover {
      background-color: #e77b4b;
      border-color: #e77b4b;
    }

    .form-label {
      font-weight: bold;
    }

    .pagination .page-item.active .page-link {
      background-color: var(--main-orange);
      border-color: var(--main-orange);
    }

    .form-control:focus {
      border-color: var(--main-orange);
      box-shadow: 0 0 0 0.25rem rgba(219, 101, 52, 0.25);
    }

    .alert-info {
      background-color: #e7f3fe;
      color: #3178b3;
    }
  </style>
</head>
<body>
<div class="container py-4">

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4>🗓 ثبت حضور و غیاب</h4>
    <div>
      <a class="btn btn-outline-secondary" href="index.php">بازگشت</a>
    </div>
  </div>

  <?php if ($msg): ?><div class="alert alert-info"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

  <!-- فرم ثبت حضور و غیاب -->
  <div class="card mb-4">
    <div class="card-header">ثبت حضور امروز/روز دلخواه</div>
    <div class="card-body">
      <form method="POST" class="row g-3">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
        <input type="hidden" name="action" value="save">
        <div class="col-md-4">
          <label class="form-label">کاربر</label>
          <select name="user_id" class="form-select" required>
            <option value="">انتخاب...</option>
            <?php foreach ($users as $u): ?>
              <option value="<?= (int)$u['id'] ?>">
                <?= htmlspecialchars($u['fullname'] ?: $u['username']) ?> (<?= htmlspecialchars($u['role']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">تاریخ</label>
          <input type="date" name="date" class="form-control" value="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">شیفت</label>
          <select name="shift" class="form-select">
            <option value="morning">صبح</option>
            <option value="evening">عصر</option>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">وضعیت</label>
          <select name="status" class="form-select">
            <option value="present">حاضر</option>
            <option value="absent">غایب</option>
          </select>
        </div>
        <div class="col-12">
          <button class="btn btn-primary w-100">ثبت</button>
        </div>
      </form>
    </div>
  </div>

  <!-- گزارش حضور -->
  <div class="card">
    <div class="card-header">گزارش حضور</div>
    <div class="card-body">
      <form method="GET" class="row g-3 mb-3">
        <div class="col-md-3">
          <label class="form-label">از تاریخ</label>
          <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($from) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">تا تاریخ</label>
          <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($to) ?>">
        </div>
        <div class="col-md-2 align-self-end">
          <button class="btn btn-outline-primary w-100">نمایش</button>
        </div>
      </form>

      <div class="table-responsive">
        <table class="table table-bordered table-striped">
          <thead>
            <tr>
              <th>تاریخ</th>
              <th>شیفت</th>
              <th>نام</th>
              <th>نقش</th>
              <th>وضعیت</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?= htmlspecialchars((string)$r['date']) ?></td>
                <td><?= ((string)$r['shift'] === 'morning') ? 'صبح' : 'عصر' ?></td>
                <td><?= htmlspecialchars((string)($r['fullname'] ?: $r['username'])) ?></td>
                <td><?= htmlspecialchars((string)$r['role']) ?></td>
                <td><?= ((string)$r['status'] === 'present') ? 'حاضر' : 'غایب' ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
              <tr><td colspan="5" class="text-center text-muted">رکوردی نیست</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>
</body>
</html>
