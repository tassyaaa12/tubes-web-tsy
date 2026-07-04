<?php
// Script Setup & Seeder Otomatis untuk Sistem Manajemen Kafe
// Jalankan file ini di browser (misal: http://localhost/tubes/setup.php) untuk membuat database dan akun default.

require_once "config/koneksi.php";

$page_title = "Database & Credentials Setup";
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Database & Akun Bawaan</title>
    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="login-body">
    <div class="login-card" style="max-width: 580px;">
        <div class="text-center mb-4">
            <i class="fa-solid fa-database sidebar-logo-icon fs-1 mb-2"></i>
            <h3 class="fw-bold text-main">INISIALISASI DATABASE & AKUN</h3>
            <p class="text-muted small">Menyiapkan tabel sistem dan mendaftarkan kredensial bawaan</p>
        </div>

        <?php
        $setup_success = true;
        $logs = [];

        try {
          // Karena config/koneksi.php mencoba terhubung langsung ke db_kafe, kita buat databasenya terlebih dahulu
          // Menggunakan koneksi sementara untuk membuat database jika belum ada
          $temp_conn = new PDO(
            "mysql:host=$host;charset=utf8mb4",
            $username,
            $password,
          );
          $temp_conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

          $temp_conn->exec(
            "CREATE DATABASE IF NOT EXISTS `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
          );
          $logs[] = [
            "status" => "success",
            "msg" => "Database `$database` berhasil diverifikasi/dibuat.",
          ];

          // Re-koneksi menggunakan koneksi utama dari config
          $conn->exec("USE `$database`");

          // 1. Buat Tabel Users
          $conn->exec("CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                nama_lengkap VARCHAR(100) NOT NULL,
                role ENUM('admin', 'kasir') NOT NULL DEFAULT 'kasir',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB;");
          $logs[] = [
            "status" => "success",
            "msg" => "Tabel `users` berhasil diverifikasi.",
          ];

          // 2. Buat Tabel Kategori
          $conn->exec("CREATE TABLE IF NOT EXISTS kategori (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nama_kategori VARCHAR(100) NOT NULL,
                deskripsi TEXT
            ) ENGINE=InnoDB;");
          $logs[] = [
            "status" => "success",
            "msg" => "Tabel `kategori` berhasil diverifikasi.",
          ];

          // 3. Buat Tabel Menu
          $conn->exec("CREATE TABLE IF NOT EXISTS menu (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nama_kategori INT,
                nama_menu VARCHAR(100) NOT NULL,
                id_kategori INT,
                harga INT NOT NULL,
                status ENUM('tersedia', 'habis') NOT NULL DEFAULT 'tersedia',
                gambar VARCHAR(255),
                deskripsi TEXT,
                FOREIGN KEY (id_kategori) REFERENCES kategori(id) ON DELETE SET NULL
            ) ENGINE=InnoDB;");

          // Catatan: Jika ada typo kolom `nama_kategori` dari rancangan awal, kita pastikan kolom relasi disesuaikan.
          // Kita hapus kolom duplikat `nama_kategori` jika terbuat agar rapi
          try {
            $conn->exec("ALTER TABLE menu DROP COLUMN nama_kategori");
          } catch (Exception $e) {
            // Abaikan jika kolom tidak ada
          }
          $logs[] = [
            "status" => "success",
            "msg" => "Tabel `menu` berhasil diverifikasi.",
          ];

          // 4. Buat Tabel Meja
          $conn->exec("CREATE TABLE IF NOT EXISTS meja (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nomor_meja VARCHAR(20) NOT NULL UNIQUE,
                kapasitas INT NOT NULL,
                status ENUM('kosong', 'terisi') NOT NULL DEFAULT 'kosong'
            ) ENGINE=InnoDB;");
          $logs[] = [
            "status" => "success",
            "msg" => "Tabel `meja` berhasil diverifikasi.",
          ];

          // 5. Buat Tabel Pelanggan (membership)
          $conn->exec("CREATE TABLE IF NOT EXISTS pelanggan (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nama_pelanggan VARCHAR(100) NOT NULL,
                telepon VARCHAR(20),
                email VARCHAR(100),
                poin_saldo INT NOT NULL DEFAULT 0,
                is_member TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB;");

          // Migrasi: tambah kolom is_member jika tabel pelanggan lama belum punya
          try {
            $conn->exec("ALTER TABLE pelanggan ADD COLUMN is_member TINYINT(1) NOT NULL DEFAULT 1");
          } catch (Exception $e) { /* sudah ada, abaikan */ }

          // Migrasi: tambah kolom poin_saldo jika tabel pelanggan lama belum punya
          try {
            $conn->exec("ALTER TABLE pelanggan ADD COLUMN poin_saldo INT NOT NULL DEFAULT 0");
          } catch (Exception $e) { /* sudah ada, abaikan */ }

          // 9. Buat Tabel Poin Transaksi
          $conn->exec("CREATE TABLE IF NOT EXISTS poin_transaksi (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_pelanggan INT NOT NULL,
                id_pesanan INT NULL,
                tipe ENUM('kredit','debet') NOT NULL,
                jumlah_poin INT NOT NULL,
                keterangan VARCHAR(150),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (id_pelanggan) REFERENCES pelanggan(id) ON DELETE CASCADE,
                FOREIGN KEY (id_pesanan) REFERENCES pesanan(id) ON DELETE SET NULL
            ) ENGINE=InnoDB;");

          $logs[] = [
            "status" => "success",
            "msg" => "Tabel `pelanggan` & `poin_transaksi` berhasil diverifikasi.",
          ];

          // 6. Buat Tabel Pesanan
          $conn->exec("CREATE TABLE IF NOT EXISTS pesanan (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_meja INT,
                id_pelanggan INT,
                id_user INT,
                tanggal_pesanan TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                total_harga INT DEFAULT 0,
                status_pesanan ENUM('pending', 'memasak', 'selesai', 'batal') NOT NULL DEFAULT 'pending',
                status_pembayaran ENUM('belum_bayar', 'sudah_bayar') NOT NULL DEFAULT 'belum_bayar',
                FOREIGN KEY (id_meja) REFERENCES meja(id) ON DELETE SET NULL,
                FOREIGN KEY (id_pelanggan) REFERENCES pelanggan(id) ON DELETE SET NULL,
                FOREIGN KEY (id_user) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB;");
          $logs[] = [
            "status" => "success",
            "msg" => "Tabel `pesanan` berhasil diverifikasi.",
          ];

          // 7. Buat Tabel Detail Pesanan
          $conn->exec("CREATE TABLE IF NOT EXISTS detail_pesanan (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_pesanan INT,
                id_menu INT,
                jumlah INT NOT NULL,
                harga_satuan INT NOT NULL,
                subtotal INT NOT NULL,
                FOREIGN KEY (id_pesanan) REFERENCES pesanan(id) ON DELETE CASCADE,
                FOREIGN KEY (id_menu) REFERENCES menu(id) ON DELETE SET NULL
            ) ENGINE=InnoDB;");
          $logs[] = [
            "status" => "success",
            "msg" => "Tabel `detail_pesanan` berhasil diverifikasi.",
          ];

          // 8. Buat Tabel Transaksi
          $conn->exec("CREATE TABLE IF NOT EXISTS transaksi (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_pesanan INT,
                tanggal_transaksi TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                metode_pembayaran ENUM('tunai', 'debit', 'qris') NOT NULL,
                jumlah_bayar INT NOT NULL,
                kembalian INT NOT NULL,
                FOREIGN KEY (id_pesanan) REFERENCES pesanan(id) ON DELETE CASCADE
            ) ENGINE=InnoDB;");
          $logs[] = [
            "status" => "success",
            "msg" => "Tabel `transaksi` berhasil diverifikasi.",
          ];

          // INSERT DATA SEEDER BAWAAN --

          // A. Seed Akun Default (hanya admin dan kasir)
          $default_users = [
            [
              "admin",
              password_hash("admin123", PASSWORD_DEFAULT),
              "Administrator",
              "admin",
            ],
            [
              "kasir",
              password_hash("kasir123", PASSWORD_DEFAULT),
              "Kasir",
              "kasir",
            ],
          ];

          $stmt_u = $conn->prepare(
            "INSERT INTO users (username, password, nama_lengkap, role) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE password = VALUES(password), nama_lengkap = VALUES(nama_lengkap), role = VALUES(role)",
          );
          foreach ($default_users as $du) {
            $stmt_u->execute($du);
          }

          // Hapus user manajer jika ada dari instalasi lama
          $conn->exec(
            "DELETE FROM users WHERE username = 'manajer' OR role NOT IN ('admin', 'kasir')",
          );

          // Update ENUM jika tabel users lama masih punya kolom 'manajer'
          try {
            $conn->exec(
              "ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'kasir') NOT NULL DEFAULT 'kasir'",
            );
          } catch (Exception $e) {
            // Abaikan jika sudah benar atau ada FK constraint
          }

          $logs[] = [
            "status" => "success",
            "msg" =>
              "Data akun bawaan (admin & kasir) berhasil diperbarui/dimasukkan.",
          ];

          // B. Seed Kategori Default
          $conn->exec("INSERT INTO kategori (id, nama_kategori, deskripsi) VALUES
                (1, 'Makanan Utama', 'Hidangan berat seperti nasi goreng, pasta, dll.'),
                (2, 'Minuman Kopi', 'Varian kopi panas dan dingin dari biji kopi pilihan.'),
                (3, 'Camilan & Dessert', 'Camilan ringan seperti kentang goreng dan kue manis.'),
                (4, 'Minuman Segar', 'Jus buah, teh es, dan mocktail.')
                ON DUPLICATE KEY UPDATE id=id;");
          $logs[] = [
            "status" => "success",
            "msg" => "Data kategori bawaan berhasil dimasukkan.",
          ];

          // C. Seed Meja Default
          $conn->exec("INSERT INTO meja (id, nomor_meja, kapasitas, status) VALUES
                (1, 'Meja 01', 2, 'kosong'),
                (2, 'Meja 02', 2, 'kosong'),
                (3, 'Meja 03', 4, 'kosong'),
                (4, 'Meja 04', 4, 'kosong'),
                (5, 'Meja 05', 6, 'kosong'),
                (6, 'Meja VIP 1', 8, 'kosong')
                ON DUPLICATE KEY UPDATE id=id;");
          $logs[] = [
            "status" => "success",
            "msg" => "Data meja bawaan berhasil dimasukkan.",
          ];

          // D. Seed Member Default (no "Pelanggan Umum")
          $conn->exec("INSERT INTO pelanggan (id, nama_pelanggan, telepon, email, poin_saldo, is_member) VALUES
                (1, 'Budi Santoso', '081234567890', 'budi@gmail.com', 120, 1),
                (2, 'Siti Rahma', '085712345678', 'siti@gmail.com', 45, 1)
                ON DUPLICATE KEY UPDATE id=id;");
          // Hapus 'Pelanggan Umum' jika masih ada dari instalasi lama
          $conn->exec("DELETE FROM pelanggan WHERE nama_pelanggan = 'Pelanggan Umum' AND telepon = '-'");
          $logs[] = [
            "status" => "success",
            "msg" => "Data member bawaan berhasil dimasukkan (sistem membership aktif).",
          ];

          // E. Seed Menu Default
          $conn->exec("INSERT INTO menu (id, nama_menu, id_kategori, harga, status, gambar, deskripsi) VALUES
                (1, 'Nasi Goreng Spesial', 1, 28000, 'tersedia', 'nasigoreng.jpg', 'Nasi goreng dengan bumbu khas, telur mata sapi, ayam suwir, dan kerupuk.'),
                (2, 'Chicken Cordon Bleu', 1, 45000, 'tersedia', 'chicken_cordon_bleu.jpg', 'Dada ayam gulung isi keju mozarella dan daging asap disajikan dengan saus BBQ.'),
                (3, 'Es Kopi Susu Gula Aren', 2, 18000, 'tersedia', 'eskopisusu.jpg', 'Espresso dicampur susu segar dan sirup gula aren murni.'),
                (4, 'Cappuccino Hot', 2, 22000, 'tersedia', 'cappuccino.jpg', 'Espresso klasik dengan foam susu tebal dan taburan bubuk cokelat.'),
                (5, 'French Fries', 3, 15000, 'tersedia', 'frenchfries.jpg', 'Kentang goreng renyah yang ditaburi garam dan disajikan dengan saus sambal.'),
                (6, 'Croissant Almond', 3, 20000, 'tersedia', 'croissant.jpg', 'Roti mentega renyah dengan isian dan taburan kacang almond.'),
                (7, 'Lychee Ice Tea', 4, 16000, 'tersedia', 'lycheetea.jpg', 'Teh es manis dengan aroma buah leci segar dan tambahan buah leci asli.')
                ON DUPLICATE KEY UPDATE id=id;");
          $logs[] = [
            "status" => "success",
            "msg" => "Data menu bawaan berhasil dimasukkan.",
          ];
        } catch (PDOException $e) {
          $setup_success = false;
          $logs[] = [
            "status" => "danger",
            "msg" => "Gagal inisialisasi database: " . $e->getMessage(),
          ];
        }
        ?>

        <!-- Log Tampilan Setup -->
        <div class="mb-4 text-start" style="max-height: 200px; overflow-y: auto; background-color: var(--bg-primary); padding: 15px; border-radius: var(--border-radius); border: 1px solid var(--border-color);">
            <div class="text-muted small fw-bold mb-2">LOG PROSES SETUP:</div>
            <?php foreach ($logs as $log): ?>
                <div class="small text-<?= $log["status"] === "success"
                  ? "success"
                  : "danger" ?> mb-1">
                    <i class="fa-solid <?= $log["status"] === "success"
                      ? "fa-circle-check"
                      : "fa-triangle-exclamation" ?> me-2"></i><?= htmlspecialchars(
   $log["msg"],
 ) ?>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($setup_success): ?>
            <div class="alert alert-success border-0 text-center py-3 mb-4" role="alert" style="background-color: rgba(0, 230, 118, 0.1); color: var(--accent-green); border-radius: 8px;">
                <i class="fa-solid fa-circle-check fa-lg me-2"></i>Inisialisasi Database Sukses! Kredensial siap digunakan.
            </div>

            <!-- Tabel Kredensial -->
            <div class="table-responsive mb-4 text-start">
                <table class="table table-bordered border-light-subtle rounded overflow-hidden" style="color: var(--text-main);">
                    <thead>
                        <tr style="background-color: var(--bg-tertiary); color: var(--text-main);">
                            <th>Nama Pengguna</th>
                            <th>Password</th>
                            <th>Hak Akses (Role)</th>
                            <th>Akses Fitur</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code class="text-dark font-monospace fw-bold">admin</code></td>
                            <td><code class="text-muted font-monospace">admin123</code></td>
                            <td><span class="badge bg-danger">Administrator</span></td>
                            <td><small class="text-muted">Dashboard, Kategori, Menu, Meja, Pelanggan, Pesanan, Kasir/POS, Laporan</small></td>
                        </tr>
                        <tr>
                            <td><code class="text-dark font-monospace fw-bold">kasir</code></td>
                            <td><code class="text-muted font-monospace">kasir123</code></td>
                            <td><span class="badge bg-success">Kasir POS</span></td>
                            <td><small class="text-muted">Dashboard, Meja, Pelanggan, Pesanan, Kasir/POS</small></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <a href="auth/login.php" class="btn w-100 py-2.5 fw-semibold" style="background: var(--gradient-primary); color: #fff; border-radius: var(--border-radius); border: none; box-shadow: 0 4px 15px rgba(0, 136, 255, 0.3);">
                <i class="fa-solid fa-right-to-bracket me-2"></i>Buka Halaman Login
            </a>
        <?php else: ?>
            <div class="alert alert-danger border-0 text-center py-3 mb-4" role="alert" style="background-color: rgba(255, 51, 66, 0.1); color: var(--accent-red); border-radius: 8px;">
                <i class="fa-solid fa-circle-xmark fa-lg me-2"></i>Setup Gagal. Periksa konfigurasi MySQL Anda.
            </div>

            <p class="text-muted small text-center mb-3">Pastikan MySQL server (XAMPP / Laragon) aktif dan port database disesuaikan pada file <code>config/koneksi.php</code>.</p>

            <button onclick="location.reload()" class="btn btn-outline-info w-100 py-2.5" style="border-radius: var(--border-radius);">
                <i class="fa-solid fa-rotate me-2"></i>Coba Ulang
            </button>
        <?php endif; ?>

        <div class="text-center mt-4 text-muted small">
            <span>&copy; <?= date("Y") ?> Sistem Kasir & Manajemen Kafe</span>
        </div>
    </div>
</body>
</html>
