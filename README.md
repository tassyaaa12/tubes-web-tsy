# Sistem Manajemen Kafe (Point of Sale & Membership)

Sistem Web POS dan Manajemen Kafe modern yang dirancang untuk kasir dan administrator guna mengelola meja, menu, pesanan, transaksi pembayaran, serta program loyalitas pelanggan (member & poin).

## 🚀 Fitur Utama
1. **Point of Sale (POS) / Kasir (`kasir.php`)**
   - Pencatatan pesanan berdasarkan meja yang tersedia.
   - Sistem keranjang belanja interaktif (tambah, kurangi, hapus item).
   - Pembayaran dengan kalkulasi otomatis (total belanja, potongan poin, jumlah bayar, kembalian).
   - Cetak struk belanja langsung (Print-friendly format).

2. **Buka Pesanan Baru (`pesanan.php` & `kasir.php`)**
   - Toggle Tipe Pelanggan: **Tamu Biasa** atau **Member Terdaftar**.
   - **Searchable Dropdown Member:** Pencarian member terdaftar secara interaktif menggunakan nama atau nomor HP secara langsung.
   - **Pendaftaran Member Baru Instan:** Opsi mendaftarkan tamu biasa sebagai member baru langsung saat checkout/buka pesanan.

3. **Program loyalitas Pelanggan (`pelanggan.php`)**
   - Perhitungan poin otomatis (belanja kelipatan Rp 10.000 mendapatkan 1 poin).
   - Penukaran poin sebagai diskon pembayaran (1 poin bernilai potongan Rp 100).
   - Log audit poin member (transaksi kredit/debet poin, detail riwayat belanja).
   - Validasi ketat nomor telepon (wajib berupa angka/integer dan tidak boleh kembar antar member resmi).

4. **Manajemen Meja (`meja.php`)**
   - Status meja dinamis (**Kosong** / **Terisi**).
   - Pembatasan pembukaan pesanan baru jika meja sedang terisi.

5. **Manajemen Menu & Kategori (`menu.php` & `kategori.php`)**
   - Pengelolaan katalog makanan/minuman beserta gambar, kategori, harga, dan ketersediaan stok.

6. **Laporan Keuangan & Audit (`laporan.php`)**
   - Rekap omzet, total transaksi, total member terdaftar, serta tabel rincian transaksi harian.

7. **Desain Responsif (Mobile Friendly)**
   - Tampilan antarmuka yang dapat menyesuaikan secara dinamis untuk perangkat *mobile* dan *tablet*.
   - *Sidebar* navigasi model *off-canvas* dengan tombol *hamburger menu*.
   - Tabel data yang dapat di-*scroll* secara horizontal (*overflow-x: auto*) di layar kecil untuk mencegah *layout* berantakan.

---

## 🔒 Manajemen Keamanan: Session & Cookie

Aplikasi ini mengimplementasikan sistem durasi sesi adaptif yang ketat untuk melindungi data penting kasir/admin saat meninggalkan komputer:

### 1. Batas Kedaluwarsa Sesi (Session Expiry)
* **Aturan Utama:** Sesi kasir/admin akan kedaluwarsa dan terhapus secara otomatis di server **60 detik** setelah seluruh tab browser yang membuka sistem ditutup.
* **Cara Kerja:** Cookie `PHPSESSID` diatur agar berakhir saat browser ditutup (`lifetime = 0`), namun di sisi *server*, PHP mengecek waktu aktivitas terakhir (*last heartbeat*).

### 2. Mekanisme Heartbeat (Menjaga Sesi Aktif)
* **Masalah:** Tanpa deteksi aktivitas, server tidak dapat mengetahui secara pasti kapan pengguna menutup *tab* browser.
* **Solusi:** Di latar belakang, script Javascript (`assets/js/main.js`) akan mengirimkan sinyal ping AJAX ke file (`heartbeat.php`) setiap **30 detik** sekali.
* Ping ini memberitahu server untuk memperbarui *timestamp* sesi agar terhindar dari batas *timeout* 60 detik.
* **Hasil Akhir:** Sesi tetap aktif secara normal selama setidaknya satu tab web terbuka di browser. Namun, ketika tab ditutup, ping terhenti, dan sesi akan terhapus secara aman dan otomatis setelah batas **60 detik** berlalu (mengurangi beban server drastis dibandingkan konfigurasi lama 5 detik).

### 3. Cookie "Ingat Saya" (Remember Me Cookie)
* **Nama Cookie:** `kafe_remember`
* **Masa Aktif:** 7 hari.
* **Keamanan:** Cookie ini ditandatangani (signed) menggunakan algoritma **HMAC SHA-256** rahasia untuk mencegah pemalsuan identitas (cookie tampering).
* **Fungsi:** Jika kasir mencentang opsi **"Ingat Saya"** saat login, status login mereka akan pulih otomatis meskipun sesi 5 detik telah kedaluwarsa.

---

## ⚙️ Cara Menjalankan Project
1. Clone / salin folder project ke direktori server lokal Anda (misalnya `htdocs` pada XAMPP).
2. Salin berkas `.env.example` menjadi `.env` di folder root:
   ```bash
   cp .env.example .env
   ```
3. Konfigurasikan kredensial database Anda pada berkas `.env`. 
   - **Lokal (MySQL):** Gunakan driver `mysql` (port bawaan `3306`).
   - **Supabase (PostgreSQL):** Ubah `DB_CONNECTION` menjadi `pgsql`, sesuaikan host (misalnya `aws-0-ap-southeast-1.pooler.supabase.com`), port (`6543`), database, username, password, dan aktifkan `DB_SSLMODE=require`.
4. Nyalakan layanan **Apache** dan **MySQL/PostgreSQL** sesuai dengan database yang dipilih.
5. Import database:
   - Jalankan script SQL pada berkas `database.sql` atau akses script migrasi instan via `setup.php` di browser.
6. Akses sistem melalui alamat: `http://localhost/tubes-web/`
