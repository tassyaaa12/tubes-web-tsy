<?php
/**
 * config/auth.php
 * Middleware Autentikasi & Manajemen Session + Cookie
 * 
 * Include file ini di awal setiap halaman yang butuh autentikasi.
 * Gunakan: require_once 'config/auth.php';
 * 
 * Untuk halaman yang HANYA boleh diakses admin:
 *   require_role('admin');
 */

if (session_status() === PHP_SESSION_NONE) {
    // Konfigurasi session yang lebih aman
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Strict');
    
    // Set cookie lifetime ke 5 detik (delay 5 detik saat web ditutup)
    session_set_cookie_params([
        'lifetime' => 5,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    
    session_start();
}

// Perbarui masa berlaku cookie session agar tetap aktif selama user membuka web
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), session_id(), [
        'expires' => time() + 5, // 5 detik dari sekarang
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
}

// =========================================================
// KONSTANTA KONFIGURASI
// =========================================================
define('COOKIE_NAME',       'kafe_remember');  // nama cookie "ingat saya"
define('COOKIE_LIFETIME',   60 * 60 * 24 * 7); // 7 hari
define('COOKIE_SECRET',     'k4fe_s3cr3t!K@s!r&@dm!n_2025_xZ9q'); // kunci HMAC rahasia
define('ALLOWED_ROLES',     ['admin', 'kasir']); // role yang diizinkan dalam sistem

// =========================================================
// FUNGSI HELPER COOKIE
// =========================================================

/**
 * Buat token cookie yang ditandatangani (signed)
 * Format: base64(username|role) + "." + HMAC
 */
function generate_cookie_token(string $username, string $role): string {
    $payload   = base64_encode($username . '|' . $role);
    $signature = hash_hmac('sha256', $payload, COOKIE_SECRET);
    return $payload . '.' . $signature;
}

/**
 * Verifikasi dan parse token cookie
 * Mengembalikan ['username' => ..., 'role' => ...] atau false jika tidak valid
 */
function verify_cookie_token(string $token): array|false {
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) return false;

    [$payload, $received_sig] = $parts;

    // Pastikan tanda tangan cocok (mencegah tampering)
    $expected_sig = hash_hmac('sha256', $payload, COOKIE_SECRET);
    if (!hash_equals($expected_sig, $received_sig)) return false;

    $decoded = base64_decode($payload, true);
    if ($decoded === false) return false;

    $pieces = explode('|', $decoded, 2);
    if (count($pieces) !== 2) return false;

    return ['username' => $pieces[0], 'role' => $pieces[1]];
}

/**
 * Set cookie "ingat saya" di browser pengguna
 */
function set_remember_cookie(string $username, string $role): void {
    $token   = generate_cookie_token($username, $role);
    $expires = time() + COOKIE_LIFETIME;
    setcookie(COOKIE_NAME, $token, [
        'expires'  => $expires,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

/**
 * Hapus cookie "ingat saya" dari browser
 */
function clear_remember_cookie(): void {
    setcookie(COOKIE_NAME, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    unset($_COOKIE[COOKIE_NAME]);
}

// =========================================================
// LOGIKA UTAMA AUTENTIKASI
// =========================================================

// Session tidak pakai timeout otomatis.
// Sesi berakhir saat browser ditutup (kecuali pakai cookie "Ingat Saya").

/**
 * Auto-login dari cookie "ingat saya" jika session belum ada
 */
if (!isset($_SESSION['user_id']) && !empty($_COOKIE[COOKIE_NAME])) {
    $cookie_data = verify_cookie_token($_COOKIE[COOKIE_NAME]);

    if ($cookie_data) {
        // Cookie valid – ambil data user dari DB untuk memastikan akun masih ada
        if (!isset($conn)) {
            // Dapatkan root path (1 level naik dari halaman root project)
            $base = dirname(__DIR__);
            require_once $base . '/config/koneksi.php';
        }

        // Pastikan role dari cookie adalah role yang diizinkan (admin / kasir)
        if (!in_array($cookie_data['role'], ALLOWED_ROLES, true)) {
            clear_remember_cookie();
        } else {
            $stmt = $conn->prepare(
                "SELECT id, username, nama_lengkap, role FROM users
                 WHERE username = :username AND role = :role
                 AND role IN ('admin', 'kasir') LIMIT 1"
            );
            $stmt->execute([
                'username' => $cookie_data['username'],
                'role'     => $cookie_data['role'],
            ]);
            $user_from_cookie = $stmt->fetch();

            if ($user_from_cookie) {
                // Restore session dari cookie
                $_SESSION['user_id']       = $user_from_cookie['id'];
                $_SESSION['username']      = $user_from_cookie['username'];
                $_SESSION['nama_lengkap']  = $user_from_cookie['nama_lengkap'];
                $_SESSION['role']          = $user_from_cookie['role'];
                $_SESSION['last_activity'] = time();

                // Regenerasi session ID untuk keamanan
                session_regenerate_id(true);
            } else {
                // Akun tidak ditemukan / role tidak valid – hapus cookie
                clear_remember_cookie();
            }
        }
    } else {
        // Token cookie rusak / dipalsukan
        clear_remember_cookie();
    }
}

// =========================================================
// FUNGSI KONTROL AKSES
// =========================================================

/**
 * Pastikan user sudah login. Jika belum, redirect ke halaman login.
 * Panggil di semua halaman yang butuh autentikasi.
 */
function require_auth(string $redirect_to = 'auth/login.php'): void {
    if (!isset($_SESSION['user_id'])) {
        header("Location: {$redirect_to}");
        exit();
    }
}

/**
 * Pastikan user memiliki role tertentu.
 * Jika tidak, tampilkan halaman error 403 atau redirect.
 * Contoh: require_role('admin');
 */
function require_role(string ...$allowed_roles): void {
    require_auth();
    $user_role = $_SESSION['role'] ?? '';
    if (!in_array($user_role, $allowed_roles, true)) {
        // Tampilkan halaman error akses ditolak
        http_response_code(403);
        ?>
        <!DOCTYPE html>
        <html lang="id">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Akses Ditolak</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
            <link href="assets/css/style.css" rel="stylesheet">
        </head>
        <body class="login-body">
            <div class="login-card text-center">
                <i class="fa-solid fa-ban fa-3x mb-3" style="color: var(--accent-red);"></i>
                <h4 class="fw-bold mb-2">Akses Ditolak (403)</h4>
                <p class="text-muted mb-4">Anda tidak memiliki izin untuk mengakses halaman ini.<br>
                   Silakan hubungi administrator sistem.</p>
                <a href="dashboard.php" class="btn btn-primary border-0 px-4"
                   style="background: var(--gradient-primary); border-radius: var(--border-radius);">
                    <i class="fa-solid fa-house me-2"></i>Kembali ke Dashboard
                </a>
            </div>
        </body>
        </html>
        <?php
        exit();
    }
}

/**
 * Cek apakah user login saat ini adalah admin
 */
function is_admin(): bool {
    return ($_SESSION['role'] ?? '') === 'admin';
}

/**
 * Cek apakah user login saat ini adalah kasir
 */
function is_kasir(): bool {
    return ($_SESSION['role'] ?? '') === 'kasir';
}

/**
 * Dapatkan label role dalam Bahasa Indonesia
 */
function get_role_label(): string {
    return match($_SESSION['role'] ?? '') {
        'admin'  => 'Administrator',
        'kasir'  => 'Kasir',
        default  => 'Tidak Dikenal',
    };
}
