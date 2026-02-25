<?php
session_start();
require __DIR__ . '/db.php';

// فقط مدیر اجازه دسترسی دارد
if (empty($_SESSION['user']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('دسترسی غیرمجاز');
}

// CSRF
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$CSRF = $_SESSION['csrf'];

// دریافت کاربران
$users = $pdo->query("SELECT id, username, role FROM users ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<title>مدیریت کاربران</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container my-4">

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">👥 مدیریت کاربران</h4>
    <a href="index.php" class="btn btn-secondary">بازگشت</a>
  </div>

  <!-- افزودن کاربر -->
  <div class="card mb-4">
    <div class="card-header">➕ افزودن کاربر جدید</div>
    <div class="card-body">
      <form method="POST" action="create_user.php" class="row g-2">
        <input type="hidden" name="csrf" value="<?= $CSRF ?>">
        <div class="col-md-3">
          <input type="text" name="username" class="form-control" placeholder="نام کاربری" required>
        </div>
        <div class="col-md-3">
          <input type="password" name="password" class="form-control" placeholder="رمز عبور" required>
        </div>
        <div class="col-md-3">
          <select name="role" class="form-select" required>
            <option value="">نقش را انتخاب کنید</option>
            <option value="admin">مدیر</option>
            <option value="supervisor">سوپروایزر</option>
            <option value="sales">کارشناس فروش</option>
            <option value="instagram_admin">ادمین اینستاگرام</option>
          </select>
        </div>
        <div class="col-md-3">
          <button class="btn btn-primary w-100" type="submit">افزودن</button>
        </div>
      </form>
    </div>
  </div>

  <!-- لیست کاربران -->
  <div class="card">
    <div class="card-header">فهرست کاربران</div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-bordered table-hover align-middle">
          <thead>
            <tr>
              <th style="width:25%;">نام کاربری</th>
              <th style="width:20%;">نقش</th>
              <th style="width:55%;">عملیات</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $u): ?>
            <tr>
              <td><?= htmlspecialchars($u['username']) ?></td>
              <td>
                <!-- فرم تغییر نقش -->
                <form method="POST" action="update_user.php" class="d-flex gap-2">
                  <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <select name="role" class="form-select form-select-sm" required>
                    <?php
                      $roles = ['admin'=>'مدیر','supervisor'=>'سوپروایزر','sales'=>'کارشناس فروش','instagram_admin'=>'ادمین اینستاگرام'];
                      foreach ($roles as $val=>$label):
                    ?>
                      <option value="<?= $val ?>" <?= $u['role']===$val?'selected':'' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button class="btn btn-sm btn-outline-primary">ثبت نقش</button>
                </form>
              </td>
              <td>
                <div class="d-flex flex-wrap gap-2">
                  <!-- دکمه باز کردن مودال تغییر رمز -->
                  <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#pwModal<?= (int)$u['id'] ?>">
                    🔒 تغییر رمز
                  </button>

                  <!-- حذف کاربر -->
                  <form method="POST" action="delete_user.php" class="d-inline" onsubmit="return confirm('آیا از حذف این کاربر مطمئن هستید؟');">
                    <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                    <button class="btn btn-sm btn-danger">🗑 حذف</button>
                  </form>
                </div>

                <!-- مودال تغییر رمز -->
                <div class="modal fade" id="pwModal<?= (int)$u['id'] ?>" tabindex="-1" aria-hidden="true">
                  <div class="modal-dialog">
                    <div class="modal-content">
                      <form method="POST" action="reset_password.php">
                        <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                        <div class="modal-header">
                          <h5 class="modal-title">تغییر رمز برای: <?= htmlspecialchars($u['username']) ?></h5>
                          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="بستن"></button>
                        </div>
                        <div class="modal-body">
                          <div class="mb-3">
                            <label class="form-label">رمز جدید</label>
                            <input type="password" name="password" class="form-control" minlength="6" required>
                          </div>
                          <div class="mb-1 small text-muted">حداقل ۶ کاراکتر. ترکیبی از حروف و اعداد پیشنهاد می‌شود.</div>
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
              <tr><td colspan="3" class="text-center text-muted">کاربری ثبت نشده است</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
