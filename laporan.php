<?php
$page_title = "Laporan Penjualan";
$page_desc =
  "Pantau omzet pendapatan, riwayat transaksi, dan rekap metode pembayaran.";

require_once "config/koneksi.php";
require_once "config/auth.php";
require_role("admin"); // Hanya admin yang dapat melihat laporan keuangan

// Set default filter tanggal (Awal bulan ini s/d hari ini)
$tanggal_mulai = $_GET["tanggal_mulai"] ?? date("Y-m-01");
$tanggal_selesai = $_GET["tanggal_selesai"] ?? date("Y-m-d");

// Query summary statistik berdasarkan range tanggal
try {
  // 1. Total Omzet
  $stmt_omzet = $conn->prepare("
        SELECT SUM(total_harga) as total_omzet, COUNT(*) as total_trx
        FROM pesanan
        WHERE status_pembayaran = 'sudah_bayar'
        AND DATE(tanggal_pesanan) BETWEEN :mulai AND :selesai
    ");
  $stmt_omzet->execute([
    "mulai" => $tanggal_mulai,
    "selesai" => $tanggal_selesai,
  ]);
  $summary = $stmt_omzet->fetch();

  $total_omzet = $summary["total_omzet"] ?? 0;
  $total_trx = $summary["total_trx"] ?? 0;

  // Average Order Value (AOV)
  $aov = $total_trx > 0 ? $total_omzet / $total_trx : 0;

  // 2. Proporsi Metode Pembayaran
  $stmt_metode = $conn->prepare("
        SELECT t.metode_pembayaran, COUNT(*) as jumlah_trx, SUM(p.total_harga) as total_nominal
        FROM transaksi t
        JOIN pesanan p ON t.id_pesanan = p.id
        WHERE p.status_pembayaran = 'sudah_bayar'
        AND DATE(p.tanggal_pesanan) BETWEEN :mulai AND :selesai
        GROUP BY t.metode_pembayaran
    ");
  $stmt_metode->execute([
    "mulai" => $tanggal_mulai,
    "selesai" => $tanggal_selesai,
  ]);
  $metode_stats = $stmt_metode->fetchAll();

  // Data untuk grafik bar persentase metode pembayaran
  $metode_data = ["tunai" => 0, "debit" => 0, "qris" => 0];
  $metode_nominal = ["tunai" => 0, "debit" => 0, "qris" => 0];
  foreach ($metode_stats as $stat) {
    $m = $stat["metode_pembayaran"];
    if (isset($metode_data[$m])) {
      $metode_data[$m] = $stat["jumlah_trx"];
      $metode_nominal[$m] = $stat["total_nominal"];
    }
  }

  // 3. Daftar Riwayat Transaksi Lengkap
  $stmt_trx_list = $conn->prepare("
        SELECT t.id as id_transaksi, t.tanggal_transaksi, p.id as id_pesanan, p.total_harga,
               t.metode_pembayaran, m.nomor_meja, pl.nama_pelanggan, u.nama_lengkap as nama_kasir
        FROM transaksi t
        JOIN pesanan p ON t.id_pesanan = p.id
        LEFT JOIN meja m ON p.id_meja = m.id
        LEFT JOIN pelanggan pl ON p.id_pelanggan = pl.id
        LEFT JOIN users u ON p.id_user = u.id
        WHERE p.status_pembayaran = 'sudah_bayar'
        AND DATE(p.tanggal_pesanan) BETWEEN :mulai AND :selesai
        ORDER BY t.tanggal_transaksi DESC
    ");
  $stmt_trx_list->execute([
    "mulai" => $tanggal_mulai,
    "selesai" => $tanggal_selesai,
  ]);
  $daftar_transaksi = $stmt_trx_list->fetchAll();
} catch (PDOException $e) {
  echo "<div class='alert alert-danger'>Gagal memuat data laporan: " .
    $e->getMessage() .
    "</div>";
  die();
}

include "includes/header.php";
?>

<!-- Filter Card -->
<div class="custom-card no-print">
    <div class="card-title-custom">
        <span><i class="fa-solid fa-filter me-2 text-info"></i>Filter Laporan Penjualan</span>
    </div>
    <form action="laporan.php" method="GET" class="row g-3">
        <div class="col-12 col-md-4">
            <label for="tanggal_mulai" class="form-label small text-muted">Tanggal Mulai</label>
            <input type="date" name="tanggal_mulai" id="tanggal_mulai" class="form-control form-control-custom" value="<?= htmlspecialchars(
              $tanggal_mulai,
            ) ?>" required>
        </div>
        <div class="col-12 col-md-4">
            <label for="tanggal_selesai" class="form-label small text-muted">Tanggal Selesai</label>
            <input type="date" name="tanggal_selesai" id="tanggal_selesai" class="form-control form-control-custom" value="<?= htmlspecialchars(
              $tanggal_selesai,
            ) ?>" required>
        </div>
        <div class="col-12 col-md-4 d-flex align-items-end gap-2">
            <button type="submit" class="btn btn-primary border-0 flex-grow-1" style="background: var(--gradient-primary); border-radius: 8px;">
                <i class="fa-solid fa-sync me-2"></i>Terapkan Filter
            </button>
            <button type="button" class="btn btn-outline-info border-0" onclick="window.print()" style="border-radius: 8px;" title="Cetak Laporan">
                <i class="fa-solid fa-print"></i>
            </button>
        </div>
    </form>
</div>

<!-- Laporan Cetak Khusus Header (Akan disembunyikan di layar biasa lewat CSS, tampil saat cetak) -->
<div class="d-none d-print-block mb-4 text-center">
    <h2 class="fw-bold">LAPORAN PENJUALAN KAFE</h2>
    <p>Periode: <strong><?= date(
      "d M Y",
      strtotime($tanggal_mulai),
    ) ?></strong> s/d <strong><?= date(
  "d M Y",
  strtotime($tanggal_selesai),
) ?></strong></p>
    <hr style="border-top: 2px solid #000;">
</div>

<!-- Ringkasan Statistik -->
<div class="row g-4 mb-4">
    <!-- Total Pendapatan -->
    <div class="col-12 col-md-4">
        <div class="stat-card">
            <div class="stat-info">
                <div class="stat-value text-success">Rp <?= number_format(
                  $total_omzet,
                  0,
                  ",",
                  ".",
                ) ?></div>
                <div class="stat-label">Total Omzet Pendapatan</div>
            </div>
            <div class="stat-icon icon-green">
                <i class="fa-solid fa-sack-dollar"></i>
            </div>
        </div>
    </div>

    <!-- Jumlah Transaksi -->
    <div class="col-12 col-md-4">
        <div class="stat-card">
            <div class="stat-info">
                <div class="stat-value"><?= $total_trx ?></div>
                <div class="stat-label">Total Transaksi Selesai</div>
            </div>
            <div class="stat-icon icon-blue">
                <i class="fa-solid fa-cart-shopping"></i>
            </div>
        </div>
    </div>

    <!-- AOV -->
    <div class="col-12 col-md-4">
        <div class="stat-card">
            <div class="stat-info">
                <div class="stat-value text-info">Rp <?= number_format(
                  $aov,
                  0,
                  ",",
                  ".",
                ) ?></div>
                <div class="stat-label">Rata-rata per Transaksi</div>
            </div>
            <div class="stat-icon icon-purple">
                <i class="fa-solid fa-calculator"></i>
            </div>
        </div>
    </div>
</div>

<!-- Grafik Analisis Sederhana & Metode Pembayaran -->
<div class="row g-4 mb-4">
    <div class="col-12 col-lg-6">
        <div class="custom-card h-100">
            <div class="card-title-custom">
                <span><i class="fa-solid fa-chart-column me-2 text-warning"></i>Metode Pembayaran Terpopuler</span>
            </div>

            <div class="p-3">
                <?php // Hitung total transaksi metode
                $grand_total_metode = array_sum($metode_data); ?>
                <!-- Loop untuk menggambar custom CSS progress bar -->
                <?php foreach ($metode_data as $key => $val):

                  $percent =
                    $grand_total_metode > 0
                      ? round(($val / $grand_total_metode) * 100)
                      : 0;
                  $color_class =
                    $key === "tunai"
                      ? "bg-success"
                      : ($key === "debit"
                        ? "bg-primary"
                        : "bg-info");
                  ?>
                    <div class="mb-4">
                        <div class="d-flex justify-content-between text-black font-monospace small mb-1">
                            <span class="text-uppercase"><?= $key ?></span>
                            <span><?= $val ?> Transaksi (<?= $percent ?>%) - Rp <?= number_format(
  $metode_nominal[$key],
  0,
  ",",
  ".",
) ?></span>
                        </div>
                        <div class="progress" style="background-color: var(--bg-tertiary); height: 12px; border-radius: 6px;">
                            <div class="progress-bar <?= $color_class ?>" role="progressbar" style="width: <?= $percent ?>%; border-radius: 6px;" aria-valuenow="<?= $percent ?>" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                    </div>
                <?php
                endforeach; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="custom-card h-100 d-flex flex-column justify-content-between">
            <div>
                <div class="card-title-custom">
                    <span><i class="fa-solid fa-circle-info me-2 text-info"></i>Catatan Laporan</span>
                </div>
                <div class="text-muted small">
                    <p class="mb-2"><i class="fa-regular fa-circle-dot me-2 text-success"></i>Laporan ini didasarkan pada transaksi penjualan yang telah dibayar lunas.</p>
                    <p class="mb-2"><i class="fa-regular fa-circle-dot me-2 text-info"></i>Gunakan tombol cetak di bagian kanan atas filter untuk mencetak berkas fisik atau menyimpan laporan dalam bentuk file PDF.</p>
                    <p class="mb-0"><i class="fa-regular fa-circle-dot me-2 text-warning"></i>Data transaksi diperbarui secara real-time langsung dari modul Kasir POS.</p>
                </div>
            </div>
            <div class="mt-4 p-3 rounded text-center no-print" style="background-color: var(--bg-tertiary); border: 1px solid var(--border-color);">
                <span class="text-muted d-block small">Unduh Laporan Format Fisik / PDF</span>
                <button type="button" class="btn btn-sm btn-outline-info w-100 mt-2 py-2" onclick="window.print()" style="border-radius: 8px;">
                    <i class="fa-solid fa-file-pdf me-2"></i>Simpan Laporan (PDF/Printer)
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Tabel Riwayat Transaksi -->
<div class="custom-card">
    <div class="card-title-custom">
        <span><i class="fa-solid fa-history me-2 text-info"></i>Rincian Jurnal Riwayat Transaksi</span>
    </div>

    <div class="table-responsive">
        <table class="custom-table">
            <thead>
                <tr>
                    <th>Waktu Pembayaran</th>
                    <th>ID Transaksi</th>
                    <th>Nomor Meja</th>
                    <th>Pelanggan</th>
                    <th>Total Tagihan</th>
                    <th>Metode Pembayaran</th>
                    <th>Kasir Penerima</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($daftar_transaksi) > 0): ?>
                    <?php foreach ($daftar_transaksi as $row): ?>
                        <tr>
                            <td><?= date(
                              "d M Y H:i",
                              strtotime($row["tanggal_transaksi"]),
                            ) ?></td>
                            <td class="font-monospace fw-semibold" style="color: var(--accent-caramel);">#TRX-<?= str_pad(
                              $row["id_transaksi"],
                              5,
                              "0",
                              STR_PAD_LEFT,
                            ) ?></td>
                            <td class="fw-semibold"><?= htmlspecialchars(
                              $row["nomor_meja"] ?? "Takeaway",
                            ) ?></td>
                            <td><?= htmlspecialchars(
                              $row["nama_pelanggan"] ?? "Umum",
                            ) ?></td>
                            <td class="text-success fw-bold">Rp <?= number_format(
                              $row["total_harga"],
                              0,
                              ",",
                              ".",
                            ) ?></td>
                            <td>
                                <span class="badge-custom badge-done text-uppercase"><?= htmlspecialchars(
                                  $row["metode_pembayaran"],
                                ) ?></span>
                            </td>
                            <td class="text-muted small"><?= htmlspecialchars(
                              $row["nama_kasir"] ?? "Staff",
                            ) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">Tidak ada data transaksi yang ditemukan untuk range tanggal ini.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include "includes/footer.php"; ?>
