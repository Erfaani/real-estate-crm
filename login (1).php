<?php
declare(strict_types=1);
session_start();

// اگر کاربر قبلاً وارد شده باشد، مستقیماً به صفحه اصلی هدایت شود
if (isset($_SESSION['user'])) {
    header("Location: index.php");
    exit;
}

require __DIR__ . '/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'نام کاربری و رمز عبور الزامی است.';
    } else {
        // جست‌وجو فقط بین کاربران فعال
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :u AND status = 'active' LIMIT 1");
        $stmt->execute(['u' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            // ذخیره اطلاعات کلیدی در سشن
            $_SESSION['user'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['fullname'] = $user['fullname'] ?? '';
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['csrf'] = bin2hex(random_bytes(32));

            // هدایت به داشبورد
            header("Location: index.php");
            exit;
        } else {
            $error = 'نام کاربری یا رمز عبور اشتباه است.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>ورود به CRM ملک و معمار</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f5f5f5;
            font-family: 'Vazir', sans-serif;
        }
        .login-card {
            max-width: 400px;
            margin: 50px auto;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        .login-header {
            background-color: #db6534;
            color: #fff;
            border-top-left-radius: 10px;
            border-top-right-radius: 10px;
            padding: 20px;
            text-align: center;
        }
        .login-card-body {
            padding: 30px;
            background-color: #fff;
            border-bottom-left-radius: 10px;
            border-bottom-right-radius: 10px;
        }
        .form-control:focus {
            border-color: #db6534;
            box-shadow: 0 0 0 0.2rem rgba(219, 101, 52, 0.25);
        }
        button.btn-primary {
            background-color: #db6534;
            border-color: #db6534;
        }
        button.btn-primary:hover {
            background-color: #b34f29;
            border-color: #b34f29;
        }
        .form-label {
            font-weight: 600;
        }
        .alert {
            margin-top: 15px;
            font-size: 0.9rem;
        }
        .alert-danger {
            background-color: #f8d7da;
            color: #842029;
        }
        .login-card-body .form-control {
            height: 45px;
            font-size: 1rem;
        }
        .btn-primary:focus, .btn-outline-primary:focus {
            box-shadow: none;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-header">
            <h3>CRM ملک و معمار</h3>
        </div>
        <div class="login-card-body">
            <h5 class="mb-4 text-center">ورود به سیستم</h5>
            
            <!-- Error message display -->
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger text-center"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" novalidate>
                <div class="mb-3">
                    <label class="form-label">نام کاربری</label>
                    <input type="text" name="username" class="form-control" required placeholder="نام کاربری خود را وارد کنید">
                </div>
                <div class="mb-3">
                    <label class="form-label">رمز عبور</label>
                    <input type="password" name="password" class="form-control" required placeholder="رمز عبور خود را وارد کنید">
                </div>
                <button type="submit" class="btn btn-primary w-100">ورود</button>
            </form>

            <!-- Forgot Password Link -->
            <div class="text-center mt-3">
                <a href="forgot_password.php" class="text-muted">فراموشی رمز عبور؟</a>
            </div>
        </div>
    </div>

    <!-- Optional: You can add a background image or animation here -->
    <script>
        // Optional: Add focus effect to inputs when clicked
        const inputs = document.querySelectorAll('.form-control');
        inputs.forEach(input => {
            input.addEventListener('focus', () => {
                input.style.borderColor = '#db6534';
            });
            input.addEventListener('blur', () => {
                input.style.borderColor = '';
            });
        });
    </script>
</body>
</html>

