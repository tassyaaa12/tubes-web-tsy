<?php
$page_title = "Manajemen Pesanan";
$page_desc = "Pantau status pesanan dapur dan daftarkan pesanan meja baru.";

require_once "config/koneksi.php";
require_once "config/auth.php";
require_auth(); // Admin dan kasir boleh akses

$message = "";
$message_type = "success";

// Handle Buat Pesanan Baru
if (
  $_SERVER["REQUEST_METHOD"] === "POST" &&
  isset($_POST["action"]) &&
  $_POST["action"] === "buat_pesanan"
) {
  $id_meja = intval($_POST["id_meja"] ?? 0);
  $id_pelanggan = !empty($_POST["id_pelanggan"])
    ? intval($_POST["id_pelanggan"])
    : null;
  $nama_pelanggan_baru = trim($_POST["nama_pelanggan_baru"] ?? "");
  $telepon_baru = trim($_POST["telepon_baru"] ?? "");
  $email_baru = trim($_POST["email_baru"] ?? "");
  $id_user = $_SESSION["user_id"] ?? 1;

  $tipe_pelanggan = $_POST["tipe_pelanggan"] ?? "tamu";
  if ($tipe_pelanggan === "member") {
    $nama_pelanggan_baru = "";
  } else {
    $id_pelanggan = null;
  }

  if ($id_meja > 0) {
    try {
      $conn->beginTransaction();

      // Cek apakah meja sedang digunakan
      $stmt_check = $conn->prepare("SELECT status FROM meja WHERE id = :id");
      $stmt_check->execute(["id" => $id_meja]);
      $meja_status = $stmt_check->fetchColumn();

      if ($meja_status === "terisi") {
        throw new Exception(
          "Meja tersebut sedang terisi! Pilih meja lain atau selesaikan transaksi meja tersebut.",
        );
      }

      // Jika kasir input nama pelanggan baru
      if (!empty($nama_pelanggan_baru)) {
        $is_member_baru = isset($_POST["is_member_baru"]) ? 1 : 0;

        // Validasi nomor telepon integer (hanya angka) jika didaftarkan sebagai member
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
        $stmt_member = $conn->prepare(
          "SELECT id FROM pelanggan WHERE nama_pelanggan = :nama OR (telepon = :telp AND telepon != '-') LIMIT 1",
        );
        $stmt_member->execute([
          "nama" => $nama_pelanggan_baru,
          "telp" => $telepon_baru ?: "n/a",
        ]);
        $existing_id = $stmt_member->fetchColumn();

        if ($existing_id) {
          $id_pelanggan = $existing_id;
        } else {
          // Daftarkan sebagai member atau hanya tamu manual biasa
          $stmt_ins = $conn->prepare(
            "INSERT INTO pelanggan (nama_pelanggan, telepon, email, poin_saldo, is_member) VALUES (:nama, :telp, :email, 0, :is_member)",
          );
          $stmt_ins->execute([
            "nama" => $nama_pelanggan_baru,
            "telp" => $telepon_baru ?: "-",
            "email" => $email_baru ?: "-",
            "is_member" => $is_member_baru,
          ]);
          $id_pelanggan = $conn->lastInsertId();
        }
      }

      // 1. Buat pesanan baru
      $stmt_order = $conn->prepare(
        "INSERT INTO pesanan (id_meja, id_pelanggan, id_user, status_pesanan, status_pembayaran) VALUES (:id_meja, :id_pelanggan, :id_user, 'pending', 'belum_bayar')",
      );
      $stmt_order->execute([
        "id_meja" => $id_meja,
        "id_pelanggan" => $id_pelanggan,
        "id_user" => $id_user,
      ]);
      $id_pesanan_baru = $conn->lastInsertId();

      // 2. Ubah status meja menjadi terisi
      $stmt_meja = $conn->prepare(
        "UPDATE meja SET status = 'terisi' WHERE id = :id",
      );
      $stmt_meja->execute(["id" => $id_meja]);

      $conn->commit();

      // Redirect ke halaman kasir untuk menambahkan menu makanan/minuman
      header("Location: kasir.php?id_pesanan=" . $id_pesanan_baru);
      exit();
    } catch (Exception $e) {
      $conn->rollBack();
      $message = "Gagal membuat pesanan: " . $e->getMessage();
      $message_type = "danger";
    }
  } else {
    $message = "Silakan pilih meja terlebih dahulu.";
    $message_type = "danger";
  }
}

