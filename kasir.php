<?php
$page_title = "Kasir / Point of Sale";
$page_desc = "Kelola keranjang pesanan meja dan proses pembayaran transaksi.";

require_once 'config/koneksi.php';
require_once 'config/auth.php';
require_auth(); // Admin dan kasir boleh akses halaman POS

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$message = '';
$message_type = 'success';
$active_order = null;
$cart_items = [];
$show_receipt_modal = false;
$receipt_data = [];

// 1. Dapatkan parameter id_pesanan dari URL atau POST
$id_pesanan = intval($_GET['id_pesanan'] ?? ($_POST['id_pesanan'] ?? 0));

// 0. Aksi: Buat Pesanan Baru (harus paling atas)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'buat_pesanan') {
    $id_meja_baru      = intval($_POST['id_meja'] ?? 0);
    $id_pelanggan_baru = !empty($_POST['id_pelanggan']) ? intval($_POST['id_pelanggan']) : null;
    $nama_pelanggan_baru = trim($_POST['nama_pelanggan_baru'] ?? '');
    $telepon_baru      = trim($_POST['telepon_baru'] ?? '');
    $email_baru        = trim($_POST['email_baru'] ?? '');
    $id_user_baru      = $_SESSION['user_id'] ?? 1;

    $tipe_pelanggan = $_POST['tipe_pelanggan'] ?? 'tamu';
    if ($tipe_pelanggan === 'member') {
        $nama_pelanggan_baru = '';
    } else {
        $id_pelanggan_baru = null;
    }

    if ($id_meja_baru > 0) {
        try {
            $conn->beginTransaction();

            // Cek meja masih kosong (race condition guard)
            $stmt_cek = $conn->prepare("SELECT status FROM meja WHERE id = :id LIMIT 1");
            $stmt_cek->execute(['id' => $id_meja_baru]);
            $status_meja = $stmt_cek->fetchColumn();

            if ($status_meja !== 'kosong') {
                throw new Exception('Meja sudah terisi. Pilih meja lain.');
            }

            // Jika kasir input nama pelanggan baru
            if (!empty($nama_pelanggan_baru)) {
                $is_member_baru = isset($_POST['is_member_baru']) ? 1 : 0;
                
                // Validasi nomor telepon integer (angka saja) jika didaftarkan sebagai member
                if ($is_member_baru === 1 && !empty($telepon_baru) && !ctype_digit($telepon_baru)) {
                    throw new Exception("Nomor telepon member harus berupa angka!");
                }

                // Validasi keunikan nomor telepon jika didaftarkan sebagai member
                if ($is_member_baru === 1 && !empty($telepon_baru)) {
                    $stmt_check = $conn->prepare("SELECT COUNT(*) FROM pelanggan WHERE telepon = :telp AND is_member = 1 LIMIT 1");
                    $stmt_check->execute(['telp' => $telepon_baru]);
                    if ($stmt_check->fetchColumn() > 0) {
                        throw new Exception("Nomor telepon tersebut sudah terdaftar sebagai member resmi!");
                    }
                }

                // Cek apakah nama / telp sudah terdaftar
                $stmt_member = $conn->prepare("SELECT id FROM pelanggan WHERE nama_pelanggan = :nama OR (telepon = :telp AND telepon != '-') LIMIT 1");
                $stmt_member->execute(['nama' => $nama_pelanggan_baru, 'telp' => $telepon_baru ?: 'n/a']);
                $existing_id = $stmt_member->fetchColumn();

                if ($existing_id) {
                    $id_pelanggan_baru = $existing_id;
                } else {
                    // Daftarkan sebagai member atau hanya tamu manual biasa
                    $stmt_ins = $conn->prepare("INSERT INTO pelanggan (nama_pelanggan, telepon, email, poin_saldo, is_member) VALUES (:nama, :telp, :email, 0, :is_member)");
                    $stmt_ins->execute([
                        'nama' => $nama_pelanggan_baru,
                        'telp' => $telepon_baru ?: '-',
                        'email' => $email_baru ?: '-',
                        'is_member' => $is_member_baru
                    ]);
                    $id_pelanggan_baru = $conn->lastInsertId();
                }
            }

            // Buat pesanan
            $stmt_new = $conn->prepare(
                "INSERT INTO pesanan (id_meja, id_pelanggan, id_user, status_pesanan, status_pembayaran)
                 VALUES (:id_meja, :id_pelanggan, :id_user, 'pending', 'belum_bayar')"
            );
            $stmt_new->execute([
                'id_meja'      => $id_meja_baru,
                'id_pelanggan' => $id_pelanggan_baru,
                'id_user'      => $id_user_baru,
            ]);
            $id_pesanan_baru = $conn->lastInsertId();

            // Tandai meja terisi
            $stmt_meja_set = $conn->prepare("UPDATE meja SET status = 'terisi' WHERE id = :id");
            $stmt_meja_set->execute(['id' => $id_meja_baru]);

            $conn->commit();
            header("Location: kasir.php?id_pesanan=" . $id_pesanan_baru);
            exit();

        } catch (Exception $e) {
            $conn->rollBack();
            $message      = 'Gagal buat pesanan: ' . $e->getMessage();
            $message_type = 'danger';
        }
    } else {
        $message      = 'Pilih meja terlebih dahulu.';
        $message_type = 'danger';
    }
}

