<?php
session_start();

// Check the correct session key your index.php actually sets
if (empty($_SESSION['user'])) {
    header("Location: /inplace/index.php");
    exit;
}

$role = $_SESSION['user']['role'];

switch ($role) {
    case 'student':  header("Location: /inplace/student/dashboard.php");  break;
    case 'tutor':    header("Location: /inplace/tutor/dashboard.php");     break;
    case 'provider': header("Location: /inplace/provider/dashboard.php"); break;
    case 'admin':    header("Location: /inplace/admin/dashboard.php");      break;
    case 'director': header("Location: /inplace/director/dashboard.php"); break;
    default:
        session_destroy();
        header("Location: /inplace/index.php");
}
exit;