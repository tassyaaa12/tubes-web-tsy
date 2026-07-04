<?php
$current_page = basename($_SERVER["PHP_SELF"]);
$user_name = $_SESSION["nama_lengkap"] ?? "User";
$user_role = $_SESSION["role"] ?? "kasir";

// Inisial avatar dari nama lengkap
$initials = "";
foreach (explode(" ", $user_name) as $word) {
  $initials .= strtoupper($word[0] ?? "");
}
$initials = substr($initials, 0, 2);

// Definisi menu dengan kontrol akses berbasis role
// 'roles' berisi daftar role yang boleh melihat menu tersebut.
// Kosongkan array atau taruh semua role berarti semua boleh akses.
$menu_items = [
  [
    "label" => "Dashboard",
    "icon" => "fa-chart-pie",
    "href" => "dashboard.php",
    "roles" => ["admin", "kasir"],
  ],
  [
    "label" => "Kategori",
    "icon" => "fa-tags",
    "href" => "kategori.php",
    "roles" => ["admin"], // Hanya admin
  ],
  [
    "label" => "Menu Kafe",
    "icon" => "fa-bowl-food",
    "href" => "menu.php",
    "roles" => ["admin"], // Hanya admin
  ],
  [
    "label" => "Daftar Meja",
    "icon" => "fa-chair",
    "href" => "meja.php",
    "roles" => ["admin", "kasir"],
  ],
  [
    "label" => "Pelanggan",
    "icon" => "fa-users",
    "href" => "pelanggan.php",
    "roles" => ["admin", "kasir"],
  ],
  [
    "label" => "Pesanan",
    "icon" => "fa-receipt",
    "href" => "pesanan.php",
    "roles" => ["admin", "kasir"],
  ],
  [
    "label" => "Kasir / POS",
    "icon" => "fa-cash-register",
    "href" => "kasir.php",
    "roles" => ["admin", "kasir"],
  ],
  [
    "label" => "Laporan",
    "icon" => "fa-file-invoice-dollar",
    "href" => "laporan.php",
    "roles" => ["admin"], // Hanya admin
  ],
];
?>

<aside class="app-sidebar no-print">
    <!-- Logo -->
    <div class="sidebar-header">
        <i class="fa-solid fa-mug-hot sidebar-logo-icon"></i>
        <span class="sidebar-logo-text">KAFE MANAGEMENT</span>
    </div>

    <!-- Info User -->
    <div class="sidebar-user">
        <div class="user-avatar">
            <?= htmlspecialchars($initials) ?>
        </div>
        <div class="user-info">
            <div class="user-name"><?= htmlspecialchars($user_name) ?></div>
            <div class="user-role"><?= htmlspecialchars($user_role) ?></div>
        </div>
    </div>

    <!-- Menu Navigasi (filter berdasarkan role) -->
    <ul class="sidebar-menu">
        <?php foreach ($menu_items as $item): ?>
            <?php if (in_array($user_role, $item["roles"], true)): ?>
                <li class="menu-item <?= $current_page === $item["href"]
                  ? "active"
                  : "" ?>">
                    <a href="<?= htmlspecialchars(
                      $item["href"],
                    ) ?>" class="menu-link">
                        <i class="fa-solid <?= htmlspecialchars(
                          $item["icon"],
                        ) ?>"></i>
                        <span><?= htmlspecialchars($item["label"]) ?></span>
                    </a>
                </li>
            <?php endif; ?>
        <?php endforeach; ?>
    </ul>

    <!-- Footer Sidebar: Info Sesi -->
    <div class="sidebar-footer">
        <!-- Tombol Keluar -->
        <a href="auth/logout.php" class="btn-logout" id="btnLogout">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span>Keluar</span>
        </a>
    </div>
</aside>

<script>
// Konfirmasi logout
document.getElementById('btnLogout')?.addEventListener('click', function(e) {
    e.preventDefault();
    if (confirm('Apakah Anda yakin ingin keluar dari sistem?')) {
        window.location.href = this.href;
    }
});
</script>