// Handle Update Status Masakan
if (
  $_SERVER["REQUEST_METHOD"] === "POST" &&
  isset($_POST["action"]) &&
  $_POST["action"] === "update_status"
) {
  $id_pesanan = intval($_POST["id_pesanan"] ?? 0);
  $status_pesanan = $_POST["status_pesanan"] ?? "pending";

  if ($id_pesanan > 0) {
    try {
      $stmt = $conn->prepare(
        "UPDATE pesanan SET status_pesanan = :status_pesanan WHERE id = :id",
      );
      $stmt->execute([
        "status_pesanan" => $status_pesanan,
        "id" => $id_pesanan,
      ]);
      $message =
        "Status pesanan berhasil diperbarui menjadi " .
        strtoupper($status_pesanan) .
        "!";
      $message_type = "success";
    } catch (PDOException $e) {
      $message = "Gagal memperbarui status pesanan: " . $e->getMessage();
      $message_type = "danger";
    }
  }
}

// Handle Hapus Pesanan
if (isset($_GET["action"]) && $_GET["action"] === "hapus") {
  $id = intval($_GET["id"] ?? 0);
  if ($id > 0) {
    try {
      $conn->beginTransaction();

      // Ambil id meja pesanan tersebut
      $stmt_info = $conn->prepare(
        "SELECT id_meja, status_pembayaran FROM pesanan WHERE id = :id",
      );
      $stmt_info->execute(["id" => $id]);
      $info = $stmt_info->fetch();

      if ($info) {
        // Hapus pesanan hanya jika belum dibayar
        if ($info["status_pembayaran"] === "belum_bayar") {
          $stmt_del = $conn->prepare("DELETE FROM pesanan WHERE id = :id");
          $stmt_del->execute(["id" => $id]);

          // Bebaskan status meja
          if (!empty($info["id_meja"])) {
            $stmt_meja = $conn->prepare(
              "UPDATE meja SET status = 'kosong' WHERE id = :id_meja",
            );
            $stmt_meja->execute(["id_meja" => $info["id_meja"]]);
          }
          $message = "Pesanan berhasil dihapus dan meja dibebaskan!";
          $message_type = "success";
        } else {
          $message = "Tidak dapat menghapus pesanan yang sudah dibayar!";
          $message_type = "danger";
        }
      }

      $conn->commit();
    } catch (PDOException $e) {
      $conn->rollBack();
      $message = "Gagal menghapus pesanan: " . $e->getMessage();
      $message_type = "danger";
    }
  }
}

