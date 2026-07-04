<?php
$page_title = "Manajemen Kategori";
$page_desc = "Kelola kategori menu makanan, minuman, dan camilan kafe.";

require_once 'config/koneksi.php';
require_once 'config/auth.php';
require_role('admin'); // Hanya admin yang dapat mengakses halaman ini

$message = '';
$message_type = 'success';

// Handle Add, Edit, Delete Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $nama_kategori = trim($_POST['nama_kategori'] ?? '');
        $deskripsi = trim($_POST['deskripsi'] ?? '');
        
        if ($_POST['action'] === 'tambah') {
            if (!empty($nama_kategori)) {
                try {
                    $stmt = $conn->prepare("INSERT INTO kategori (nama_kategori, deskripsi) VALUES (:nama_kategori, :deskripsi)");
                    $stmt->execute(['nama_kategori' => $nama_kategori, 'deskripsi' => $deskripsi]);
                    $message = "Kategori berhasil ditambahkan!";
                    $message_type = "success";
                } catch (PDOException $e) {
                    $message = "Gagal menambahkan kategori: " . $e->getMessage();
                    $message_type = "danger";
                }
            } else {
                $message = "Nama kategori tidak boleh kosong!";
                $message_type = "danger";
            }
        }
        
        if ($_POST['action'] === 'edit') {
            $id = intval($_POST['id'] ?? 0);
            if ($id > 0 && !empty($nama_kategori)) {
                try {
                    $stmt = $conn->prepare("UPDATE kategori SET nama_kategori = :nama_kategori, deskripsi = :deskripsi WHERE id = :id");
                    $stmt->execute(['nama_kategori' => $nama_kategori, 'deskripsi' => $deskripsi, 'id' => $id]);
                    $message = "Kategori berhasil diperbarui!";
                    $message_type = "success";
                } catch (PDOException $e) {
                    $message = "Gagal memperbarui kategori: " . $e->getMessage();
                    $message_type = "danger";
                }
            } else {
                $message = "Data tidak valid untuk memperbarui kategori.";
                $message_type = "danger";
            }
        }
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'hapus') {
    $id = intval($_GET['id'] ?? 0);
    if ($id > 0) {
        try {
            $stmt = $conn->prepare("DELETE FROM kategori WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $message = "Kategori berhasil dihapus!";
            $message_type = "success";
        } catch (PDOException $e) {
            $message = "Gagal menghapus kategori (mungkin kategori sedang digunakan oleh data menu): " . $e->getMessage();
            $message_type = "danger";
        }
    }
}

// Ambil semua kategori
$stmt_get = $conn->query("SELECT * FROM kategori ORDER BY id DESC");
$daftar_kategori = $stmt_get->fetchAll();

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
        <span><i class="fa-solid fa-tags me-2 text-info"></i>Daftar Kategori</span>
        <button type="button" class="btn btn-sm btn-primary border-0" data-bs-toggle="modal" data-bs-target="#modalTambah" style="background: var(--gradient-primary); border-radius: 8px;">
            <i class="fa-solid fa-plus me-1"></i> Tambah Kategori
        </button>
    </div>

    <!-- Filter Pencarian -->
    <div class="row mb-3">
        <div class="col-12 col-md-4">
            <div class="input-group">
                <span class="input-group-text form-control-custom text-muted"><i class="fa-solid fa-magnifying-glass"></i></span>
                <input type="text" id="tableSearch" class="form-control form-control-custom" placeholder="Cari kategori...">
            </div>
        </div>
    </div>

    <!-- Tabel Kategori -->
    <div class="table-responsive">
        <table class="custom-table">
            <thead>
                <tr>
                    <th style="width: 80px;">No</th>
                    <th>Nama Kategori</th>
                    <th>Deskripsi</th>
                    <th style="width: 150px; text-align: center;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($daftar_kategori) > 0): ?>
                    <?php $no = 1; foreach ($daftar_kategori as $kat): ?>
                        <tr>
                            <td><?= $no++ ?></td>
                            <td class="fw-semibold"><?= htmlspecialchars($kat['nama_kategori']) ?></td>
                            <td class="text-muted"><?= htmlspecialchars($kat['deskripsi'] ?? '-') ?></td>
                            <td style="text-align: center;">
                                <div class="d-flex justify-content-center gap-2">
                                    <!-- Edit Button -->
                                    <button class="btn btn-sm btn-outline-info border-0 btn-edit" 
                                            data-id="<?= $kat['id'] ?>" 
                                            data-nama="<?= htmlspecialchars($kat['nama_kategori']) ?>" 
                                            data-deskripsi="<?= htmlspecialchars($kat['deskripsi'] ?? '') ?>"
                                            data-bs-toggle="modal" 
                                            data-bs-target="#modalEdit">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </button>
                                    
                                    <!-- Delete Button -->
                                    <a href="kategori.php?action=hapus&id=<?= $kat['id'] ?>" 
                                       class="btn btn-sm btn-outline-danger border-0" 
                                       onclick="return confirm('Apakah Anda yakin ingin menghapus kategori ini?');">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted py-4">Belum ada kategori yang ditambahkan.</td>
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
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-plus text-primary me-2"></i>Tambah Kategori</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="kategori.php" method="POST">
                <input type="hidden" name="action" value="tambah">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="nama_kategori" class="form-label small text-muted">Nama Kategori</label>
                        <input type="text" name="nama_kategori" id="nama_kategori" class="form-control form-control-custom" placeholder="Contoh: Makanan Utama" required>
                    </div>
                    <div class="mb-3">
                        <label for="deskripsi" class="form-label small text-muted">Deskripsi</label>
                        <textarea name="deskripsi" id="deskripsi" rows="3" class="form-control form-control-custom" placeholder="Tulis deskripsi kategori di sini..."></textarea>
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
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen-to-square text-info me-2"></i>Ubah Kategori</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="kategori.php" method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_nama_kategori" class="form-label small text-muted">Nama Kategori</label>
                        <input type="text" name="nama_kategori" id="edit_nama_kategori" class="form-control form-control-custom" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_deskripsi" class="form-label small text-muted">Deskripsi</label>
                        <textarea name="deskripsi" id="edit_deskripsi" rows="3" class="form-control form-control-custom"></textarea>
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
// Script Javascript Tambahan untuk Binding Edit Data ke Modal
$extra_js = "
<script>
document.addEventListener('DOMContentLoaded', function() {
    const editButtons = document.querySelectorAll('.btn-edit');
    editButtons.forEach(button => {
        button.addEventListener('click', function() {
            const id = this.getAttribute('data-id');
            const nama = this.getAttribute('data-nama');
            const deskripsi = this.getAttribute('data-deskripsi');
            
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_nama_kategori').value = nama;
            document.getElementById('edit_deskripsi').value = deskripsi;
        });
    });
});
</script>
";

include 'includes/footer.php';
?>
