<?php
/***********************************************
 * db.php — اتصال ایمن به دیتابیس با PDO
 ***********************************************/

$host     = 'localhost';
$db       = 'melkmema_crmdatabase';
$user     = 'melkmema_crmadminmelkmemar';
$pass     = 'L!Lj!tWas(ANV!Ix';
$charset  = 'utf8mb4';

$dsn = "mysql:host={$host};dbname={$db};charset={$charset}";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // فعال کردن Exception برای خطاها
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // نتایج به صورت associative
    PDO::ATTR_EMULATE_PREPARES   => false,                  // غیرفعال کردن emulate برای امنیت بالاتر
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    error_log("DB Connection Error: " . $e->getMessage(), 3, __DIR__ . '/php-error.log');
    exit('❌ اتصال به دیتابیس برقرار نشد. لطفاً بعداً دوباره تلاش کنید.');
}