// 2. Aksi: Proses Pembayaran / Checkout (harus dicek SEBELUM blok keranjang)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'proses_bayar') {
    $id_pesanan    = intval($_POST['id_pesanan'] ?? 0);
    $total_harga   = intval($_POST['total_harga'] ?? 0);
    $jumlah_bayar  = intval($_POST['jumlah_bayar'] ?? 0);
    $metode_pembayaran = $_POST['metode_pembayaran'] ?? 'tunai';

    if ($id_pesanan > 0 && $jumlah_bayar >= 0) {
        $poin_digunakan    = max(0, intval($_POST['poin_digunakan'] ?? 0));
        $id_pelanggan_poin = intval($_POST['id_pelanggan_poin'] ?? 0);
        $potongan_poin     = $poin_digunakan * 100; // 1 poin = Rp 100
        $total_setelah_poin = max(0, $total_harga - $potongan_poin);

        if ($jumlah_bayar < $total_setelah_poin) {
            $message      = 'Jumlah uang bayar tidak cukup!';
            $message_type = 'danger';
        } else {
            $conn->beginTransaction();
            $kembalian = $jumlah_bayar - $total_setelah_poin;

        try {
            // 1. Simpan Transaksi
            $stmt_trx = $conn->prepare("INSERT INTO transaksi (id_pesanan, metode_pembayaran, jumlah_bayar, kembalian) VALUES (:id_pesanan, :metode, :bayar, :kembalian)");
            $stmt_trx->execute([
                'id_pesanan' => $id_pesanan,
                'metode'     => $metode_pembayaran,
                'bayar'      => $jumlah_bayar,
                'kembalian'  => $kembalian
            ]);
            $id_transaksi_baru = $conn->lastInsertId();

            // 2. Update status pembayaran dan pesanan
            $stmt_pesanan = $conn->prepare("UPDATE pesanan SET status_pembayaran = 'sudah_bayar', status_pesanan = 'selesai' WHERE id = :id");
            $stmt_pesanan->execute(['id' => $id_pesanan]);

            // 3. Reset meja ke kosong
            $stmt_meja_id = $conn->prepare("SELECT id_meja FROM pesanan WHERE id = :id");
            $stmt_meja_id->execute(['id' => $id_pesanan]);
            $id_meja = $stmt_meja_id->fetchColumn();

            if (!empty($id_meja)) {
                $stmt_meja_update = $conn->prepare("UPDATE meja SET status = 'kosong' WHERE id = :id");
                $stmt_meja_update->execute(['id' => $id_meja]);
            }

            // 4. Proses poin: debet jika member & ada redeem
            if ($id_pelanggan_poin > 0) {
                if ($poin_digunakan > 0) {
                    // Validasi saldo cukup (double-check server side)
                    $stmt_cek_poin = $conn->prepare("SELECT poin_saldo FROM pelanggan WHERE id = :id");
                    $stmt_cek_poin->execute(['id' => $id_pelanggan_poin]);
                    $saldo_now = intval($stmt_cek_poin->fetchColumn());

                    if ($poin_digunakan <= $saldo_now) {
                        $conn->prepare("UPDATE pelanggan SET poin_saldo = poin_saldo - :p WHERE id = :id")
                             ->execute(['p' => $poin_digunakan, 'id' => $id_pelanggan_poin]);
                        $conn->prepare("INSERT INTO poin_transaksi (id_pelanggan, id_pesanan, tipe, jumlah_poin, keterangan) VALUES (:pl, :ps, 'debet', :jp, 'Redeem poin saat checkout')")
                             ->execute(['pl' => $id_pelanggan_poin, 'ps' => $id_pesanan, 'jp' => $poin_digunakan]);
                    }
                }
                
                // Kredit poin baru dari belanja (1 poin per Rp 10.000)
                $poin_baru = floor($total_setelah_poin / 10000);
                if ($poin_baru > 0) {
                    $conn->prepare("UPDATE pelanggan SET poin_saldo = poin_saldo + :p WHERE id = :id")
                         ->execute(['p' => $poin_baru, 'id' => $id_pelanggan_poin]);
                    $conn->prepare("INSERT INTO poin_transaksi (id_pelanggan, id_pesanan, tipe, jumlah_poin, keterangan) VALUES (:pl, :ps, 'kredit', :jp, 'Poin belanja transaksi')")
                         ->execute(['pl' => $id_pelanggan_poin, 'ps' => $id_pesanan, 'jp' => $poin_baru]);
                }
            }

            $conn->commit();

            // Set data untuk struk cetak
            $show_receipt_modal = true;

            $stmt_receipt_info = $conn->prepare("
                SELECT p.*, t.tanggal_transaksi, t.metode_pembayaran, t.jumlah_bayar, t.kembalian, m.nomor_meja, pl.nama_pelanggan, u.nama_lengkap as nama_kasir
                FROM pesanan p
                JOIN transaksi t ON t.id_pesanan = p.id
                LEFT JOIN meja m ON p.id_meja = m.id
                LEFT JOIN pelanggan pl ON p.id_pelanggan = pl.id
                LEFT JOIN users u ON p.id_user = u.id
                WHERE p.id = :id_pesanan LIMIT 1
            ");
            $stmt_receipt_info->execute(['id_pesanan' => $id_pesanan]);
            $receipt_data['pesanan'] = $stmt_receipt_info->fetch();

            $stmt_receipt_items = $conn->prepare("
                SELECT dp.*, m.nama_menu
                FROM detail_pesanan dp
                JOIN menu m ON dp.id_menu = m.id
                WHERE dp.id_pesanan = :id_pesanan
            ");
            $stmt_receipt_items->execute(['id_pesanan' => $id_pesanan]);
            $receipt_data['items'] = $stmt_receipt_items->fetchAll();

            $message      = "Transaksi pembayaran berhasil diselesaikan!";
            $message_type = "success";

        } catch (Exception $e) {
            $conn->rollBack();
            $message      = "Gagal memproses pembayaran: " . $e->getMessage();
            $message_type = "danger";
        }
        } // end if jumlah_bayar >= total_setelah_poin
    } else {
        $message      = 'Data pembayaran tidak valid.';
        $message_type = 'danger';
    }
}

// 3. Aksi: Tambah/Kurangi/Hapus Item di Keranjang (exclude proses_bayar)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $id_pesanan > 0 && $_POST['action'] !== 'proses_bayar') {
    $action  = $_POST['action'];
    $id_menu = intval($_POST['id_menu'] ?? 0);
    
    // Pastikan pesanan belum dibayar sebelum bisa memodifikasi keranjang
    $stmt_status = $conn->prepare("SELECT status_pembayaran FROM pesanan WHERE id = :id");
    $stmt_status->execute(['id' => $id_pesanan]);
    $status_bayar = $stmt_status->fetchColumn();
    
    if ($status_bayar === 'belum_bayar') {
        try {
            $conn->beginTransaction();
            
            if ($action === 'add_to_cart') {
                // Ambil harga menu
                $stmt_menu = $conn->prepare("SELECT harga FROM menu WHERE id = :id AND status = 'tersedia' LIMIT 1");
                $stmt_menu->execute(['id' => $id_menu]);
                $menu = $stmt_menu->fetch();
                
                if ($menu) {
                    $harga_satuan = $menu['harga'];
                    
                    // Cek apakah item sudah ada di detail pesanan
                    $stmt_check = $conn->prepare("SELECT id, jumlah FROM detail_pesanan WHERE id_pesanan = :id_pesanan AND id_menu = :id_menu LIMIT 1");
                    $stmt_check->execute(['id_pesanan' => $id_pesanan, 'id_menu' => $id_menu]);
                    $detail = $stmt_check->fetch();
                    
                    if ($detail) {
                        // Increment Qty
                        $new_qty = $detail['jumlah'] + 1;
                        $new_subtotal = $new_qty * $harga_satuan;
                        $stmt_update = $conn->prepare("UPDATE detail_pesanan SET jumlah = :jumlah, subtotal = :subtotal WHERE id = :id");
                        $stmt_update->execute(['jumlah' => $new_qty, 'subtotal' => $new_subtotal, 'id' => $detail['id']]);
                    } else {
                        // Insert New
                        $stmt_insert = $conn->prepare("INSERT INTO detail_pesanan (id_pesanan, id_menu, jumlah, harga_satuan, subtotal) VALUES (:id_pesanan, :id_menu, 1, :harga, :harga)");
                        $stmt_insert->execute(['id_pesanan' => $id_pesanan, 'id_menu' => $id_menu, 'harga' => $harga_satuan]);
                    }
                }
            }
            
            if ($action === 'qty_minus') {
                $stmt_check = $conn->prepare("SELECT id, jumlah, harga_satuan FROM detail_pesanan WHERE id_pesanan = :id_pesanan AND id_menu = :id_menu LIMIT 1");
                $stmt_check->execute(['id_pesanan' => $id_pesanan, 'id_menu' => $id_menu]);
                $detail = $stmt_check->fetch();
                
                if ($detail) {
                    if ($detail['jumlah'] > 1) {
                        $new_qty = $detail['jumlah'] - 1;
                        $new_subtotal = $new_qty * $detail['harga_satuan'];
                        $stmt_update = $conn->prepare("UPDATE detail_pesanan SET jumlah = :jumlah, subtotal = :subtotal WHERE id = :id");
                        $stmt_update->execute(['jumlah' => $new_qty, 'subtotal' => $new_subtotal, 'id' => $detail['id']]);
                    } else {
                        // Hapus jika tinggal 1
                        $stmt_del = $conn->prepare("DELETE FROM detail_pesanan WHERE id = :id");
                        $stmt_del->execute(['id' => $detail['id']]);
                    }
                }
            }
            
            if ($action === 'remove_item') {
                $stmt_del = $conn->prepare("DELETE FROM detail_pesanan WHERE id_pesanan = :id_pesanan AND id_menu = :id_menu");
                $stmt_del->execute(['id_pesanan' => $id_pesanan, 'id_menu' => $id_menu]);
            }
            
            // Rekalkulasi Total Harga Pesanan
            $stmt_sum = $conn->prepare("SELECT SUM(subtotal) FROM detail_pesanan WHERE id_pesanan = :id_pesanan");
            $stmt_sum->execute(['id_pesanan' => $id_pesanan]);
            $total_harga = intval($stmt_sum->fetchColumn() ?: 0);
            
            $stmt_total = $conn->prepare("UPDATE pesanan SET total_harga = :total_harga WHERE id = :id");
            $stmt_total->execute(['total_harga' => $total_harga, 'id' => $id_pesanan]);
            
            $conn->commit();
            header("Location: kasir.php?id_pesanan=" . $id_pesanan);
            exit();
            
        } catch (Exception $e) {
            $conn->rollBack();
            $message = "Terjadi kesalahan keranjang: " . $e->getMessage();
            $message_type = "danger";
        }
    } else {
        $message = "Transaksi sudah dibayar! Keranjang tidak dapat diedit lagi.";
        $message_type = "danger";
    }
}


