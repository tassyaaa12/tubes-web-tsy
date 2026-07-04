<?php
$page_title = "Manajemen Pelanggan";
$page_desc = "Kelola database pelanggan kafe untuk keanggotaan dan pencatatan transaksi.";

require_once 'config/koneksi.php';
require_once 'config/auth.php';
require_auth(); // Admin dan kasir boleh akses

$message = '';
$message_type = 'success';

// Handle Add, Edit, Delete Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $nama_pelanggan = trim($_POST['nama_pelanggan'] ?? '');
        $telepon = trim($_POST['telepon'] ?? '');
        $email = trim($_POST['email'] ?? '');
        
        if ($_POST['action'] === 'tambah') {
            if (!empty($nama_pelanggan)) {
                try {
                    // Validasi tipe integer (angka saja)
                    if (!empty($telepon) && !ctype_digit($telepon)) {
                        throw new Exception("Nomor telepon harus berupa angka!");
                    }

                    // Validasi keunikan nomor HP untuk member resmi
                    if (!empty($telepon)) {
                        $stmt_check = $conn->prepare("SELECT COUNT(*) FROM pelanggan WHERE telepon = :telp AND is_member = 1 LIMIT 1");
                        $stmt_check->execute(['telp' => $telepon]);
                        if ($stmt_check->fetchColumn() > 0) {
                            throw new Exception("Nomor telepon tersebut sudah terdaftar sebagai member resmi!");
                        }
                    }

                    $stmt = $conn->prepare("INSERT INTO pelanggan (nama_pelanggan, telepon, email, is_member) VALUES (:nama_pelanggan, :telepon, :email, 1)");
                    $stmt->execute([
                        'nama_pelanggan' => $nama_pelanggan,
                        'telepon' => $telepon ?: '-',
                        'email' => $email ?: '-'
                    ]);
                    $message = "Pelanggan baru berhasil didaftarkan!";
                    $message_type = "success";
                } catch (Exception $e) {
                    $message = "Gagal mendaftarkan pelanggan: " . $e->getMessage();
                    $message_type = "danger";
                }
            } else {
                $message = "Nama pelanggan wajib diisi!";
                $message_type = "danger";
            }
        }
        
        if ($_POST['action'] === 'edit') {
            $id = intval($_POST['id'] ?? 0);
            $poin_saldo = intval($_POST['poin_saldo'] ?? 0);
            if ($id > 0 && !empty($nama_pelanggan)) {
                try {
                    // Validasi tipe integer (angka saja)
                    if (!empty($telepon) && !ctype_digit($telepon)) {
                        throw new Exception("Nomor telepon harus berupa angka!");
                    }

                    // Validasi keunikan nomor HP untuk member resmi (kecuali ID diri sendiri)
                    if (!empty($telepon)) {
                        $stmt_check = $conn->prepare("SELECT COUNT(*) FROM pelanggan WHERE telepon = :telp AND is_member = 1 AND id != :id LIMIT 1");
                        $stmt_check->execute(['telp' => $telepon, 'id' => $id]);
                        if ($stmt_check->fetchColumn() > 0) {
                            throw new Exception("Nomor telepon tersebut sudah terdaftar sebagai member resmi!");
                        }
                    }

                    $conn->beginTransaction();
                    
                    // Ambil poin lama untuk logging deviasi
                    $stmt_cur = $conn->prepare("SELECT poin_saldo FROM pelanggan WHERE id = :id");
                    $stmt_cur->execute(['id' => $id]);
                    $old_poin = intval($stmt_cur->fetchColumn());

                    $stmt = $conn->prepare("UPDATE pelanggan SET nama_pelanggan = :nama_pelanggan, telepon = :telepon, email = :email, poin_saldo = :poin WHERE id = :id");
                    $stmt->execute([
                        'nama_pelanggan' => $nama_pelanggan,
                        'telepon' => $telepon ?: '-',
                        'email' => $email ?: '-',
                        'poin' => $poin_saldo,
                        'id' => $id
                    ]);

                    if ($old_poin !== $poin_saldo) {
                        $diff = $poin_saldo - $old_poin;
                        $tipe = $diff > 0 ? 'kredit' : 'debet';
                        $jumlah = abs($diff);
                        $stmt_log = $conn->prepare("INSERT INTO poin_transaksi (id_pelanggan, tipe, jumlah_poin, keterangan) VALUES (:id, :tipe, :jumlah, 'Adjustment manual oleh admin/staff')");
                        $stmt_log->execute([
                            'id' => $id,
                            'tipe' => $tipe,
                            'jumlah' => $jumlah
                        ]);
                    }

                    $conn->commit();
                    $message = "Data pelanggan berhasil diperbarui!";
                    $message_type = "success";
                } catch (Exception $e) {
                    if ($conn->inTransaction()) {
                        $conn->rollBack();
                    }
                    $message = "Gagal memperbarui data pelanggan: " . $e->getMessage();
                    $message_type = "danger";
                }
            } else {
                $message = "Data tidak valid untuk memperbarui pelanggan.";
                $message_type = "danger";
            }
        }
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'hapus') {
    $id = intval($_GET['id'] ?? 0);
    if ($id > 0) {
        try {
            $stmt = $conn->prepare("DELETE FROM pelanggan WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $message = "Data pelanggan berhasil dihapus!";
            $message_type = "success";
        } catch (PDOException $e) {
            $message = "Gagal menghapus data pelanggan: " . $e->getMessage();
            $message_type = "danger";
        }
    }
}

// Ambil semua pelanggan (hanya member terdaftar)
$stmt_get = $conn->query("SELECT * FROM pelanggan WHERE is_member = 1 ORDER BY id DESC");
$daftar_pelanggan = $stmt_get->fetchAll();

// Detail Member modal load
$show_detail_modal = false;
$detail_member = null;
$detail_poin_log = [];
$detail_order_log = [];

if (isset($_GET['detail_id'])) {
    $detail_id = intval($_GET['detail_id']);
    if ($detail_id > 0) {
        $stmt_m = $conn->prepare("SELECT * FROM pelanggan WHERE id = :id LIMIT 1");
        $stmt_m->execute(['id' => $detail_id]);
        $detail_member = $stmt_m->fetch();
        
        if ($detail_member) {
            $show_detail_modal = true;
            
            // Poin log
            $stmt_pl = $conn->prepare("SELECT * FROM poin_transaksi WHERE id_pelanggan = :id ORDER BY created_at DESC LIMIT 10");
            $stmt_pl->execute(['id' => $detail_id]);
            $detail_poin_log = $stmt_pl->fetchAll();
            
            // Order history
            $stmt_oh = $conn->prepare("
                SELECT p.*, m.nomor_meja 
                FROM pesanan p 
                LEFT JOIN meja m ON p.id_meja = m.id 
                WHERE p.id_pelanggan = :id AND p.status_pembayaran = 'sudah_bayar'
                ORDER BY p.tanggal_pesanan DESC LIMIT 10
            ");
            $stmt_oh->execute(['id' => $detail_id]);
            $detail_order_log = $stmt_oh->fetchAll();
        }
    }
}

include 'includes/header.php';
?>

<!-- Menampilkan Pesan Toast -->
<?php if (!empty($message)): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            showToast("<?= addslashes($message) ?>", "<?= $message_type ?>");
        });
    </script>
