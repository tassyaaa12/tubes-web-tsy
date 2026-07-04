<?php
$page_title = "Manajemen Menu";
$page_desc = "Kelola daftar hidangan makanan, minuman, dan camilan kafe Anda.";

require_once 'config/koneksi.php';
require_once 'config/auth.php';
require_role('admin'); // Hanya admin yang dapat mengakses halaman ini

$message = '';
$message_type = 'success';

// Buat direktori upload jika belum ada
$upload_dir = 'assets/images/uploads/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Handle Add, Edit, Delete Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $nama_menu = trim($_POST['nama_menu'] ?? '');
        $id_kategori = intval($_POST['id_kategori'] ?? 0);
        $harga = intval($_POST['harga'] ?? 0);
        $status = $_POST['status'] ?? 'tersedia';
        $deskripsi = trim($_POST['deskripsi'] ?? '');
        
        // File Upload Logic
        $gambar_name = '';
        if (isset($_FILES['gambar']) && $_FILES['gambar']['error'] === UPLOAD_ERR_OK) {
            $file_tmp = $_FILES['gambar']['tmp_name'];
            $file_name = $_FILES['gambar']['name'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            
            if (in_array($file_ext, $allowed_exts)) {
                $gambar_name = time() . '_' . uniqid() . '.' . $file_ext;
                move_uploaded_file($file_tmp, $upload_dir . $gambar_name);
            } else {
                $message = "Format gambar tidak didukung! Gunakan JPG, JPEG, PNG, GIF, atau WEBP.";
                $message_type = "danger";
            }
        }
        
        if ($_POST['action'] === 'tambah' && $message_type !== 'danger') {
            if (!empty($nama_menu) && $id_kategori > 0 && $harga > 0) {
                try {
                    $stmt = $conn->prepare("INSERT INTO menu (nama_menu, id_kategori, harga, status, gambar, deskripsi) VALUES (:nama_menu, :id_kategori, :harga, :status, :gambar, :deskripsi)");
                    $stmt->execute([
                        'nama_menu' => $nama_menu,
                        'id_kategori' => $id_kategori,
                        'harga' => $harga,
                        'status' => $status,
                        'gambar' => $gambar_name ?: 'default_food.png', // Default image fallback
                        'deskripsi' => $deskripsi
                    ]);
                    $message = "Menu baru berhasil ditambahkan!";
                    $message_type = "success";
                } catch (PDOException $e) {
                    $message = "Gagal menambahkan menu: " . $e->getMessage();
                    $message_type = "danger";
                }
            } else {
                $message = "Mohon lengkapi semua data wajib (Nama, Kategori, Harga).";
                $message_type = "danger";
            }
        }
        
        if ($_POST['action'] === 'edit' && $message_type !== 'danger') {
            $id = intval($_POST['id'] ?? 0);
            $old_gambar = $_POST['old_gambar'] ?? '';
            
            // Gunakan gambar lama jika tidak ada gambar baru yang diunggah
            if (empty($gambar_name)) {
                $gambar_name = $old_gambar;
            } else {
                // Hapus gambar lama jika ada dan bukan file default
                if (!empty($old_gambar) && $old_gambar !== 'default_food.png' && file_exists($upload_dir . $old_gambar)) {
                    @unlink($upload_dir . $old_gambar);
                }
            }
            
            if ($id > 0 && !empty($nama_menu) && $id_kategori > 0 && $harga > 0) {
                try {
                    $stmt = $conn->prepare("UPDATE menu SET nama_menu = :nama_menu, id_kategori = :id_kategori, harga = :harga, status = :status, gambar = :gambar, deskripsi = :deskripsi WHERE id = :id");
                    $stmt->execute([
                        'nama_menu' => $nama_menu,
                        'id_kategori' => $id_kategori,
                        'harga' => $harga,
                        'status' => $status,
                        'gambar' => $gambar_name,
                        'deskripsi' => $deskripsi,
                        'id' => $id
                    ]);
                    $message = "Menu berhasil diperbarui!";
                    $message_type = "success";
                } catch (PDOException $e) {
                    $message = "Gagal memperbarui menu: " . $e->getMessage();
                    $message_type = "danger";
                }
            } else {
                $message = "Data tidak valid untuk memperbarui menu.";
                $message_type = "danger";
            }
        }
    }
}