// Ambil semua pesanan aktif (belum lunas ATAU status belum selesai)
$stmt_aktif = $conn->query("
    SELECT p.*, m.nomor_meja, pl.nama_pelanggan, u.nama_lengkap as nama_kasir
    FROM pesanan p
    LEFT JOIN meja m ON p.id_meja = m.id
    LEFT JOIN pelanggan pl ON p.id_pelanggan = pl.id
    LEFT JOIN users u ON p.id_user = u.id
    WHERE p.status_pembayaran = 'belum_bayar' OR p.status_pesanan != 'selesai'
    ORDER BY p.tanggal_pesanan DESC
");
$pesanan_aktif = $stmt_aktif->fetchAll();

// Ambil daftar meja kosong untuk modal buat pesanan baru
$meja_kosong = $conn
  ->query("SELECT * FROM meja WHERE status = 'kosong' ORDER BY nomor_meja ASC")
  ->fetchAll();

// Ambil daftar pelanggan (hanya member resmi) untuk modal buat pesanan baru
$pelanggan_list = $conn
  ->query(
    "SELECT * FROM pelanggan WHERE is_member = 1 ORDER BY nama_pelanggan ASC",
  )
  ->fetchAll();

include "includes/header.php";
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
        <span><i class="fa-solid fa-bell me-2 text-warning animate-pulse"></i>Antrean Pesanan Aktif</span>
        <button type="button" class="btn btn-sm btn-primary border-0" data-bs-toggle="modal" data-bs-target="#modalTambahPesanan" style="background: var(--gradient-primary); border-radius: 8px;">
            <i class="fa-solid fa-plus me-1"></i> Buat Pesanan Baru
        </button>
    </div>

    <!-- Tabel Pesanan Aktif -->
    <div class="table-responsive">
        <table class="custom-table">
            <thead>
                <tr>
                    <th>Waktu</th>
                    <th>Meja</th>
                    <th>Pelanggan</th>
                    <th>Total Item / Menu Detail</th>
                    <th>Tagihan</th>
                    <th>Status Pembayaran</th>
                    <th>Status Masakan</th>
                    <th style="width: 180px; text-align: center;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($pesanan_aktif) > 0): ?>
                    <?php foreach ($pesanan_aktif as $p):

                      // Ambil detail items menu untuk pesanan ini
                      $stmt_details = $conn->prepare("
                            SELECT dp.*, m.nama_menu
                            FROM detail_pesanan dp
                            JOIN menu m ON dp.id_menu = m.id
                            WHERE dp.id_pesanan = :id_pesanan
                        ");
                      $stmt_details->execute(["id_pesanan" => $p["id"]]);
                      $details = $stmt_details->fetchAll();
                      ?>
                        <tr>
                            <td class="text-muted small"><?= date(
                              "H:i",
                              strtotime($p["tanggal_pesanan"]),
                            ) ?><br><span style="font-size: 10px;"><?= date(
  "d/m/y",
  strtotime($p["tanggal_pesanan"]),
) ?></span></td>
                            <td class="fw-semibold"><?= htmlspecialchars(
                              $p["nomor_meja"] ?? "Takeaway",
                            ) ?></td>
                            <td><?= htmlspecialchars(
                              $p["nama_pelanggan"] ?? "Umum",
                            ) ?></td>
                            <td>
                                <div class="small">
                                    <?php if (count($details) > 0): ?>
                                        <ul class="list-unstyled mb-0 text-muted">
                                            <?php foreach ($details as $d): ?>
                                                <li><i class="fa-solid fa-caret-right me-1 text-info"></i><?= $d[
                                                  "jumlah"
                                                ] ?>x <?= htmlspecialchars(
  $d["nama_menu"],
) ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php else: ?>
                                        <span class="text-warning small italic"><i class="fa-solid fa-triangle-exclamation"></i> Belum ada menu dipilih</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="text-info fw-bold">Rp <?= number_format(
                              $p["total_harga"],
                              0,
                              ",",
                              ".",
                            ) ?></td>
                            <td>
                                <span class="badge-custom <?= $p[
                                  "status_pembayaran"
                                ] == "sudah_bayar"
                                  ? "badge-done"
                                  : "badge-cancel" ?>">
                                    <i class="fa-solid <?= $p[
                                      "status_pembayaran"
                                    ] == "sudah_bayar"
                                      ? "fa-check-double"
                                      : "fa-clock" ?>"></i>
                                    <?= $p["status_pembayaran"] == "sudah_bayar"
                                      ? "Lunas"
                                      : "Belum Lunas" ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge-custom badge-<?= $p[
                                  "status_pesanan"
                                ] ?>">
                                    <i class="fa-solid <?= $p[
                                      "status_pesanan"
                                    ] == "pending"
                                      ? "fa-hourglass-start"
                                      : ($p["status_pesanan"] == "memasak"
                                        ? "fa-fire"
                                        : "fa-circle-check") ?>"></i>
                                    <?= htmlspecialchars(
                                      $p["status_pesanan"],
                                    ) ?>
                                </span>
                            </td>
                            <td style="text-align: center;">
                                <div class="d-flex justify-content-center gap-1">
                                    <!-- Kelola items di kasir -->
                                    <a href="kasir.php?id_pesanan=<?= $p[
                                      "id"
                                    ] ?>" class="btn btn-sm btn-outline-info border-0" title="Kelola / Bayar Menu Pesanan">
                                        <i class="fa-solid fa-cash-register"></i>
                                    </a>

                                    <!-- Tombol Ubah Status Masakan -->
                                    <button class="btn btn-sm btn-outline-warning border-0 btn-status"
                                            data-id="<?= $p["id"] ?>"
                                            data-status="<?= $p[
                                              "status_pesanan"
                                            ] ?>"
                                            data-bs-toggle="modal"
                                            data-bs-target="#modalStatus">
                                        <i class="fa-solid fa-utensils"></i>
                                    </button>

                                    <!-- Hapus Pesanan (Hanya jika belum bayar) -->
                                    <?php if (
                                      $p["status_pembayaran"] === "belum_bayar"
                                    ): ?>
                                        <a href="pesanan.php?action=hapus&id=<?= $p[
                                          "id"
                                        ] ?>"
                                           class="btn btn-sm btn-outline-danger border-0"
                                           onclick="return confirm('Apakah Anda yakin ingin membatalkan & menghapus pesanan ini? Meja akan dibebaskan kembali.');" title="Batalkan Pesanan">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php
                    endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">Belum ada pesanan aktif saat ini.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Buat Pesanan Baru -->
