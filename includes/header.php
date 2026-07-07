<?php
require_once 'config/koneksi.php';
require_once 'config/auth.php';

// Wajib login untuk semua halaman yang pakai header ini
require_auth();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($page_title) ? htmlspecialchars($page_title) . " - Kafe POS" : "Sistem Manajemen Kafe" ?></title>
    <?php if (isset($page_desc)): ?>
    <meta name="description" content="<?= htmlspecialchars($page_desc) ?>">
    <?php endif; ?>
    
    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- FontAwesome 6.4 Icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <!-- Custom CSS -->
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
    <div class="app-wrapper">
        <!-- Sidebar Navigation -->
        <div class="sidebar-overlay"></div>
        <?php include 'includes/sidebar.php'; ?>
        
        <!-- Main Content Wrapper -->
        <main class="main-content">
            <!-- Content Header -->
            <div class="content-header">
                <div class="header-title d-flex align-items-center gap-3">
                    <button id="sidebarToggle" class="btn btn-outline-secondary d-md-none border-0" aria-label="Toggle Sidebar">
                        <i class="fa-solid fa-bars fs-4"></i>
                    </button>
                    <div>
                        <h1><?= isset($page_title) ? htmlspecialchars($page_title) : "Dashboard" ?></h1>
                        <p class="text-muted mb-0"><?= isset($page_desc) ? htmlspecialchars($page_desc) : "Selamat datang di panel kontrol Sistem Manajemen Kafe." ?></p>
                    </div>
                </div>
                <div class="header-action d-flex align-items-center gap-3">
                    <!-- Tanggal -->
                    <span class="text-muted d-none d-md-inline small">
                        <i class="fa-regular fa-calendar me-1"></i><?= date('d M Y') ?>
                    </span>

                    <!-- Badge Sesi Aktif & Info Cookie -->
                    <?php if (!empty($_COOKIE[COOKIE_NAME])): ?>
                    <span class="badge rounded-pill px-2 py-1 small d-none d-md-inline"
                          title="Login diingat via cookie selama 7 hari"
                          style="background: rgba(61,122,90,0.1); color: var(--accent-green); border: 1px solid rgba(61,122,90,0.2);">
                        <i class="fa-solid fa-cookie-bite me-1"></i>Diingat
                    </span>
                    <?php endif; ?>

                    <!-- Badge Role -->
                    <span class="badge rounded-pill px-3 py-1 fw-semibold"
                          style="background: var(--gradient-primary); color: #fff; font-size: 11px;">
                        <i class="fa-solid <?= is_admin() ? 'fa-user-shield' : 'fa-cash-register' ?> me-1"></i>
                        <?= strtoupper(htmlspecialchars($_SESSION['role'] ?? '')) ?>
                    </span>
                </div>
            </div>
