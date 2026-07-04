-- Database Setup for Cafe Management System
-- Database name: db_kafe

CREATE DATABASE IF NOT EXISTS db_kafe;
USE db_kafe;

-- 1. Table: users
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    nama_lengkap VARCHAR(100) NOT NULL,
    role ENUM('admin', 'kasir') NOT NULL DEFAULT 'kasir',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. Table: kategori
CREATE TABLE IF NOT EXISTS kategori (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama_kategori VARCHAR(100) NOT NULL,
    deskripsi TEXT
);

-- 3. Table: menu
CREATE TABLE IF NOT EXISTS menu (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama_menu VARCHAR(100) NOT NULL,
    id_kategori INT,
    harga INT NOT NULL,
    status ENUM('tersedia', 'habis') NOT NULL DEFAULT 'tersedia',
    gambar VARCHAR(255),
    deskripsi TEXT,
    FOREIGN KEY (id_kategori) REFERENCES kategori(id) ON DELETE SET NULL
);

-- 4. Table: meja
CREATE TABLE IF NOT EXISTS meja (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nomor_meja VARCHAR(20) NOT NULL UNIQUE,
    kapasitas INT NOT NULL,
    status ENUM('kosong', 'terisi') NOT NULL DEFAULT 'kosong'
);

-- 5. Table: pelanggan (membership)
CREATE TABLE IF NOT EXISTS pelanggan (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama_pelanggan VARCHAR(100) NOT NULL,
    telepon VARCHAR(20),
    email VARCHAR(100),
    poin_saldo INT NOT NULL DEFAULT 0,
    is_member TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 9. Table: poin_transaksi (log poin member)
CREATE TABLE IF NOT EXISTS poin_transaksi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_pelanggan INT NOT NULL,
    id_pesanan INT NULL,
    tipe ENUM('kredit','debet') NOT NULL,
    jumlah_poin INT NOT NULL,
    keterangan VARCHAR(150),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_pelanggan) REFERENCES pelanggan(id) ON DELETE CASCADE,
    FOREIGN KEY (id_pesanan) REFERENCES pesanan(id) ON DELETE SET NULL
);

-- 6. Table: pesanan
CREATE TABLE IF NOT EXISTS pesanan (
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
);

-- 7. Table: detail_pesanan
CREATE TABLE IF NOT EXISTS detail_pesanan (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_pesanan INT,
    id_menu INT,
    jumlah INT NOT NULL,
    harga_satuan INT NOT NULL,
    subtotal INT NOT NULL,
    FOREIGN KEY (id_pesanan) REFERENCES pesanan(id) ON DELETE CASCADE,
    FOREIGN KEY (id_menu) REFERENCES menu(id) ON DELETE SET NULL
);

-- 8. Table: transaksi
CREATE TABLE IF NOT EXISTS transaksi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_pesanan INT,
    tanggal_transaksi TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    metode_pembayaran ENUM('tunai', 'debit', 'qris') NOT NULL,
    jumlah_bayar INT NOT NULL,
    kembalian INT NOT NULL,
    FOREIGN KEY (id_pesanan) REFERENCES pesanan(id) ON DELETE CASCADE
);

-- Seed Default Data --

-- Insert Default Users (hanya 2 user: admin dan kasir)
-- password 'admin123' untuk admin, 'kasir123' untuk kasir
-- Hashes generated via password_hash('password', PASSWORD_DEFAULT)
INSERT INTO users (username, password, nama_lengkap, role) VALUES
('admin', '$2y$10$Ue9N0pA37M64VjK/BvXp.O0oVzLqV0vA5TCO5s30Dq2.i1C1u.o.2', 'Administrator', 'admin'),
('kasir', '$2y$10$oYVp1XJ5U2x2g09H9Q7m4uzw6kCgS0eLve4yYyUv1tXfQ8.vY5B3S', 'Kasir', 'kasir')
ON DUPLICATE KEY UPDATE
    password = VALUES(password),
    nama_lengkap = VALUES(nama_lengkap),
    role = VALUES(role);

-- Hapus user manajer jika ada dari instalasi sebelumnya
DELETE FROM users WHERE username = 'manajer' OR role NOT IN ('admin', 'kasir');

-- Insert Default Kategori
INSERT INTO kategori (id, nama_kategori, deskripsi) VALUES
(1, 'Makanan Utama', 'Hidangan berat seperti nasi goreng, pasta, dll.'),
(2, 'Minuman Kopi', 'Varian kopi panas dan dingin dari biji kopi pilihan.'),
(3, 'Camilan & Dessert', 'Camilan ringan seperti kentang goreng dan kue manis.'),
(4, 'Minuman Segar', 'Jus buah, teh es, dan mocktail.')
ON DUPLICATE KEY UPDATE id=id;

-- Insert Default Meja
INSERT INTO meja (id, nomor_meja, kapasitas, status) VALUES
(1, 'Meja 01', 2, 'kosong'),
(2, 'Meja 02', 2, 'kosong'),
(3, 'Meja 03', 4, 'kosong'),
(4, 'Meja 04', 4, 'kosong'),
(5, 'Meja 05', 6, 'kosong'),
(6, 'Meja VIP 1', 8, 'kosong')
ON DUPLICATE KEY UPDATE id=id;

-- Insert Default Pelanggan (member system - no "Pelanggan Umum")
INSERT INTO pelanggan (id, nama_pelanggan, telepon, email, poin_saldo, is_member) VALUES
(1, 'Budi Santoso', '081234567890', 'budi@gmail.com', 120, 1),
(2, 'Siti Rahma', '085712345678', 'siti@gmail.com', 45, 1)
ON DUPLICATE KEY UPDATE id=id;

-- Insert Default Menu
INSERT INTO menu (id, nama_menu, id_kategori, harga, status, gambar, deskripsi) VALUES
(1, 'Nasi Goreng Spesial', 1, 28000, 'tersedia', 'nasigoreng.jpg', 'Nasi goreng dengan bumbu khas, telur mata sapi, ayam suwir, dan kerupuk.'),
(2, 'Chicken Cordon Bleu', 1, 45000, 'tersedia', 'chicken_cordon_bleu.jpg', 'Dada ayam gulung isi keju mozarella dan daging asap disajikan dengan saus BBQ.'),
(3, 'Es Kopi Susu Gula Aren', 2, 18000, 'tersedia', 'eskopisusu.jpg', 'Espresso dicampur susu segar dan sirup gula aren murni.'),
(4, 'Cappuccino Hot', 2, 22000, 'tersedia', 'cappuccino.jpg', 'Espresso klasik dengan foam susu tebal dan taburan bubuk cokelat.'),
(5, 'French Fries', 3, 15000, 'tersedia', 'frenchfries.jpg', 'Kentang goreng renyah yang ditaburi garam dan disajikan dengan saus sambal.'),
(6, 'Croissant Almond', 3, 20000, 'tersedia', 'croissant.jpg', 'Roti mentega renyah dengan isian dan taburan kacang almond.'),
(7, 'Lychee Ice Tea', 4, 16000, 'tersedia', 'lycheetea.jpg', 'Teh es manis dengan aroma buah leci segar dan tambahan buah leci asli.')
ON DUPLICATE KEY UPDATE id=id;