<div class="modal fade" id="modalTambahPesanan" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content modal-content-custom">
            <div class="modal-header modal-header-custom">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-plus text-primary me-2"></i>Buka Pesanan Baru</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="pesanan.php" method="POST" id="formTambahPesanan">
                <input type="hidden" name="action" value="buat_pesanan">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="id_meja" class="form-label small text-muted">Pilih Meja *</label>
                        <select name="id_meja" id="id_meja" class="form-select form-select-custom" required>
                            <option value="">-- Pilih Meja Kosong --</option>
                            <?php foreach ($meja_kosong as $meja): ?>
                                <option value="<?= $meja[
                                  "id"
                                ] ?>"><?= htmlspecialchars(
  $meja["nomor_meja"],
) ?> (Kapasitas: <?= $meja["kapasitas"] ?> Orang)</option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text text-muted-50 small">Meja terisi tidak akan muncul dalam pilihan.</div>
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
                    .member-dropdown-opt {
                        background: transparent;
                        border: 0;
                        width: 100%;
                        text-align: left;
                        transition: background-color 0.15s;
                    }
                    .member-dropdown-opt:hover {
                        background-color: var(--bg-tertiary) !important;
                    }
                    </style>

                    <!-- Tipe Pelanggan Toggle -->
                    <div class="mb-4">
                        <label class="form-label small text-muted d-block text-center fw-semibold mb-2">Tipe Pelanggan</label>
                        <div class="d-flex justify-content-center">
                            <div class="btn-group w-100" role="group" style="max-width: 320px;">
                                <input type="radio" class="btn-check" name="tipe_pelanggan" id="tipe_tamu" value="tamu" checked>
                                <label class="btn btn-sm tipe-toggle-btn px-4 py-2" for="tipe_tamu" style="border-radius: 8px 0 0 8px;">
                                    <i class="fa-solid fa-user me-1.5"></i>Tamu Biasa
                                </label>

                                <input type="radio" class="btn-check" name="tipe_pelanggan" id="tipe_member" value="member">
                                <label class="btn btn-sm tipe-toggle-btn px-4 py-2" for="tipe_member" style="border-radius: 0 8px 8px 0;">
                                    <i class="fa-solid fa-star me-1.5"></i>Member
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Section: Member Terdaftar (Hidden by default) -->
                    <div class="mb-3" id="sec_member" style="display: none;">
                        <label for="member_search_input" class="form-label small text-muted fw-semibold">Pencarian Member Terdaftar *</label>
                        <div class="position-relative">
                            <input type="text" id="member_search_input" class="form-control form-control-custom" placeholder="Ketik nama atau nomor HP member..." autocomplete="off">
                            <input type="hidden" name="id_pelanggan" id="id_pelanggan">
                            <div id="member_dropdown_list" class="dropdown-menu w-100" style="display: none; max-height: 200px; overflow-y: auto; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 8px; position: absolute; top: 100%; left: 0; z-index: 1050; padding: 4px 0;">
                                <div class="dropdown-item text-muted small py-2 px-3" id="member_no_result" style="display: none;">Member tidak ditemukan</div>
                                <?php foreach ($pelanggan_list as $pl): ?>
                                    <button type="button" class="dropdown-item text-main small py-2 px-3 member-dropdown-opt btn-member-opt" data-id="<?= $pl['id'] ?>" data-search="<?= strtolower($pl['nama_pelanggan'] . ' ' . $pl['telepon']) ?>">
                                        <div class="fw-semibold text-main"><?= htmlspecialchars($pl['nama_pelanggan']) ?></div>
                                        <div class="text-muted-50" style="font-size: 0.75rem;"><i class="fa-solid fa-phone me-1"></i><?= htmlspecialchars($pl['telepon']) ?> | Poin: <?= $pl['poin_saldo'] ?></div>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Section: Tamu Biasa (Visible by default) -->
                    <div id="sec_tamu">
                        <div class="p-3 mb-3 rounded" style="background: var(--bg-tertiary); border: 1px solid var(--border-color);">
                            <div class="fw-semibold text-main small mb-2">Data Tamu Manual</div>
                            <div class="mb-2">
                                <label for="nama_pelanggan_baru" class="form-label small text-muted">Nama Pelanggan / Tamu *</label>
                                <input type="text" name="nama_pelanggan_baru" id="nama_pelanggan_baru" class="form-control form-control-custom form-control-sm" placeholder="Nama Lengkap Tamu">
                            </div>

                            <div class="form-check form-switch my-3">
                                <input class="form-check-input" type="checkbox" name="is_member_baru" id="is_member_baru" value="1">
                                <label class="form-check-label text-muted small fw-semibold" for="is_member_baru">Daftarkan sebagai member resmi</label>
                            </div>

                            <!-- Detail Member Baru (Hidden by default) -->
                            <div id="sec_member_detail" style="display: none; border-top: 1px dashed var(--border-color); class='pt-2 mt-2'">
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
                </div>
                <div class="modal-footer modal-footer-custom">
                    <button type="button" class="btn btn-secondary border-0" data-bs-dismiss="modal" style="border-radius: 8px;">Batal</button>
                    <button type="submit" class="btn btn-primary border-0" style="background: var(--gradient-primary); border-radius: 8px;">Buka & Input Menu</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Ubah Status Dapur -->
