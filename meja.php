<?php
$page_title = "Manajemen Meja";
$page_desc = "Kelola nomor meja, kapasitas, dan status ketersediaan meja kafe.";

require_once 'config/koneksi.php';
require_once 'config/auth.php';
require_auth(); // Admin dan kasir boleh akses

$message = '';
$message_type = 'success';

// Handle Add, Edit, Delete Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $nomor_meja = trim($_POST['nomor_meja'] ?? '');
        $kapasitas = intval($_POST['kapasitas'] ?? 2);
        
        if ($_POST['action'] === 'tambah') {
            if (!empty($nomor_meja) && $kapasitas > 0) {
                try {
                    $stmt = $conn->prepare("INSERT INTO meja (nomor_meja, kapasitas, status) VALUES (:nomor_meja, :kapasitas, 'kosong')");
                    $stmt->execute(['nomor_meja' => $nomor_meja, 'kapasitas' => $kapasitas]);
                    $message = "Meja baru berhasil didaftarkan!";
                    $message_type = "success";
                } catch (PDOException $e) {
                    $message = "Gagal mendaftarkan meja (mungkin nomor meja sudah ada): " . $e->getMessage();
                    $message_type = "danger";
                }
            } else {
                $message = "Mohon lengkapi semua field wajib.";
                $message_type = "danger";
            }
        }
        
        if ($_POST['action'] === 'edit') {
            $id = intval($_POST['id'] ?? 0);
            $status = $_POST['status'] ?? 'kosong';
            
            if ($id > 0 && !empty($nomor_meja) && $kapasitas > 0) {
                try {
                    $stmt = $conn->prepare("UPDATE meja SET nomor_meja = :nomor_meja, kapasitas = :kapasitas, status = :status WHERE id = :id");
                    $stmt->execute([
                        'nomor_meja' => $nomor_meja,
                        'kapasitas' => $kapasitas,
                        'status' => $status,
                        'id' => $id
                    ]);
                    $message = "Data meja berhasil diperbarui!";
                    $message_type = "success";
                } catch (PDOException $e) {
                    $message = "Gagal memperbarui meja: " . $e->getMessage();
                    $message_type = "danger";
                }
            } else {
                $message = "Data tidak valid untuk memperbarui meja.";
                $message_type = "danger";
            }
        }
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'hapus') {
    $id = intval($_GET['id'] ?? 0);
    if ($id > 0) {
        try {
            $stmt = $conn->prepare("DELETE FROM meja WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $message = "Meja berhasil dihapus!";
            $message_type = "success";
        } catch (PDOException $e) {
            $message = "Gagal menghapus meja: " . $e->getMessage();
            $message_type = "danger";
        }
    }
}

// Ambil semua meja
$stmt_get = $conn->query("SELECT * FROM meja ORDER BY nomor_meja ASC");
$daftar_meja = $stmt_get->fetchAll();

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

<div class="row g-4 mb-4">
    <!-- Denah visual meja -->
    <div class="col-12">
        <div class="custom-card">
            <div class="card-title-custom">
                <span><i class="fa-solid fa-table-cells-large me-2 text-info"></i>Denah Visual Meja</span>
                <button type="button" class="btn btn-sm btn-primary border-0" data-bs-toggle="modal" data-bs-target="#modalTambah" style="background: var(--gradient-primary); border-radius: 8px;">
                    <i class="fa-solid fa-plus me-1"></i> Tambah Meja
                </button>
            </div>
            
            <div class="table-map-container">
                <?php foreach ($daftar_meja as $meja): ?>
                    <div class="table-card <?= $meja['status'] == 'kosong' ? 'empty' : 'occupied' ?>"
                         data-id="<?= $meja['id'] ?>"
                         data-nomor="<?= htmlspecialchars($meja['nomor_meja']) ?>"
                         data-kapasitas="<?= $meja['kapasitas'] ?>"
                         data-status="<?= $meja['status'] ?>"
                         data-bs-toggle="modal" 
                         data-bs-target="#modalEdit">
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
</div>

<div class="custom-card">
    <div class="card-title-custom">
        <span><i class="fa-solid fa-list me-2 text-info"></i>Data Tabel Meja</span>
    </div>

    <!-- Tabel Data Meja -->
    <div class="table-responsive">
        <table class="custom-table">
            <thead>
                <tr>
                    <th>No</th>
                    <th>Nomor Meja</th>
                    <th>Kapasitas</th>
                    <th>Status</th>
                    <th style="width: 150px; text-align: center;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($daftar_meja) > 0): ?>
                    <?php $no = 1; foreach ($daftar_meja as $meja): ?>
                        <tr>
                            <td><?= $no++ ?></td>
                            <td class="fw-semibold"><?= htmlspecialchars($meja['nomor_meja']) ?></td>
                            <td><?= htmlspecialchars($meja['kapasitas']) ?> Orang</td>
                            <td>
                                <span class="badge-custom <?= $meja['status'] == 'kosong' ? 'badge-table-empty' : 'badge-table-occupied' ?>">
                                    <i class="fa-solid <?= $meja['status'] == 'kosong' ? 'fa-circle-check' : 'fa-circle-dot' ?>"></i>
                                    <?= strtoupper($meja['status']) ?>
                                </span>
                            </td>
                            <td style="text-align: center;">
                                <div class="d-flex justify-content-center gap-2">
                                    <!-- Edit Button -->
                                    <button class="btn btn-sm btn-outline-info border-0 btn-edit" 
                                            data-id="<?= $meja['id'] ?>" 
                                            data-nomor="<?= htmlspecialchars($meja['nomor_meja']) ?>" 
                                            data-kapasitas="<?= $meja['kapasitas'] ?>"
                                            data-status="<?= $meja['status'] ?>"
                                            data-bs-toggle="modal" 
                                            data-bs-target="#modalEdit">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </button>
                                    
                                    <!-- Delete Button -->
                                    <a href="meja.php?action=hapus&id=<?= $meja['id'] ?>" 
                                       class="btn btn-sm btn-outline-danger border-0" 
                                       onclick="return confirm('Apakah Anda yakin ingin menghapus meja ini?');">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">Belum ada data meja yang ditambahkan.</td>
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
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-plus text-primary me-2"></i>Tambah Meja Baru</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="meja.php" method="POST">
                <input type="hidden" name="action" value="tambah">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="nomor_meja" class="form-label small text-muted">Nomor Meja</label>
                        <input type="text" name="nomor_meja" id="nomor_meja" class="form-control form-control-custom" placeholder="Contoh: Meja 07" required>
                    </div>
                    <div class="mb-3">
                        <label for="kapasitas" class="form-label small text-muted">Kapasitas (Orang)</label>
                        <input type="number" name="kapasitas" id="kapasitas" class="form-control form-control-custom" placeholder="Contoh: 4" min="1" required>
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
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen-to-square text-info me-2"></i>Ubah Data Meja</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="meja.php" method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_nomor_meja" class="form-label small text-muted">Nomor Meja</label>
                        <input type="text" name="nomor_meja" id="edit_nomor_meja" class="form-control form-control-custom" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_kapasitas" class="form-label small text-muted">Kapasitas (Orang)</label>
                        <input type="number" name="kapasitas" id="edit_kapasitas" class="form-control form-control-custom" min="1" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_status" class="form-label small text-muted">Status Ketersediaan</label>
                        <select name="status" id="edit_status" class="form-select form-select-custom">
                            <option value="kosong">Kosong (Tersedia)</option>
                            <option value="terisi">Terisi (Sedang Digunakan)</option>
                        </select>
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

<?php
// Script Javascript untuk binding modal edit dan klik card denah
$extra_js = "
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Handler untuk tombol edit di tabel & klik card denah meja
    const triggerElements = document.querySelectorAll('.btn-edit, .table-card');
    triggerElements.forEach(el => {
        el.addEventListener('click', function(e) {
            // Jika klik tombol hapus di dalam card (jika ada), abaikan
            if (e.target.closest('a')) return;
            
            const id = this.getAttribute('data-id');
            const nomor = this.getAttribute('data-nomor');
            const kapasitas = this.getAttribute('data-kapasitas');
            const status = this.getAttribute('data-status');
            
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_nomor_meja').value = nomor;
            document.getElementById('edit_kapasitas').value = kapasitas;
            document.getElementById('edit_status').value = status;
        });
    });
});
</script>
";

include 'includes/footer.php';
?>
