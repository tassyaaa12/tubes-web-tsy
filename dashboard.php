<?php
$page_title = "Dashboard";
$page_desc = "Ringkasan performa penjualan dan status operasional kafe saat ini.";

require_once 'config/koneksi.php';
require_once 'config/auth.php';
require_auth(); // Semua role bisa akses dashboard
include 'includes/header.php';

// Ambil Statistik
try {
    // 1. Total Penjualan Hari Ini
    $stmt1 = $conn->query("SELECT SUM(total_harga) as total_today FROM pesanan WHERE status_pembayaran = 'sudah_bayar' AND DATE(tanggal_pesanan) = CURDATE()");
    $total_today = $stmt1->fetch()['total_today'] ?? 0;

    // 2. Jumlah Pesanan Aktif (pending atau memasak)
    $stmt2 = $conn->query("SELECT COUNT(*) as active_orders FROM pesanan WHERE status_pesanan IN ('pending', 'memasak')");
    $active_orders = $stmt2->fetch()['active_orders'] ?? 0;

    // 3. Jumlah Meja Terisi
    $stmt3 = $conn->query("SELECT COUNT(*) as occupied_tables FROM meja WHERE status = 'terisi'");
    $occupied_tables = $stmt3->fetch()['occupied_tables'] ?? 0;

    // 4. Total Menu Tersedia
    $stmt4 = $conn->query("SELECT COUNT(*) as total_menu FROM menu WHERE status = 'tersedia'");
    $total_menu = $stmt4->fetch()['total_menu'] ?? 0;

    // Ambil Data Meja untuk Denah
    $stmt_meja = $conn->query("SELECT * FROM meja ORDER BY nomor_meja ASC");
    $daftar_meja = $stmt_meja->fetchAll();

    // Ambil 5 Transaksi Terakhir
    $stmt_trx = $conn->query("
        SELECT t.id, t.tanggal_transaksi, p.total_harga, t.metode_pembayaran, m.nomor_meja, pl.nama_pelanggan
        FROM transaksi t
        JOIN pesanan p ON t.id_pesanan = p.id
        LEFT JOIN meja m ON p.id_meja = m.id
        LEFT JOIN pelanggan pl ON p.id_pelanggan = pl.id
        ORDER BY t.tanggal_transaksi DESC
        LIMIT 5
    ");
    $recent_trx = $stmt_trx->fetchAll();

} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>Gagal memuat data dashboard: " . $e->getMessage() . "</div>";
    die();
}
?>

<!-- Widget Statistik -->
<div class="row g-4 mb-4">
    <!-- Total Pendapatan Hari Ini -->
    <div class="col-12 col-md-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-info">
                <div class="stat-value">Rp <?= number_format($total_today, 0, ',', '.') ?></div>
                <div class="stat-label">Pendapatan Hari Ini</div>
            </div>
            <div class="stat-icon icon-green">
                <i class="fa-solid fa-money-bill-trend-up"></i>
            </div>
        </div>
    </div>
    
    <!-- Pesanan Aktif -->
    <div class="col-12 col-md-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-info">
                <div class="stat-value"><?= $active_orders ?></div>
                <div class="stat-label">Pesanan Aktif</div>
            </div>
            <div class="stat-icon icon-blue">
                <i class="fa-solid fa-spinner fa-spin-slow"></i>
            </div>
        </div>
    </div>
    
    <!-- Meja Terpakai -->
    <div class="col-12 col-md-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-info">
                <div class="stat-value"><?= $occupied_tables ?> / <?= count($daftar_meja) ?></div>
                <div class="stat-label">Meja Terisi</div>
            </div>
            <div class="stat-icon icon-purple">
                <i class="fa-solid fa-chair"></i>
            </div>
        </div>
    </div>
    
    <!-- Total Menu -->
    <div class="col-12 col-md-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-info">
                <div class="stat-value"><?= $total_menu ?></div>
                <div class="stat-label">Menu Tersedia</div>
            </div>
            <div class="stat-icon icon-red">
                <i class="fa-solid fa-bowl-food"></i>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Denah/Status Meja Kafe -->
    <div class="col-12 col-lg-7">
        <div class="custom-card h-100">
            <div class="card-title-custom">
                <span><i class="fa-solid fa-map-location-dot me-2 text-info"></i>Denah & Status Meja</span>
                <span class="badge bg-secondary font-monospace small" style="font-size: 11px;">Aktif</span>
            </div>
            
            <div class="table-map-container">
                <?php foreach ($daftar_meja as $meja): ?>
                    <div class="table-card <?= $meja['status'] == 'kosong' ? 'empty' : 'occupied' ?>" onclick="location.href='pesanan.php'">
                        <div class="table-number"><?= htmlspecialchars($meja['nomor_meja']) ?></div>
                        <div class="table-capacity"><i class="fa-solid fa-users me-1"></i> Kapasitas: <?= htmlspecialchars($meja['kapasitas']) ?></div>
                        <span class="badge-custom <?= $meja['status'] == 'kosong' ? 'badge-table-empty' : 'badge-table-occupied' ?>">
                            <i class="fa-solid <?= $meja['status'] == 'kosong' ? 'fa-circle-check' : 'fa-circle-dot' ?>"></i>
                            <?= $meja['status'] == 'kosong' ? 'KOSONG' : 'TERISI' ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Transaksi Terbaru -->
    <div class="col-12 col-lg-5">
        <div class="custom-card h-100">
            <div class="card-title-custom">
                <span><i class="fa-solid fa-clock-rotate-left me-2 text-warning"></i>Transaksi Terakhir</span>
                <a href="laporan.php" class="btn btn-sm btn-outline-info border-0"><i class="fa-solid fa-arrow-right"></i></a>
            </div>
            
            <div class="table-responsive">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>Meja/Pelanggan</th>
                            <th>Total</th>
                            <th>Metode</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($recent_trx) > 0): ?>
                            <?php foreach ($recent_trx as $trx): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars($trx['nomor_meja'] ?? 'Takeaway') ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($trx['nama_pelanggan'] ?? 'Umum') ?></small>
                                    </td>
                                    <td class="text-info fw-bold">Rp <?= number_format($trx['total_harga'], 0, ',', '.') ?></td>
                                    <td>
                                        <span class="badge-custom badge-done"><?= strtoupper($trx['metode_pembayaran']) ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="3" class="text-center text-muted py-4">Belum ada transaksi hari ini.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