// 4. Load Data Pesanan Aktif jika terpilih
$member_data = null;
if ($id_pesanan > 0) {
    $stmt_get_order = $conn->prepare("
        SELECT p.*, m.nomor_meja, pl.nama_pelanggan, pl.poin_saldo, pl.telepon as member_telepon
        FROM pesanan p
        LEFT JOIN meja m ON p.id_meja = m.id
        LEFT JOIN pelanggan pl ON p.id_pelanggan = pl.id
        WHERE p.id = :id LIMIT 1
    ");
    $stmt_get_order->execute(['id' => $id_pesanan]);
    $active_order = $stmt_get_order->fetch();

    if ($active_order) {
        // Load member data
        if (!empty($active_order['id_pelanggan'])) {
            $member_data = [
                'id'            => $active_order['id_pelanggan'],
                'nama'          => $active_order['nama_pelanggan'],
                'telepon'       => $active_order['member_telepon'],
                'poin_saldo'    => intval($active_order['poin_saldo'] ?? 0),
            ];
        }
        // Ambil item dalam keranjang
        $stmt_cart = $conn->prepare("
            SELECT dp.*, m.nama_menu, m.gambar
            FROM detail_pesanan dp
            JOIN menu m ON dp.id_menu = m.id
            WHERE dp.id_pesanan = :id_pesanan
        ");
        $stmt_cart->execute(['id_pesanan' => $id_pesanan]);
        $cart_items = $stmt_cart->fetchAll();
    }
}

// 5. Ambil Daftar Semua Menu (Filter: Tersedia)
$stmt_menus = $conn->query("
    SELECT m.*, k.nama_kategori 
    FROM menu m 
    LEFT JOIN kategori k ON m.id_kategori = k.id 
    WHERE m.status = 'tersedia' 
    ORDER BY k.nama_kategori, m.nama_menu ASC
");
$daftar_menu = $stmt_menus->fetchAll();

// Group menu berdasarkan kategori untuk tabs filter di UI
$menu_by_category = [];
foreach ($daftar_menu as $m) {
    $cat = $m['nama_kategori'] ?? 'Lain-lain';
    $menu_by_category[$cat][] = $m;
}

// Ambil list meja yang saat ini Terisi/Aktif untuk sidebar switcher kasir
$stmt_active_tables = $conn->query("
    SELECT p.id as id_pesanan, m.nomor_meja, pl.nama_pelanggan
    FROM pesanan p
    JOIN meja m ON p.id_meja = m.id
    LEFT JOIN pelanggan pl ON p.id_pelanggan = pl.id
    WHERE p.status_pembayaran = 'belum_bayar'
    ORDER BY m.nomor_meja ASC
");
$active_tables_list = $stmt_active_tables->fetchAll();

// Load meja kosong untuk modal Pesanan Baru
$stmt_meja_kosong = $conn->query("SELECT id, nomor_meja, kapasitas FROM meja WHERE status = 'kosong' ORDER BY nomor_meja ASC");
$daftar_meja_kosong = $stmt_meja_kosong->fetchAll();

// Load pelanggan untuk modal Pesanan Baru
$stmt_pelanggan_all = $conn->query("SELECT id, nama_pelanggan FROM pelanggan ORDER BY id ASC");
$daftar_pelanggan = $stmt_pelanggan_all->fetchAll();

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

<div class="pos-container">
    
    <!-- LEFT: Menu Selection Section -->
    <div class="pos-menu-section">
        <!-- Pencarian, Switcher Pesanan Aktif & Tombol Pesanan Baru -->
        <div class="row g-2 mb-3 align-items-center">
            <div class="col-md-5 col-lg-5">
                <div class="input-group">
                    <span class="input-group-text form-control-custom text-muted"><i class="fa-solid fa-magnifying-glass"></i></span>
                    <input type="text" id="posMenuSearch" class="form-control form-control-custom" placeholder="Cari menu makanan/minuman...">
                </div>
            </div>
            <div class="col-md-4 col-lg-4">
                <select id="selectOrderSwitcher" class="form-select form-select-custom" onchange="location.href='kasir.php?id_pesanan='+this.value">
                    <option value="">-- Pilih Meja Aktif --</option>
                    <?php foreach ($active_tables_list as $at): ?>
                        <option value="<?= $at['id_pesanan'] ?>" <?= $at['id_pesanan'] == $id_pesanan ? 'selected' : '' ?>>
                            <?= htmlspecialchars($at['nomor_meja']) ?> - <?= htmlspecialchars($at['nama_pelanggan']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 col-lg-3">
                <button type="button" class="btn w-100 fw-semibold" data-bs-toggle="modal" data-bs-target="#modalPesananBaru"
                    style="background: var(--gradient-primary); color:#fff; border:none; border-radius: var(--border-radius);">
                    <i class="fa-solid fa-circle-plus me-2"></i>Pesanan Baru
                </button>
            </div>
        </div>

        <?php if ($active_order): ?>
            <!-- Category Tabs -->
            <div class="menu-category-tabs">
                <div class="category-tab active" data-category="all">Semua Menu</div>
                <?php foreach (array_keys($menu_by_category) as $cat): ?>
                    <div class="category-tab" data-category="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></div>
                <?php endforeach; ?>
            </div>
            
            <!-- Menu Grid -->
            <div class="menu-grid">
                <?php foreach ($daftar_menu as $menu): ?>
                    <div class="pos-menu-card" data-category="<?= htmlspecialchars($menu['nama_kategori'] ?? 'Lain-lain') ?>" onclick="addToCart(<?= $menu['id'] ?>)">
                        <?php 
                            $img_src = 'assets/images/uploads/' . $menu['gambar'];
                            if (empty($menu['gambar']) || !file_exists($img_src)) {
                                $img_src = 'assets/images/default_food.png';
                            }
                        ?>
                        <div class="menu-card-img" style="background-image: url('<?= $img_src ?>');">
                            <span class="menu-card-price">Rp <?= number_format($menu['harga'], 0, ',', '.') ?></span>
                        </div>
                        <div class="menu-card-body">
                            <h6 class="menu-card-title"><?= htmlspecialchars($menu['nama_menu']) ?></h6>
                            <p class="menu-card-desc small text-muted-50 mb-0"><?= htmlspecialchars($menu['deskripsi'] ?? '-') ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="d-flex flex-column align-items-center justify-content-center h-100 text-center text-muted" style="min-height: 400px;">
                <i class="fa-solid fa-cash-register fa-4x mb-4 text-muted"></i>
                <h5 class="text-main fw-semibold">Belum Ada Pesanan Aktif</h5>
                <p class="mb-4 text-muted small">Pilih meja aktif dari dropdown di atas,<br>atau buat pesanan baru untuk meja yang tersedia.</p>
                <button type="button" class="btn btn-primary border-0 px-4" data-bs-toggle="modal" data-bs-target="#modalPesananBaru"
                    style="background: var(--gradient-primary); border-radius: var(--border-radius);">
                    <i class="fa-solid fa-circle-plus me-2"></i>Buat Pesanan Baru
                </button>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- RIGHT: Billing Cart Panel -->
    <div class="pos-cart-section">
        <?php if ($active_order): ?>
            <!-- Form untuk Aksi Keranjang (Dikirim secara dinamis lewat JS) -->
            <form id="cartActionForm" action="kasir.php" method="POST" style="display:none;">
                <input type="hidden" name="id_pesanan" value="<?= $active_order['id'] ?>">
                <input type="hidden" name="action" id="cart_action">
                <input type="hidden" name="id_menu" id="cart_id_menu">
            </form>
            
            <div class="cart-header d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="fw-bold text-main mb-0"><?= htmlspecialchars($active_order['nomor_meja'] ?? 'Takeaway') ?></h5>
                    <?php if ($member_data): ?>
                        <small class="text-muted">
                            <i class="fa-solid fa-id-card me-1" style="color:var(--accent-coffee);"></i>
                            <?= htmlspecialchars($member_data['nama']) ?>
                            &nbsp;|&nbsp;
                            <i class="fa-solid fa-star me-1" style="color:#f59e0b;"></i>
                            <strong><?= number_format($member_data['poin_saldo']) ?></strong> poin
                        </small>
                    <?php else: ?>
                        <small class="text-muted"><i class="fa-solid fa-user me-1"></i>Non-member</small>
                    <?php endif; ?>
                </div>
                <span class="badge bg-warning text-dark font-monospace px-2 py-1 small">Belum Lunas</span>
            </div>
            
            <!-- Cart Items list -->
            <div class="cart-items-wrapper">
                <?php if (count($cart_items) > 0): ?>
                    <?php foreach ($cart_items as $item): ?>
                        <div class="cart-item">
                            <div class="cart-item-info">
                                <div class="cart-item-name text-main"><?= htmlspecialchars($item['nama_menu']) ?></div>
                                <div class="cart-item-price">Rp <?= number_format($item['harga_satuan'], 0, ',', '.') ?></div>
                            </div>
                            
                            <div class="cart-item-qty">
                                <button type="button" class="qty-btn" onclick="updateQty('qty_minus', <?= $item['id_menu'] ?>)">-</button>
                                <span class="qty-val text-main"><?= $item['jumlah'] ?></span>
                                <button type="button" class="qty-btn" onclick="updateQty('add_to_cart', <?= $item['id_menu'] ?>)">+</button>
                            </div>
                            
                            <i class="fa-solid fa-trash-can cart-item-delete" onclick="updateQty('remove_item', <?= $item['id_menu'] ?>)"></i>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="d-flex flex-column align-items-center justify-content-center h-100 text-center text-muted">
                        <i class="fa-solid fa-basket-shopping fa-2x mb-2 text-muted"></i>
                        <span class="small">Keranjang masih kosong</span>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Checkout Summary & Payment -->
            <div class="cart-summary">
                <div class="summary-row">
                    <span class="text-muted">Subtotal</span>
                    <span class="text-main">Rp <?= number_format($active_order['total_harga'], 0, ',', '.') ?></span>
                </div>
                <div class="summary-row">
                    <span class="text-muted">Pajak (PPN 10%)</span>
                    <?php $tax = $active_order['total_harga'] * 0.1; ?>
                    <span class="text-main">Rp <?= number_format($tax, 0, ',', '.') ?></span>
                </div>
                <div class="summary-total">
                    <span>Total Tagihan</span>
                    <?php $grand_total = $active_order['total_harga'] + $tax; ?>
                    <span>Rp <?= number_format($grand_total, 0, ',', '.') ?></span>
                </div>
                
                <?php if (count($cart_items) > 0): ?>
                    <!-- Form Pembayaran -->
                    <button type="button" class="btn btn-success border-0 w-100 py-2.5 mt-3 fw-bold" data-bs-toggle="modal" data-bs-target="#modalPembayaran" style="background: var(--gradient-green); border-radius: 8px;">
                        <i class="fa-solid fa-wallet me-2"></i>Proses Pembayaran
                    </button>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="d-flex align-items-center justify-content-center h-100 text-muted small">
                Pilih pesanan aktif terlebih dahulu untuk menampilkan panel pembayaran.
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Proses Pembayaran -->
<?php if ($active_order): ?>
<div class="modal fade" id="modalPembayaran" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content modal-content-custom">
            <div class="modal-header modal-header-custom">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-wallet text-success me-2"></i>Checkout Pembayaran</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="kasir.php" method="POST">
                <input type="hidden" name="action" value="proses_bayar">
                <input type="hidden" name="id_pesanan" value="<?= $active_order['id'] ?>">
                <input type="hidden" name="total_harga" value="<?= $grand_total ?>">
                <input type="hidden" name="id_pelanggan_poin" value="<?= $member_data['id'] ?? 0 ?>">
                <?php $poin_max = $member_data ? $member_data['poin_saldo'] : 0; ?>
                <?php $nilai_max_poin = $poin_max * 100; ?>
                
                <div class="modal-body">
                    <!-- Rincian Jumlah -->
                    <div class="mb-3 p-3 rounded text-center" style="background-color: var(--bg-tertiary); border: 1px solid var(--border-color);">
                        <span class="text-muted d-block small text-transform-uppercase">Total Tagihan (Termasuk PPN)</span>
                        <h2 class="fw-bold mb-0" style="color: var(--accent-coffee);" id="displayTotalTagihan">Rp <?= number_format($grand_total, 0, ',', '.') ?></h2>
                    </div>

                    <?php if ($member_data && $poin_max > 0): ?>
                    <!-- Redeem Poin -->
                    <div class="mb-3 p-3 rounded" style="background: rgba(245,158,11,0.08); border: 1px solid rgba(245,158,11,0.3); border-radius: 8px;">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="form-label mb-0 fw-semibold small" style="color:#f59e0b;">
                                <i class="fa-solid fa-star me-1"></i>Gunakan Poin Member
                            </label>
                            <span class="small text-muted">Saldo: <strong><?= number_format($poin_max) ?> poin</strong> (= Rp <?= number_format($nilai_max_poin, 0, ',', '.') ?>)</span>
                        </div>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text" style="background:var(--bg-tertiary); border-color:var(--border-color); color:var(--text-muted);">Poin</span>
                            <input type="number" name="poin_digunakan" id="inputPoinRedeem"
                                   class="form-control form-control-custom"
                                   min="0" max="<?= $poin_max ?>" value="0"
                                   placeholder="0">
                            <button type="button" class="btn btn-sm" onclick="document.getElementById('inputPoinRedeem').value=<?= $poin_max ?>; hitungPoin();"
                                    style="background:rgba(245,158,11,0.2); color:#f59e0b; border-color:var(--border-color);">Pakai Semua</button>
                        </div>
                        <div class="mt-2 small" id="infoPoinPotong" style="color:#f59e0b;"></div>
                    </div>
                    <?php else: ?>
                        <input type="hidden" name="poin_digunakan" value="0">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label for="metode_pembayaran" class="form-label small text-muted">Metode Pembayaran</label>
                        <select name="metode_pembayaran" id="metode_pembayaran" class="form-select form-select-custom" required>
                            <option value="tunai">Tunai</option>
                            <option value="debit">Kartu Debit</option>
                            <option value="qris">QRIS (Gopay/OVO/Dana)</option>
                        </select>
                    </div>
                    
                    <div class="mb-3" id="inputBayarGroup">
                        <label for="jumlah_bayar" class="form-label small text-muted">Jumlah Uang Bayar (Rp)</label>
                        <input type="number" name="jumlah_bayar" id="jumlah_bayar" class="form-control form-control-custom" placeholder="Contoh: 100000" min="0" required>
                    </div>
                    
                    <!-- Kembalian real-time -->
                    <div class="mb-3 p-3 rounded text-center" id="kembalianPanel" style="background-color: var(--bg-tertiary); border: 1px solid var(--border-color);">
                        <span class="text-muted d-block small">Kembalian</span>
                        <h3 class="text-success fw-bold mb-0" id="kembalianValue">Rp 0</h3>
                    </div>
                </div>
                
                <div class="modal-footer modal-footer-custom">
                    <button type="button" class="btn btn-secondary border-0" data-bs-dismiss="modal" style="border-radius: 8px;">Batal</button>
                    <button type="submit" class="btn btn-success border-0 text-white" style="background: var(--gradient-green); border-radius: 8px;">Proses & Selesai</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Modal Simulasi Print Struk Pembayaran -->
<?php if ($show_receipt_modal && !empty($receipt_data)): ?>
<div class="modal fade show" id="modalReceipt" tabindex="-1" style="display: block; background-color: rgba(0,0,0,0.7);" aria-modal="true" role="dialog">
    <div class="modal-dialog modal-dialog-centered modal-sm" style="max-width: 420px;">
        <div class="modal-content modal-content-custom">
            <div class="modal-header modal-header-custom border-0 pb-0">
                <h5 class="modal-title fw-bold text-success"><i class="fa-solid fa-circle-check me-2"></i>Pembayaran Lunas</h5>
                <button type="button" class="btn-close btn-close-white" onclick="closeReceiptModal()" aria-label="Close"></button>
            </div>
            
            <div class="modal-body py-4">
                <!-- Receipt simulated view -->
                <div class="receipt-preview text-start" id="printableReceipt">
                    <div class="receipt-header">
                        <div class="receipt-title">KAFFE POS & BISTRO</div>
                        <div class="small">Bandung, Jawa Barat</div>
                        <div class="small">Telp: 022-12345678</div>
                    </div>
                    
                    <div class="receipt-divider"></div>
                    
                    <div class="receipt-item">
                        <span>No. Pesanan:</span>
                        <span>#<?= $receipt_data['pesanan']['id'] ?></span>
                    </div>
                    <div class="receipt-item">
                        <span>Tanggal:</span>
                        <span><?= date('d/m/Y H:i', strtotime($receipt_data['pesanan']['tanggal_transaksi'])) ?></span>
                    </div>
                    <div class="receipt-item">
                        <span>Meja:</span>
                        <span><?= htmlspecialchars($receipt_data['pesanan']['nomor_meja'] ?? 'Takeaway') ?></span>
                    </div>
                    <div class="receipt-item">
                        <span>Pelanggan:</span>
                        <span><?= htmlspecialchars($receipt_data['pesanan']['nama_pelanggan'] ?? 'Umum') ?></span>
                    </div>
                    <div class="receipt-item">
                        <span>Kasir:</span>
                        <span><?= htmlspecialchars($receipt_data['pesanan']['nama_kasir'] ?? 'Staff') ?></span>
                    </div>
                    
                    <div class="receipt-divider"></div>
                    
                    <!-- Loop Items -->
                    <?php foreach ($receipt_data['items'] as $item): ?>
                        <div class="receipt-item">
                            <span><?= htmlspecialchars($item['nama_menu']) ?> (<?= $item['jumlah'] ?>x)</span>
                            <span>Rp <?= number_format($item['subtotal'], 0, ',', '.') ?></span>
                        </div>
                    <?php endforeach; ?>
                    
                    <div class="receipt-divider"></div>
                    
                    <div class="receipt-item">
                        <span>Subtotal:</span>
                        <span>Rp <?= number_format($receipt_data['pesanan']['total_harga'], 0, ',', '.') ?></span>
                    </div>
                    <div class="receipt-item text-muted">
                        <span>PPN (10%):</span>
                        <?php $receipt_tax = $receipt_data['pesanan']['total_harga'] * 0.1; ?>
                        <span>Rp <?= number_format($receipt_tax, 0, ',', '.') ?></span>
                    </div>
                    <div class="receipt-item receipt-total-section">
                        <span>TOTAL TAGIHAN:</span>
                        <?php $receipt_grand = $receipt_data['pesanan']['total_harga'] + $receipt_tax; ?>
                        <span>Rp <?= number_format($receipt_grand, 0, ',', '.') ?></span>
                    </div>
                    
                    <div class="receipt-divider"></div>
                    
                    <div class="receipt-item">
                        <span>Metode Pembayaran:</span>
                        <span><?= strtoupper($receipt_data['pesanan']['metode_pembayaran']) ?></span>
                    </div>
                    <div class="receipt-item">
                        <span>Bayar:</span>
                        <span>Rp <?= number_format($receipt_data['pesanan']['jumlah_bayar'], 0, ',', '.') ?></span>
                    </div>
                    <div class="receipt-item text-success">
                        <span>Kembalian:</span>
                        <span>Rp <?= number_format($receipt_data['pesanan']['kembalian'], 0, ',', '.') ?></span>
                    </div>
                    
                    <div class="receipt-divider"></div>
                    <div class="text-center small mt-3" style="font-size: 11px;">
                        Terima Kasih Atas Kunjungan Anda<br>Sampai Jumpa Kembali!
                    </div>
                </div>
            </div>
            
            <div class="modal-footer modal-footer-custom border-0 pt-0">
                <button type="button" class="btn btn-secondary border-0" onclick="closeReceiptModal()" style="border-radius: 8px;">Tutup</button>
                <button type="button" class="btn btn-info border-0 text-white" onclick="printReceipt()" style="background: var(--gradient-primary); border-radius: 8px;">
                    <i class="fa-solid fa-print me-2"></i>Cetak Struk
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
// Script JavaScript POS
$extra_js = "
<script>
// Aksi client-side filter menu berdasar Kategori Tabs
document.addEventListener('DOMContentLoaded', function() {
    const categoryTabs = document.querySelectorAll('.category-tab');
    const menuCards = document.querySelectorAll('.pos-menu-card');
    
    categoryTabs.forEach(tab => {
        tab.addEventListener('click', function() {
            // Remove active from all tabs
            categoryTabs.forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            
            const category = this.getAttribute('data-category');
            
            menuCards.forEach(card => {
                const cardCat = card.getAttribute('data-category');
                if (category === 'all' || cardCat === category) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });
        });
    });
    
    // Live Search Menu POS
    const posMenuSearch = document.getElementById('posMenuSearch');
    if (posMenuSearch) {
        posMenuSearch.addEventListener('keyup', function() {
            const filter = this.value.toLowerCase();
            menuCards.forEach(card => {
                const title = card.querySelector('.menu-card-title').innerText.toLowerCase();
                const desc = card.querySelector('.menu-card-desc').innerText.toLowerCase();
                
                if (title.indexOf(filter) > -1 || desc.indexOf(filter) > -1) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });
        });
    }
    
    // Live Cash Calculation Kembalian & Poin
    const totalHarga = " . ($grand_total ?? 0) . ";
    const inputBayar = document.getElementById('jumlah_bayar');
    const kembalianVal = document.getElementById('kembalianValue');
    const metodeSelect = document.getElementById('metode_pembayaran');
    const inputBayarGroup = document.getElementById('inputBayarGroup');
    const kembalianPanel = document.getElementById('kembalianPanel');
    const inputPoin = document.getElementById('inputPoinRedeem');
    const infoPoin = document.getElementById('infoPoinPotong');
    const displayTotal = document.getElementById('displayTotalTagihan');
    
    function hitungAkhir() {
        const poin = inputPoin ? (parseInt(inputPoin.value) || 0) : 0;
        let potongan = poin * 100;
        
        // Cap potongan agar tidak melebihi total tagihan
        if (potongan > totalHarga) {
            potongan = totalHarga;
            const maxPoin = Math.floor(totalHarga / 100);
            if (inputPoin) inputPoin.value = maxPoin;
        }
        
        if (infoPoin) {
            infoPoin.innerText = potongan > 0 ? 'Potongan: - ' + formatRupiah(potongan) : '';
        }
        
        const totalAkhir = totalHarga - potongan;
        if (displayTotal) {
            displayTotal.innerText = formatRupiah(totalAkhir);
        }
        
        if (inputBayar) {
            inputBayar.min = totalAkhir;
        }
        
        const bayar = inputBayar ? (parseInt(inputBayar.value) || 0) : 0;
        const kembalian = bayar - totalAkhir;
        if (kembalianVal) {
            if (kembalian >= 0) {
                kembalianVal.innerText = formatRupiah(kembalian);
            } else {
                kembalianVal.innerText = 'Rp 0';
            }
        }
        return totalAkhir;
    }
    
    window.hitungPoin = hitungAkhir; // Bind ke global scope agar tombol 'Pakai Semua' bisa panggil
    
    if (inputPoin) {
        inputPoin.addEventListener('input', hitungAkhir);
    }
    
    if (inputBayar && kembalianVal) {
        inputBayar.addEventListener('input', hitungAkhir);
    }
    
    if (metodeSelect) {
        metodeSelect.addEventListener('change', function() {
            const totalAkhir = hitungAkhir();
            if (this.value !== 'tunai') {
                if (inputBayar) inputBayar.value = totalAkhir;
                if (inputBayarGroup) inputBayarGroup.style.display = 'none';
                if (kembalianPanel) kembalianPanel.style.display = 'none';
                if (kembalianVal) kembalianVal.innerText = 'Rp 0';
            } else {
                if (inputBayar) inputBayar.value = '';
                if (inputBayarGroup) inputBayarGroup.style.display = 'block';
                if (kembalianPanel) kembalianPanel.style.display = 'block';
                hitungAkhir();
            }
        });
    }

    // Logic Toggle Tipe Pelanggan di Modal Kasir
    const radioTamuKasir = document.getElementById('tipe_tamu_kasir');
    const radioMemberKasir = document.getElementById('tipe_member_kasir');
    const secTamuKasir = document.getElementById('sec_tamu_kasir');
    const secMemberKasir = document.getElementById('sec_member_kasir');
    const inputMemberSearchKasir = document.getElementById('member_search_input_kasir');
    const hiddenMemberIdKasir = document.getElementById('modal_id_pelanggan');
    const inputNamaTamuKasir = document.getElementById('modal_nama_pelanggan_baru');

    function toggleCustomerTypeKasir() {
        if (radioTamuKasir && radioTamuKasir.checked) {
            if (secTamuKasir) secTamuKasir.style.display = 'block';
            if (secMemberKasir) secMemberKasir.style.display = 'none';
        } else if (radioMemberKasir && radioMemberKasir.checked) {
            if (secTamuKasir) secTamuKasir.style.display = 'none';
            if (secMemberKasir) secMemberKasir.style.display = 'block';
        }
    }

    if (radioTamuKasir) radioTamuKasir.addEventListener('change', toggleCustomerTypeKasir);
    if (radioMemberKasir) radioMemberKasir.addEventListener('change', toggleCustomerTypeKasir);

    // Datalist member ID synchronizer (Kasir)
    if (inputMemberSearchKasir) {
        inputMemberSearchKasir.addEventListener('input', function() {
            const val = this.value;
            const datalist = document.getElementById('member_datalist_kasir');
            let foundId = '';
            if (datalist) {
                const options = datalist.options;
                for (let i = 0; i < options.length; i++) {
                    if (options[i].value === val) {
                        foundId = options[i].getAttribute('data-id');
                        break;
                    }
                }
            }
            if (hiddenMemberIdKasir) hiddenMemberIdKasir.value = foundId;
        });
    }

    // Toggle Member Detail fields
    const checkDaftarMemberKasir = document.getElementById('is_member_baru_kasir');
    const secMemberDetailKasir = document.getElementById('sec_member_detail_kasir');
    if (checkDaftarMemberKasir && secMemberDetailKasir) {
        checkDaftarMemberKasir.addEventListener('change', function() {
            secMemberDetailKasir.style.display = this.checked ? 'block' : 'none';
        });
    }

    // Validasi form tambah pesanan
    const formKasir = document.getElementById('formPesananBaruKasir');
    if (formKasir) {
        formKasir.addEventListener('submit', function(e) {
            if (radioTamuKasir && radioTamuKasir.checked) {
                if (inputNamaTamuKasir && inputNamaTamuKasir.value.trim() === '') {
                    alert('Silakan tulis nama tamu manual terlebih dahulu!');
                    e.preventDefault();
                }
            } else if (radioMemberKasir && radioMemberKasir.checked) {
                if (hiddenMemberIdKasir && hiddenMemberIdKasir.value === '') {
                    alert('Silakan pilih salah satu member terdaftar dari hasil pencarian (klik/pilih opsi autocomplete)!');
                    e.preventDefault();
                }
            }
        });
    }
});

// Submit Aksi Keranjang
function updateQty(action, menuId) {
    document.getElementById('cart_action').value = action;
    document.getElementById('cart_id_menu').value = menuId;
    document.getElementById('cartActionForm').submit();
}

function addToCart(menuId) {
    updateQty('add_to_cart', menuId);
}

// Menutup modal struk & refresh halaman ke kasir utama
function closeReceiptModal() {
    location.href = 'kasir.php';
}

// Print Struk spesifik
function printReceipt() {
    const printContents = document.getElementById('printableReceipt').innerHTML;
    const originalContents = document.body.innerHTML;
    
    document.body.innerHTML = printContents;
    window.print();
    document.body.innerHTML = originalContents;
    
    // Reload halaman agar event handler JS nempel kembali setelah body diubah
    location.href = 'kasir.php';
}
</script>
";

// =========================================================
// MODAL: PESANAN BARU
// =========================================================
?>
<div class="modal fade" id="modalPesananBaru" tabindex="-1" aria-labelledby="modalPesananBaruLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: var(--border-radius-lg);">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-main" id="modalPesananBaruLabel">
                    <i class="fa-solid fa-circle-plus me-2" style="color: var(--accent-coffee);"></i>Pesanan Baru
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="kasir.php" id="formPesananBaruKasir">
                <input type="hidden" name="action" value="buat_pesanan">
                <div class="modal-body pt-3">
                    <?php if (empty($daftar_meja_kosong)): ?>
                        <div class="alert border-0 text-center py-3"
                             style="background: rgba(201,87,87,0.1); color: var(--accent-red); border-radius: 8px;">
                            <i class="fa-solid fa-triangle-exclamation me-2"></i>
                            <strong>Semua meja sedang terisi.</strong><br>
                            <small>Selesaikan transaksi meja yang aktif terlebih dahulu.</small>
                        </div>
                    <?php else: ?>
                        <!-- Pilih Meja -->
                        <div class="mb-3">
                            <label for="modal_id_meja" class="form-label text-muted small fw-semibold">Pilih Meja <span class="text-danger">*</span></label>
                            <select name="id_meja" id="modal_id_meja" class="form-select form-select-custom" required>
                                <option value="">-- Pilih Meja Tersedia --</option>
                                <?php foreach ($daftar_meja_kosong as $mj): ?>
                                    <option value="<?= $mj['id'] ?>">
                                        <?= htmlspecialchars($mj['nomor_meja']) ?> &nbsp;(Kapasitas: <?= $mj['kapasitas'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <style>
                        .tipe-toggle-btn {
                            border: 1px solid var(--border-color) !important;
                            color: var(--text-muted) !important;
                            background: transparent !important;
                            transition: all 0.2s ease-in-out;
                        }
                        .tipe-toggle-btn:hover {
                            color: var(--text-main) !important;
                            border-color: var(--accent-coffee) !important;
                        }
                        .btn-check:checked + .tipe-toggle-btn {
                            background: var(--gradient-primary) !important;
                            color: #fff !important;
                            border-color: transparent !important;
                            box-shadow: 0 4px 10px rgba(0, 136, 255, 0.25) !important;
                        }
                        </style>

                        <!-- Tipe Pelanggan Toggle -->
                        <div class="mb-4">
                            <label class="form-label small text-muted d-block text-center fw-semibold mb-2">Tipe Pelanggan</label>
                            <div class="d-flex justify-content-center">
                                <div class="btn-group w-100" role="group" style="max-width: 320px;">
                                    <input type="radio" class="btn-check" name="tipe_pelanggan" id="tipe_tamu_kasir" value="tamu" checked>
                                    <label class="btn btn-sm tipe-toggle-btn px-4 py-2" for="tipe_tamu_kasir" style="border-radius: 8px 0 0 8px;">
                                        <i class="fa-solid fa-user me-1.5"></i>Tamu Biasa
                                    </label>
                                    
                                    <input type="radio" class="btn-check" name="tipe_pelanggan" id="tipe_member_kasir" value="member">
                                    <label class="btn btn-sm tipe-toggle-btn px-4 py-2" for="tipe_member_kasir" style="border-radius: 0 8px 8px 0;">
                                        <i class="fa-solid fa-star me-1.5"></i>Member
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Section: Member Terdaftar (Hidden by default) -->
                        <div class="mb-3" id="sec_member_kasir" style="display: none;">
                            <label for="member_search_input_kasir" class="form-label small text-muted fw-semibold">Pencarian Member Terdaftar *</label>
                            <input type="text" id="member_search_input_kasir" class="form-control form-control-custom" placeholder="Ketik nama atau nomor HP member..." list="member_datalist_kasir" autocomplete="off">
                            <datalist id="member_datalist_kasir">
                                <?php foreach ($daftar_pelanggan as $pl): ?>
                                    <option value="<?= htmlspecialchars($pl['nama_pelanggan']) ?> (<?= htmlspecialchars($pl['telepon']) ?>)" data-id="<?= $pl['id'] ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                            <input type="hidden" name="id_pelanggan" id="modal_id_pelanggan">
                        </div>

                        <!-- Section: Tamu Biasa (Visible by default) -->
                        <div id="sec_tamu_kasir">
                            <div class="p-3 mb-3 rounded" style="background: var(--bg-tertiary); border: 1px solid var(--border-color);">
                                <div class="fw-semibold text-main small mb-2"><i class="fa-solid fa-user-pen me-1.5 text-info"></i>Data Tamu Manual</div>
                                <div class="mb-2">
                                    <label for="modal_nama_pelanggan_baru" class="form-label small text-muted">Nama Pelanggan / Tamu *</label>
                                    <input type="text" name="nama_pelanggan_baru" id="modal_nama_pelanggan_baru" class="form-control form-control-custom form-control-sm" placeholder="Nama Lengkap Tamu">
                                </div>
                                
                                <div class="form-check form-switch my-3">
                                    <input class="form-check-input" type="checkbox" name="is_member_baru" id="is_member_baru_kasir" value="1">
                                    <label class="form-check-label text-muted small fw-semibold" for="is_member_baru_kasir">Daftarkan sebagai member resmi</label>
                                </div>

                                <!-- Detail Member Baru (Hidden by default) -->
                                <div id="sec_member_detail_kasir" style="display: none; border-top: 1px dashed var(--border-color); class='pt-2 mt-2'">
                                    <div class="mb-2 pt-2">
                                        <label class="form-label small text-muted">Nomor Telepon (Opsional)</label>
                                        <input type="text" name="telepon_baru" class="form-control form-control-custom form-control-sm" placeholder="Contoh: 0812345678" pattern="[0-9]+" title="Nomor telepon hanya boleh berisi angka (0-9).">
                                    </div>
                                    <div>
                                        <label class="form-label small text-muted">Email (Opsional)</label>
                                        <input type="email" name="email_baru" class="form-control form-control-custom form-control-sm" placeholder="nama@domain.com">
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-secondary border-0 px-3" data-bs-dismiss="modal"
                            style="background: var(--bg-tertiary); color: var(--text-main); border-radius: 8px;">
                        Batal
                    </button>
                    <?php if (!empty($daftar_meja_kosong)): ?>
                        <button type="submit" class="btn border-0 px-4 fw-semibold"
                                style="background: var(--gradient-primary); color: #fff; border-radius: 8px;">
                            <i class="fa-solid fa-check me-2"></i>Buat Pesanan
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

?>