<div class="modal fade" id="modalStatus" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content modal-content-custom">
            <div class="modal-header modal-header-custom">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-circle-question text-warning me-2"></i>Status Masakan</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="pesanan.php" method="POST">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="id_pesanan" id="status_id_pesanan">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="status_pesanan" class="form-label small text-muted">Ubah Status Dapur</label>
                        <select name="status_pesanan" id="status_pesanan" class="form-select form-select-custom">
                            <option value="pending">Pending</option>
                            <option value="memasak">Memasak</option>
                            <option value="selesai">Selesai</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer modal-footer-custom">
                    <button type="button" class="btn btn-secondary border-0" data-bs-dismiss="modal" style="border-radius: 8px;">Batal</button>
                    <button type="submit" class="btn btn-warning border-0" style="border-radius: 8px;">Ubah Status</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$extra_js = "
<script>
document.addEventListener('DOMContentLoaded', function() {
    const statusBtns = document.querySelectorAll('.btn-status');
    statusBtns.forEach(button => {
        button.addEventListener('click', function() {
            const id = this.getAttribute('data-id');
            const status = this.getAttribute('data-status');

            document.getElementById('status_id_pesanan').value = id;
            document.getElementById('status_pesanan').value = status;
        });
    });

    // Logic Toggle Tipe Pelanggan di Modal
    const radioTamu = document.getElementById('tipe_tamu');
    const radioMember = document.getElementById('tipe_member');
    const secTamu = document.getElementById('sec_tamu');
    const secMember = document.getElementById('sec_member');
    const inputMemberSearch = document.getElementById('member_search_input');
    const hiddenMemberId = document.getElementById('id_pelanggan');
    const inputNamaTamu = document.getElementById('nama_pelanggan_baru');

    function toggleCustomerType() {
        if (radioTamu && radioTamu.checked) {
            if (secTamu) secTamu.style.display = 'block';
            if (secMember) secMember.style.display = 'none';
        } else if (radioMember && radioMember.checked) {
            if (secTamu) secTamu.style.display = 'none';
            if (secMember) secMember.style.display = 'block';
        }
    }

    if (radioTamu) radioTamu.addEventListener('change', toggleCustomerType);
    if (radioMember) radioMember.addEventListener('change', toggleCustomerType);

    // Custom Searchable Dropdown Logic
    const dropdownList = document.getElementById('member_dropdown_list');
    if (inputMemberSearch && dropdownList) {
        inputMemberSearch.addEventListener('focus', function() {
            dropdownList.style.display = 'block';
            filterOptions(this.value.toLowerCase());
        });

        inputMemberSearch.addEventListener('input', function() {
            dropdownList.style.display = 'block';
            filterOptions(this.value.toLowerCase());
            
            let exactMatch = false;
            const options = dropdownList.querySelectorAll('.btn-member-opt');
            options.forEach(opt => {
                const name = opt.querySelector('.fw-semibold').innerText;
                if (name === this.value) {
                    hiddenMemberId.value = opt.getAttribute('data-id');
                    exactMatch = true;
                }
            });
            if (!exactMatch) {
                hiddenMemberId.value = '';
            }
        });

        dropdownList.addEventListener('click', function(e) {
            const btn = e.target.closest('.btn-member-opt');
            if (btn) {
                const memberId = btn.getAttribute('data-id');
                const memberName = btn.querySelector('.fw-semibold').innerText;
                inputMemberSearch.value = memberName;
                hiddenMemberId.value = memberId;
                dropdownList.style.display = 'none';
            }
        });

        document.addEventListener('click', function(e) {
            if (!inputMemberSearch.contains(e.target) && !dropdownList.contains(e.target)) {
                dropdownList.style.display = 'none';
            }
        });
    }

    function filterOptions(query) {
        if (!dropdownList) return;
        const options = dropdownList.querySelectorAll('.btn-member-opt');
        let visibleCount = 0;
        options.forEach(opt => {
            const searchVal = opt.getAttribute('data-search');
            if (searchVal.includes(query)) {
                opt.style.display = 'block';
                visibleCount++;
            } else {
                opt.style.display = 'none';
            }
        });
        const noResult = document.getElementById('member_no_result');
        if (noResult) {
            noResult.style.display = visibleCount === 0 ? 'block' : 'none';
        }
    }

    // Toggle Member Detail fields
    const checkDaftarMember = document.getElementById('is_member_baru');
    const secMemberDetail = document.getElementById('sec_member_detail');
    if (checkDaftarMember && secMemberDetail) {
        checkDaftarMember.addEventListener('change', function() {
            secMemberDetail.style.display = this.checked ? 'block' : 'none';
        });
    }

    // Validasi form tambah pesanan
    const formTambah = document.getElementById('formTambahPesanan');
    if (formTambah) {
        formTambah.addEventListener('submit', function(e) {
            if (radioTamu && radioTamu.checked) {
                if (inputNamaTamu && inputNamaTamu.value.trim() === '') {
                    alert('Silakan tulis nama tamu manual terlebih dahulu!');
                    e.preventDefault();
                }
            } else if (radioMember && radioMember.checked) {
                if (hiddenMemberId && hiddenMemberId.value === '') {
                    alert('Silakan pilih salah satu member terdaftar dari hasil pencarian (klik/pilih opsi autocomplete)!');
                    e.preventDefault();
                }
            }
        });
    }
});
</script>
";

include "includes/footer.php";


?>
