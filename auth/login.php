<?php
require_once "../config/koneksi.php";
require_once "../config/auth.php";

// Jika sudah login, langsung redirect ke dashboard
if (isset($_SESSION["user_id"])) {
  header("Location: ../dashboard.php");
  exit();
}

$error = "";

// =========================================================
// PROSES LOGIN FORM
// =========================================================
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $username = trim($_POST["username"] ?? "");
  $password = trim($_POST["password"] ?? "");
  $remember_me = isset($_POST["remember_me"]); // checkbox "Ingat Saya"

  if (!empty($username) && !empty($password)) {
    try {
      $stmt = $conn->prepare(
        "SELECT * FROM users WHERE username = :username LIMIT 1",
      );
      $stmt->execute(["username" => $username]);
      $user = $stmt->fetch();

      if ($user && password_verify($password, $user["password"])) {
        // Validasi role — hanya admin dan kasir yang diizinkan
        if (!in_array($user["role"], ["admin", "kasir"], true)) {
          $error = "Akun Anda tidak memiliki izin untuk mengakses sistem ini.";
        } else {
          // Regenerasi ID session untuk mencegah session fixation
          session_regenerate_id(true);

          // Simpan data user ke session
          $_SESSION["user_id"] = $user["id"];
          $_SESSION["username"] = $user["username"];
          $_SESSION["nama_lengkap"] = $user["nama_lengkap"];
          $_SESSION["role"] = $user["role"];
          $_SESSION["last_activity"] = time();

          // Jika centang "Ingat Saya", set cookie 7 hari
          if ($remember_me) {
            set_remember_cookie($user["username"], $user["role"]);
          }

          header("Location: ../dashboard.php");
          exit();
        }
      } else {
        $error = "Username atau Password salah!";
      }
    } catch (PDOException $e) {
      $error = "Terjadi kesalahan sistem: " . $e->getMessage();
    }
  } else {
    $error = "Semua field wajib diisi!";
  }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Sistem Manajemen Kafe</title>
    <meta name="description" content="Halaman masuk Sistem Manajemen Kafe">
    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Custom Style -->
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body class="login-body">

    <div class="login-card">
        <!-- Logo & Header -->
        <div class="text-center mb-4">
            <div class="mb-3" style="font-size: 48px; color: var(--accent-coffee);">
                <i class="fa-solid fa-mug-hot"></i>
            </div>
            <h3 class="fw-bold text-main">MANAJEMEN KAFE</h3>
            <p class="text-muted small">Silakan masuk untuk mengakses sistem</p>
        </div>

        <!-- Pesan Error -->
        <?php if (!empty($error)): ?>
            <div class="d-flex align-items-center gap-2 mb-3 px-3 py-2"
                 role="alert"
                 style="background-color: rgba(201,87,87,0.08); color: var(--accent-red);
                        border-left: 3px solid var(--accent-red); border-radius: 8px;">
                <i class="fa-solid fa-triangle-exclamation fa-sm"></i>
                <span class="small fw-medium"><?= htmlspecialchars(
                  $error,
                ) ?></span>
            </div>
        <?php endif; ?>

        <!-- Form Login -->
        <form id="loginForm" action="" method="POST" novalidate>
            <!-- Username -->
            <div class="mb-3">
                <label for="username" class="form-label text-muted small fw-medium">Username</label>
                <div class="input-group">
                    <span class="input-group-text form-control-custom text-muted border-end-0">
                        <i class="fa-regular fa-user fa-sm"></i>
                    </span>
                    <input type="text" name="username" id="username"
                           class="form-control form-control-custom border-start-0"
                           placeholder="Masukkan username Anda"
                           value="<?= htmlspecialchars(
                             $_POST["username"] ?? "",
                           ) ?>"
                           required autofocus autocomplete="username">
                </div>
            </div>

            <!-- Password -->
            <div class="mb-3">
                <label for="password" class="form-label text-muted small fw-medium">Password</label>
                <div class="input-group">
                    <span class="input-group-text form-control-custom text-muted border-end-0">
                        <i class="fa-solid fa-lock fa-sm"></i>
                    </span>
                    <input type="password" name="password" id="password"
                           class="form-control form-control-custom border-start-0"
                           placeholder="Masukkan password Anda"
                           required autocomplete="current-password">
                    <!-- Tombol show/hide password -->
                    <button type="button" id="togglePassword"
                            class="input-group-text form-control-custom text-muted"
                            title="Tampilkan/Sembunyikan Password">
                        <i class="fa-regular fa-eye fa-sm" id="eyeIcon"></i>
                    </button>
                </div>
            </div>

            <!-- Ingat Saya -->
            <div class="mb-4 d-flex align-items-center justify-content-between">
                <div class="form-check mb-0">
                    <input class="form-check-input" type="checkbox" name="remember_me" id="remember_me"
                           style="accent-color: var(--accent-coffee);">
                    <label class="form-check-label text-muted small" for="remember_me">
                        Ingat Saya (7 hari)
                    </label>
                </div>
                <span class="small text-muted fst-italic">
                    <i class="fa-solid fa-shield-halved me-1" style="color: var(--accent-green);"></i>
                    Aman & Terenkripsi
                </span>
            </div>

            <!-- Tombol Login -->
            <button type="submit" id="btnLogin"
                    class="btn w-100 fw-semibold py-2"
                    style="background: var(--gradient-primary); color: #fff;
                           border-radius: var(--border-radius); border: none;
                           box-shadow: 0 4px 12px rgba(138, 90, 54, 0.2);">
                <i class="fa-solid fa-right-to-bracket me-2"></i>
                <span id="btnText">Masuk Aplikasi</span>
            </button>
        </form>

        <div class="text-center mt-4 text-muted small">
            &copy; <?= date("Y") ?> Sistem Manajemen Kafe
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle show/hide password
        document.getElementById('togglePassword').addEventListener('click', function () {
            const pwInput = document.getElementById('password');
            const eyeIcon = document.getElementById('eyeIcon');
            if (pwInput.type === 'password') {
                pwInput.type = 'text';
                eyeIcon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                pwInput.type = 'password';
                eyeIcon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        });

        // Loading state saat form submit
        document.getElementById('loginForm').addEventListener('submit', function () {
            const btn = document.getElementById('btnLogin');
            const txt = document.getElementById('btnText');
            btn.disabled = true;
            txt.textContent = 'Memverifikasi...';
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>' + txt.textContent;
        });
    </script>
</body>
</html>
