<?php
// logout.php - خروج از حساب کاربری
session_start();
session_unset();
session_destroy();
header("Location: login.php");
exit;
?>
