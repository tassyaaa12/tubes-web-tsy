<?php
// Koneksi Database PHP Native menggunakan PDO

// Fungsi helper untuk memuat file .env
if (!function_exists('loadEnv')) {
    function loadEnv($path) {
        if (!file_exists($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            // Abaikan baris komentar
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $key = trim($parts[0]);
                $val = trim($parts[1]);
                // Hapus tanda kutip jika ada
                if (preg_match('/^"([^"]*)"$/', $val, $matches)) {
                    $val = $matches[1];
                } elseif (preg_match('/^\'([^\']*)\'$/', $val, $matches)) {
                    $val = $matches[1];
                }
                $_ENV[$key] = $val;
                $_SERVER[$key] = $val;
                putenv("{$key}={$val}");
            }
        }
    }
}

// Muat berkas .env dari root folder
loadEnv(dirname(__DIR__) . '/.env');

// Dapatkan konfigurasi dengan fallback ke nilai bawaan
$connection = getenv('DB_CONNECTION') ?: 'mysql';
$host       = getenv('DB_HOST')       ?: 'localhost';
$port       = getenv('DB_PORT')       ?: ($connection === 'pgsql' ? '5432' : '3306');
$database   = getenv('DB_DATABASE')   ?: 'db_kafe';
$username   = getenv('DB_USERNAME')   ?: 'root';
$password   = getenv('DB_PASSWORD')   !== false ? getenv('DB_PASSWORD') : '';
$sslmode    = getenv('DB_SSLMODE')    ?: '';

try {
    if ($connection === 'pgsql') {
        $dsn = "pgsql:host=$host;port=$port;dbname=$database";
        if (!empty($sslmode)) {
            $dsn .= ";sslmode=$sslmode";
        }
        $conn = new PDO($dsn, $username, $password);
    } else {
        $conn = new PDO("mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4", $username, $password);
    }
    
    // Set error mode PDO ke Exception untuk mempermudah penanganan error
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    // Tampilkan pesan error jika koneksi gagal
    die("Koneksi database gagal: " . $e->getMessage());
}
?>