// Handle Delete Action
if (isset($_GET['action']) && $_GET['action'] === 'hapus') {
    $id = intval($_GET['id'] ?? 0);
    if ($id > 0) {
        try {
            // Ambil info gambar sebelum hapus records
            $stmt_img = $conn->prepare("SELECT gambar FROM menu WHERE id = :id");
            $stmt_img->execute(['id' => $id]);
            $menu_item = $stmt_img->fetch();
            
            $stmt = $conn->prepare("DELETE FROM menu WHERE id = :id");
            $stmt->execute(['id' => $id]);
            
            // Hapus file gambar secara fisik jika ada
            if ($menu_item && !empty($menu_item['gambar']) && $menu_item['gambar'] !== 'default_food.png') {
                @unlink($upload_dir . $menu_item['gambar']);
            }
            
            $message = "Menu berhasil dihapus!";
            $message_type = "success";
        } catch (PDOException $e) {
            $message = "Gagal menghapus menu: " . $e->getMessage();
            $message_type = "danger";
        }
    }
}

// Ambil semua kategori untuk dropdown select
$kategori_list = $conn->query("SELECT * FROM kategori ORDER BY nama_kategori ASC")->fetchAll();

// Ambil semua menu dengan join ke kategori
$stmt_get = $conn->query("
    SELECT m.*, k.nama_kategori 
    FROM menu m 
    LEFT JOIN kategori k ON m.id_kategori = k.id 
    ORDER BY m.id DESC
");
$daftar_menu = $stmt_get->fetchAll();

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
        <span><i class="fa-solid fa-bowl-food me-2 text-info"></i>Daftar Menu Kafe</span>
        <button type="button" class="btn btn-sm btn-primary border-0" data-bs-toggle="modal" data-bs-target="#modalTambah" style="background: var(--gradient-primary); border-radius: 8px;">
            <i class="fa-solid fa-plus me-1"></i> Tambah Menu
        </button>
    </div>

    <!-- Filter Pencarian -->
    <div class="row mb-3">
        <div class="col-12 col-md-4">
            <div class="input-group">
                <span class="input-group-text form-control-custom text-muted"><i class="fa-solid fa-magnifying-glass"></i></span>
                <input type="text" id="tableSearch" class="form-control form-control-custom" placeholder="Cari menu...">
            </div>
        </div>
    </div>

    <!-- Tabel Menu -->
    <div class="table-responsive">
        <table class="custom-table">
            <thead>
                <tr>
                    <th style="width: 60px;">No</th>
                    <th style="width: 80px;">Gambar</th>
                    <th>Nama Menu</th>
                    <th>Kategori</th>
                    <th>Harga</th>
                    <th>Status</th>
                    <th>Deskripsi</th>
                    <th style="width: 150px; text-align: center;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($daftar_menu) > 0): ?>
                    <?php $no = 1; foreach ($daftar_menu as $menu): ?>
                        <tr>
                            <td><?= $no++ ?></td>
                            <td>
                                <?php 
                                    $img_src = 'assets/images/uploads/' . $menu['gambar'];
                                    if (empty($menu['gambar']) || !file_exists($img_src)) {
                                        $img_src = 'assets/images/default_food.png';
                                    }
                                ?>
                                <img src="<?= $img_src ?>" alt="<?= htmlspecialchars($menu['nama_menu']) ?>" style="width: 50px; height: 50px; object-fit: cover; border-radius: 8px; border: 1px solid var(--border-color);">
                            </td>
                            <td class="fw-semibold"><?= htmlspecialchars($menu['nama_menu']) ?></td>
                            <td><span class="badge px-2 py-1" style="background-color: var(--bg-tertiary); color: var(--text-muted); border: 1px solid var(--border-color);"><?= htmlspecialchars($menu['nama_kategori'] ?? 'Tanpa Kategori') ?></span></td>
                            <td class="fw-bold" style="color: var(--accent-coffee);">Rp <?= number_format($menu['harga'], 0, ',', '.') ?></td>
                            <td>
                                <span class="badge-custom <?= $menu['status'] == 'tersedia' ? 'badge-done' : 'badge-cancel' ?>">
                                    <i class="fa-solid <?= $menu['status'] == 'tersedia' ? 'fa-check' : 'fa-xmark' ?>"></i>
                                    <?= htmlspecialchars($menu['status']) ?>
                                </span>
                            </td>
                            <td class="text-muted small" style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                <?= htmlspecialchars($menu['deskripsi'] ?? '-') ?>
                            </td>
                            <td style="text-align: center;">
                                <div class="d-flex justify-content-center gap-2">
                                    <!-- Edit Button -->
                                    <button class="btn btn-sm btn-outline-info border-0 btn-edit" 
                                            data-id="<?= $menu['id'] ?>" 
                                            data-nama="<?= htmlspecialchars($menu['nama_menu']) ?>" 
                                            data-kategori="<?= $menu['id_kategori'] ?>"
                                            data-harga="<?= $menu['harga'] ?>"
                                            data-status="<?= $menu['status'] ?>"
                                            data-deskripsi="<?= htmlspecialchars($menu['deskripsi'] ?? '') ?>"
                                            data-gambar="<?= htmlspecialchars($menu['gambar']) ?>"
                                            data-bs-toggle="modal" 
                                            data-bs-target="#modalEdit">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </button>
                                    
                                    <!-- Delete Button -->
                                    <a href="menu.php?action=hapus&id=<?= $menu['id'] ?>" 
                                       class="btn btn-sm btn-outline-danger border-0" 
                                       onclick="return confirm('Apakah Anda yakin ingin menghapus menu ini?');">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">Belum ada menu yang ditambahkan.</td>
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
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-plus text-primary me-2"></i>Tambah Menu</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="menu.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="tambah">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="nama_menu" class="form-label small text-muted">Nama Menu</label>
                        <input type="text" name="nama_menu" id="nama_menu" class="form-control form-control-custom" placeholder="Contoh: Nasi Goreng Gila" required>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="id_kategori" class="form-label small text-muted">Kategori</label>
                            <select name="id_kategori" id="id_kategori" class="form-select form-select-custom" required>
                                <option value="">Pilih Kategori</option>
                                <?php foreach ($kategori_list as $kat): ?>
                                    <option value="<?= $kat['id'] ?>"><?= htmlspecialchars($kat['nama_kategori']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="harga" class="form-label small text-muted">Harga (Rp)</label>
                            <input type="number" name="harga" id="harga" class="form-control form-control-custom" placeholder="Contoh: 25000" min="0" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="status" class="form-label small text-muted">Status</label>
                        <select name="status" id="status" class="form-select form-select-custom">
                            <option value="tersedia">Tersedia</option>
                            <option value="habis">Habis</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="gambar" class="form-label small text-muted">Gambar Menu</label>
                        <input type="file" name="gambar" id="gambar" class="form-control form-control-custom" accept="image/*">
                        <div class="form-text text-muted-50 small">Rekomendasi rasio 1:1 (PNG, JPG, JPEG, WEBP).</div>
                    </div>
                    <div class="mb-3">
                        <label for="deskripsi" class="form-label small text-muted">Deskripsi</label>
                        <textarea name="deskripsi" id="deskripsi" rows="3" class="form-control form-control-custom" placeholder="Tulis bahan-bahan atau deskripsi ringkas..."></textarea>
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
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen-to-square text-info me-2"></i>Ubah Menu</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="menu.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">
                <input type="hidden" name="old_gambar" id="edit_old_gambar">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_nama_menu" class="form-label small text-muted">Nama Menu</label>
                        <input type="text" name="nama_menu" id="edit_nama_menu" class="form-control form-control-custom" required>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="edit_id_kategori" class="form-label small text-muted">Kategori</label>
                            <select name="id_kategori" id="edit_id_kategori" class="form-select form-select-custom" required>
                                <?php foreach ($kategori_list as $kat): ?>
                                    <option value="<?= $kat['id'] ?>"><?= htmlspecialchars($kat['nama_kategori']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="edit_harga" class="form-label small text-muted">Harga (Rp)</label>
                            <input type="number" name="harga" id="edit_harga" class="form-control form-control-custom" min="0" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="edit_status" class="form-label small text-muted">Status</label>
                        <select name="status" id="edit_status" class="form-select form-select-custom">
                            <option value="tersedia">Tersedia</option>
                            <option value="habis">Habis</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="edit_gambar" class="form-label small text-muted">Unggah Gambar Baru (Opsional)</label>
                        <input type="file" name="gambar" id="edit_gambar" class="form-control form-control-custom" accept="image/*">
                        <div class="form-text text-muted-50 small">Kosongkan jika tidak ingin mengubah gambar.</div>
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
            const kategori = this.getAttribute('data-kategori');
            const harga = this.getAttribute('data-harga');
            const status = this.getAttribute('data-status');
            const deskripsi = this.getAttribute('data-deskripsi');
            const gambar = this.getAttribute('data-gambar');
            
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_nama_menu').value = nama;
            document.getElementById('edit_id_kategori').value = kategori;
            document.getElementById('edit_harga').value = harga;
            document.getElementById('edit_status').value = status;
            document.getElementById('edit_deskripsi').value = deskripsi;
            document.getElementById('edit_old_gambar').value = gambar;
        });
    });
});
</script>
";

include 'includes/footer.php';
?>