<?php endif; ?>

<div class="custom-card">
    <div class="card-title-custom">
        <span><i class="fa-solid fa-users me-2 text-info"></i>Daftar Pelanggan</span>
        <button type="button" class="btn btn-sm btn-primary border-0" data-bs-toggle="modal" data-bs-target="#modalTambah" style="background: var(--gradient-primary); border-radius: 8px;">
            <i class="fa-solid fa-user-plus me-1"></i> Daftar Pelanggan
        </button>
    </div>

    <!-- Filter Pencarian -->
    <div class="row mb-3">
        <div class="col-12 col-md-4">
            <div class="input-group">
                <span class="input-group-text form-control-custom text-muted"><i class="fa-solid fa-magnifying-glass"></i></span>
                <input type="text" id="tableSearch" class="form-control form-control-custom" placeholder="Cari pelanggan...">
            </div>
        </div>
    </div>

    <!-- Tabel Data Pelanggan -->
    <div class="table-responsive">
        <table class="custom-table">
            <thead>
                <tr>
                    <th style="width: 80px;">No</th>
                    <th>Nama Pelanggan</th>
                    <th>No. Telepon</th>
                    <th>Email</th>
                    <th>Poin Saldo</th>
                    <th>Tanggal Daftar</th>
                    <th style="width: 180px; text-align: center;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($daftar_pelanggan) > 0): ?>
                    <?php $no = 1; foreach ($daftar_pelanggan as $pl): ?>
                        <tr>
                            <td><?= $no++ ?></td>
                            <td class="fw-semibold"><?= htmlspecialchars($pl['nama_pelanggan']) ?></td>
                            <td><?= htmlspecialchars($pl['telepon'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($pl['email'] ?? '-') ?></td>
                            <td>
                                <span class="badge bg-warning text-dark px-2 py-1 fw-bold">
                                    <i class="fa-solid fa-star me-1"></i><?= number_format($pl['poin_saldo']) ?>
                                </span>
                            </td>
                            <td class="text-muted small"><?= date('d M Y H:i', strtotime($pl['created_at'])) ?></td>
                            <td style="text-align: center;">
                                <div class="d-flex justify-content-center gap-1">
                                    <!-- Detail Button -->
                                    <a href="pelanggan.php?detail_id=<?= $pl['id'] ?>" 
                                       class="btn btn-sm btn-outline-success border-0" 
                                       title="Lihat Detail & Histori Member">
                                        <i class="fa-solid fa-eye"></i>
                                    </a>

                                    <!-- Edit Button -->
                                    <button class="btn btn-sm btn-outline-info border-0 btn-edit" 
                                            data-id="<?= $pl['id'] ?>" 
                                            data-nama="<?= htmlspecialchars($pl['nama_pelanggan']) ?>" 
                                            data-telepon="<?= htmlspecialchars($pl['telepon'] ?? '') ?>"
                                            data-email="<?= htmlspecialchars($pl['email'] ?? '') ?>"
                                            data-poin="<?= $pl['poin_saldo'] ?>"
                                            data-bs-toggle="modal" 
                                            data-bs-target="#modalEdit"
                                            title="Ubah Data Member">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </button>
                                    
                                    <!-- Delete Button -->
                                    <a href="pelanggan.php?action=hapus&id=<?= $pl['id'] ?>" 
                                       class="btn btn-sm btn-outline-danger border-0" 
                                       onclick="return confirm('Apakah Anda yakin ingin menghapus pelanggan ini?');"
                                       title="Hapus Member">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">Belum ada pelanggan terdaftar.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Tambah -->
<div class="modal fade" id="modalTambah" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content modal-content-custom">
            <div class="modal-header modal-header-custom">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-user-plus text-primary me-2"></i>Daftar Pelanggan Baru</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="pelanggan.php" method="POST">
                <input type="hidden" name="action" value="tambah">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="nama_pelanggan" class="form-label small text-muted">Nama Pelanggan *</label>
                        <input type="text" name="nama_pelanggan" id="nama_pelanggan" class="form-control form-control-custom" placeholder="Nama Lengkap" required>
                    </div>
                    <div class="mb-3">
                        <label for="telepon" class="form-label small text-muted">Nomor Telepon</label>
                        <input type="text" name="telepon" id="telepon" class="form-control form-control-custom" placeholder="Contoh: 0812345678" pattern="[0-9]+" title="Nomor telepon hanya boleh berisi angka (0-9).">
                    </div>
                    <div class="mb-3">
                        <label for="email" class="form-label small text-muted">Email</label>
                        <input type="email" name="email" id="email" class="form-control form-control-custom" placeholder="Contoh: nama@domain.com">
                    </div>
                </div>
                <div class="modal-footer modal-footer-custom">
                    <button type="button" class="btn btn-secondary border-0" data-bs-dismiss="modal" style="border-radius: 8px;">Batal</button>
                    <button type="submit" class="btn btn-primary border-0" style="background: var(--gradient-primary); border-radius: 8px;">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Edit -->
<div class="modal fade" id="modalEdit" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content modal-content-custom">
            <div class="modal-header modal-header-custom">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-user-pen text-info me-2"></i>Ubah Data Pelanggan / Member</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="pelanggan.php" method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_nama_pelanggan" class="form-label small text-muted">Nama Pelanggan *</label>
                        <input type="text" name="nama_pelanggan" id="edit_nama_pelanggan" class="form-control form-control-custom" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_telepon" class="form-label small text-muted">Nomor Telepon</label>
                        <input type="text" name="telepon" id="edit_telepon" class="form-control form-control-custom" pattern="[0-9]+" title="Nomor telepon hanya boleh berisi angka (0-9).">
                    </div>
                    <div class="mb-3">
                        <label for="edit_email" class="form-label small text-muted">Email</label>
                        <input type="email" name="email" id="edit_email" class="form-control form-control-custom">
                    </div>
                    <div class="mb-3">
                        <label for="edit_poin_saldo" class="form-label small text-muted">Saldo Poin Member</label>
                        <input type="number" name="poin_saldo" id="edit_poin_saldo" class="form-control form-control-custom" min="0" required>
                        <div class="form-text text-muted" style="font-size: 11px;">Perubahan saldo akan dicatat di log audit poin.</div>
                    </div>
                </div>
                <div class="modal-footer modal-footer-custom">
                    <button type="button" class="btn btn-secondary border-0" data-bs-dismiss="modal" style="border-radius: 8px;">Batal</button>
                    <button type="submit" class="btn btn-info border-0 text-white" style="background: var(--gradient-primary); border-radius: 8px;">Perbarui</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Detail Member & Log -->
<?php if ($show_detail_modal && $detail_member): ?>
<div class="modal fade" id="modalDetail" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content modal-content-custom">
            <div class="modal-header modal-header-custom">
                <h5 class="modal-title fw-bold text-success"><i class="fa-solid fa-id-card me-2"></i>Detail & Log Histori Member</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" onclick="location.href='pelanggan.php'" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Info Member -->
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="p-3 rounded" style="background: var(--bg-tertiary); border: 1px solid var(--border-color);">
                            <div class="small text-muted mb-1">NAMA LENGKAP:</div>
                            <div class="fw-bold text-main fs-5"><?= htmlspecialchars($detail_member['nama_pelanggan']) ?></div>
                            <div class="small text-muted mt-2">KONTAK:</div>
                            <div class="text-main small"><i class="fa-solid fa-phone me-1 text-muted"></i> <?= htmlspecialchars($detail_member['telepon']) ?></div>
                            <div class="text-main small mt-1"><i class="fa-solid fa-envelope me-1 text-muted"></i> <?= htmlspecialchars($detail_member['email']) ?></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-3 rounded text-center h-100 d-flex flex-column justify-content-center align-items-center" 
                             style="background: rgba(245,158,11,0.08); border: 1px solid rgba(245,158,11,0.3);">
                            <span class="small text-muted text-uppercase mb-1">Saldo Poin Saat Ini</span>
                            <div class="display-5 fw-bold" style="color: #f59e0b;"><i class="fa-solid fa-star me-2"></i><?= number_format($detail_member['poin_saldo']) ?></div>
                            <span class="small text-muted mt-1">1 Poin = Potongan Rp 100</span>
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <!-- Log Poin -->
                    <div class="col-md-6">
                        <div class="card bg-transparent border-0">
                            <div class="fw-bold text-main mb-2"><i class="fa-solid fa-clock-rotate-left text-warning me-2"></i>Histori Transaksi Poin</div>
                            <div class="table-responsive" style="max-height: 250px; overflow-y: auto;">
                                <table class="table table-sm text-main table-borderless align-middle" style="font-size:12px;">
                                    <thead>
                                        <tr class="text-muted border-bottom" style="font-size:11px;">
                                            <th>Waktu</th>
                                            <th>Tipe</th>
                                            <th>Jumlah</th>
                                            <th>Keterangan</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (count($detail_poin_log) > 0): ?>
                                            <?php foreach ($detail_poin_log as $pl): ?>
                                                <tr class="border-bottom border-light">
                                                    <td class="text-muted small"><?= date('d/m H:i', strtotime($pl['created_at'])) ?></td>
                                                    <td>
                                                        <span class="badge bg-<?= $pl['tipe'] == 'kredit' ? 'success' : 'danger' ?>-subtle text-<?= $pl['tipe'] == 'kredit' ? 'success' : 'danger' ?> font-monospace px-1">
                                                            <?= strtoupper($pl['tipe']) ?>
                                                        </span>
                                                    </td>
                                                    <td class="fw-bold text-<?= $pl['tipe'] == 'kredit' ? 'success' : 'danger' ?>">
                                                        <?= $pl['tipe'] == 'kredit' ? '+' : '-' ?><?= $pl['jumlah_poin'] ?>
                                                    </td>
                                                    <td class="text-muted text-truncate" style="max-width: 100px;"><?= htmlspecialchars($pl['keterangan'] ?? '-') ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="4" class="text-center text-muted py-3">Tidak ada log poin.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Riwayat Order -->
                    <div class="col-md-6">
                        <div class="card bg-transparent border-0">
                            <div class="fw-bold text-main mb-2"><i class="fa-solid fa-receipt text-success me-2"></i>Riwayat Pembelian Lunas</div>
                            <div class="table-responsive" style="max-height: 250px; overflow-y: auto;">
                                <table class="table table-sm text-main table-borderless align-middle" style="font-size:12px;">
                                    <thead>
                                        <tr class="text-muted border-bottom" style="font-size:11px;">
                                            <th>Waktu</th>
                                            <th>Meja</th>
                                            <th>Total Tagihan</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (count($detail_order_log) > 0): ?>
                                            <?php foreach ($detail_order_log as $ol): ?>
                                                <tr class="border-bottom border-light">
                                                    <td class="text-muted small"><?= date('d M H:i', strtotime($ol['tanggal_pesanan'])) ?></td>
                                                    <td class="fw-semibold"><?= htmlspecialchars($ol['nomor_meja'] ?? 'Takeaway') ?></td>
                                                    <td class="fw-bold">Rp <?= number_format($ol['total_harga'], 0, ',', '.') ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="3" class="text-center text-muted py-3">Belum ada transaksi.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer modal-footer-custom border-0 pt-0">
                <button type="button" class="btn btn-secondary border-0" onclick="location.href='pelanggan.php'" style="border-radius: 8px;">Tutup</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
$extra_js = "
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Edit member modal handler
    const editButtons = document.querySelectorAll('.btn-edit');
    editButtons.forEach(button => {
        button.addEventListener('click', function() {
            const id = this.getAttribute('data-id');
            const nama = this.getAttribute('data-nama');
            const telepon = this.getAttribute('data-telepon');
            const email = this.getAttribute('data-email');
            const poin = this.getAttribute('data-poin') || 0;
            
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_nama_pelanggan').value = nama;
            document.getElementById('edit_telepon').value = telepon;
            document.getElementById('edit_email').value = email;
            document.getElementById('edit_poin_saldo').value = poin;
        });
    });

    // Auto open detail modal if needed
    " . ($show_detail_modal ? "
    const detailModal = new bootstrap.Modal(document.getElementById('modalDetail'), {
        backdrop: 'static',
        keyboard: false
    });
    detailModal.show();
    " : "") . "
});
</script>
";

include 'includes/footer.php';
?>
