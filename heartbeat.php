<?php
// heartbeat.php
// Menjaga agar session tetap aktif selama tab/halaman browser dibuka

require_once 'config/koneksi.php';
require_once 'config/auth.php';

header('Content-Type: application/json');

if (isset($_SESSION['user_id'])) {
    // Perbarui waktu heartbeat agar sesi tidak dianggap mati
    $_SESSION['last_heartbeat'] = time();
    
    echo json_encode([
        'status' => 'active',
        'expires_in' => 60
    ]);
} else {
    http_response_code(401);
    echo json_encode([
        'status' => 'expired'
    ]);
}
