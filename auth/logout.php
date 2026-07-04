<?php
require_once '../config/auth.php';

// Hapus cookie "Ingat Saya" jika ada
clear_remember_cookie();

// Hapus semua data session
session_unset();
session_destroy();

// Redirect ke halaman login
header("Location: login.php");
exit();
