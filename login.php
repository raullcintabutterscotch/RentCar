<?php
session_start();

include "config/koneksi.php";

if (isset($_SESSION['login']) && $_SESSION['login'] === true) {
    $sessionLevel = $_SESSION['level'] ?? null;
    $destination = in_array($sessionLevel, ['admin', 'petugas'], true)
        ? 'admin/dashboard.php'
        : 'kendaraan.php';

    header('Location: ' . $destination);
    exit;
}

$error = "";
$success = "";
$showRegister = isset($_GET['mode']) && $_GET['mode'] === 'register';

/* PROSES REGISTER */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    $showRegister = true;

    $nama = trim($_POST['nama'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($nama === '' || $username === '' || $password === '') {
        $error = "Semua kolom wajib diisi.";
    } elseif (strlen($username) > 50) {
        $error = "Username maksimal 50 karakter.";
    } elseif (strlen($password) < 8) {
        $error = "Password minimal 8 karakter.";
    } else {
        $cek = mysqli_prepare(
            $koneksi,
            "SELECT id_user FROM users WHERE username = ? LIMIT 1"
        );

        if ($cek) {
            mysqli_stmt_bind_param($cek, "s", $username);
            mysqli_stmt_execute($cek);

            $hasil = mysqli_stmt_get_result($cek);
            $userAda = mysqli_fetch_assoc($hasil);

            mysqli_stmt_close($cek);

            if ($userAda) {
                $error = "Username sudah digunakan.";
            } else {
                $passwordHash = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                // Akun hasil registrasi adalah pelanggan.
                // Level NULL pada database dipakai untuk akun pelanggan.
                $simpan = mysqli_prepare(
                    $koneksi,
                    "INSERT INTO users (username, password, nama, level)
                    VALUES (?, ?, ?, NULL)"
                );

                if ($simpan) {
                    mysqli_stmt_bind_param(
                        $simpan,
                        "sss",
                        $username,
                        $passwordHash,
                        $nama
                    );

                    if (mysqli_stmt_execute($simpan)) {
                        $success = "Registrasi berhasil. Silakan login.";
                        $showRegister = false;
                    } else {
                        $error = "Registrasi gagal. Periksa database.";
                    }

                    mysqli_stmt_close($simpan);
                } else {
                    $error = "Gagal menyiapkan query registrasi.";
                }
            }
        } else {
            $error = "Gagal memeriksa username.";
        }
    }
}

/* PROSES LOGIN */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = "Username dan password wajib diisi.";
    } else {
        $query = mysqli_prepare(
            $koneksi,
            "SELECT id_user, username, password, nama, level, aktif
            FROM users
            WHERE username = ?
            LIMIT 1"
        );

        if ($query) {
            mysqli_stmt_bind_param($query, "s", $username);
            mysqli_stmt_execute($query);

            $hasil = mysqli_stmt_get_result($query);
            $user = mysqli_fetch_assoc($hasil);

            mysqli_stmt_close($query);

            $passwordValid = false;

            if ($user) {
                $passwordTersimpan = (string) $user['password'];

                if (password_verify($password, $passwordTersimpan)) {
                    $passwordValid = true;
                } elseif (hash_equals($passwordTersimpan, $password)) {
                    // Kompatibilitas password lama berbentuk teks biasa.
                    $passwordValid = true;

                    $passwordBaru = password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    );

                    $update = mysqli_prepare(
                        $koneksi,
                        "UPDATE users SET password = ? WHERE id_user = ?"
                    );

                    if ($update) {
                        mysqli_stmt_bind_param(
                            $update,
                            "si",
                            $passwordBaru,
                            $user['id_user']
                        );

                        mysqli_stmt_execute($update);
                        mysqli_stmt_close($update);
                    }
                }
            }

            if ($user && $passwordValid && (int) ($user['aktif'] ?? 1) === 1) {
                $lastLogin = mysqli_prepare($koneksi, 'UPDATE users SET last_login_at = NOW() WHERE id_user = ?');
                if ($lastLogin) {
                    mysqli_stmt_bind_param($lastLogin, 'i', $user['id_user']);
                    mysqli_stmt_execute($lastLogin);
                    mysqli_stmt_close($lastLogin);
                }
                session_regenerate_id(true);

                $_SESSION['login'] = true;
                $_SESSION['id_user'] = $user['id_user'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['nama'] = $user['nama'];
                $_SESSION['level'] = $user['level'];

                $destination = in_array($user['level'] ?? null, ['admin', 'petugas'], true)
                    ? 'admin/dashboard.php'
                    : 'kendaraan.php';

                header('Location: ' . $destination);
                exit;
            }

            $error = ($user && (int) ($user['aktif'] ?? 1) !== 1)
                ? "Akun dinonaktifkan. Hubungi administrator."
                : "Username atau password salah.";
        } else {
            $error = "Terjadi kesalahan saat memproses login.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#08090b">

    <title>Login | RentCar</title>

    <link rel="stylesheet" href="assets/css/login.css">
    <link
        href="https://cdn.lineicons.com/4.0/lineicons.css"
        rel="stylesheet"
    >

    <script src="assets/js/login.js?v=4" defer></script>
</head>

<body class="login-page">
    <a href="index.php" class="back-home">
        <i class="lni lni-arrow-left" aria-hidden="true"></i>
        <span>Kembali ke beranda</span>
    </a>

    <div class="login-box">
        <h2 class="login-brand">
            <img
                src="assets/image/logo.svg"
                alt="Logo RentCar"
                class="login-logo"
                width="100"
                height="100"
            >
            <span class="login-brand-text">
                Rent<span class="brand-r">Car</span>
            </span>
        </h2>

        <p class="page-subtitle">
            Sistem Rental Mobil &amp; Motor
        </p>

        <?php if ($error !== '') : ?>
            <div class="alert alert-error" role="alert">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <?php if ($success !== '') : ?>
            <div class="alert alert-success" role="status">
                <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <div
            class="container <?= $showRegister ? 'right-panel-active' : ''; ?>"
            id="container"
        >
            <!-- FORM REGISTER -->
            <div class="form-container register-container">
                <form method="post" action="">
                    <h1>Register here.</h1>

                    <input
                        type="text"
                        name="nama"
                        placeholder="Nama lengkap"
                        maxlength="100"
                        autocomplete="name"
                        required
                    >

                    <input
                        type="text"
                        name="username"
                        placeholder="Username"
                        maxlength="50"
                        autocomplete="username"
                        required
                    >

                    <input
                        type="password"
                        name="password"
                        placeholder="Password minimal 8 karakter"
                        minlength="8"
                        autocomplete="new-password"
                        required
                    >

                    <button type="submit" name="register" value="1">
                        Register
                    </button>

                    <span class="form-note">
                        Daftar untuk memulai perjalanan bersama RentCar.
                    </span>
                </form>
            </div>

            <!-- FORM LOGIN -->
            <div class="form-container login-container">
                <form method="post" action="">
                    <h1>Login here.</h1>

                    <input
                        type="text"
                        name="username"
                        placeholder="Username"
                        maxlength="50"
                        autocomplete="username"
                        required
                    >

                    <input
                        type="password"
                        name="password"
                        placeholder="Password"
                        autocomplete="current-password"
                        required
                    >

                    <div class="content">
                        <div class="checkbox">
                            <input
                                type="checkbox"
                                name="remember"
                                id="checkbox"
                            >
                            <label for="checkbox">Remember me</label>
                        </div>

                        <div class="pass-link">
                            <span class="pass-link pass-help-text">Lupa kata sandi? Hubungi administrator.</span>
                        </div>
                    </div>

                    <button type="submit" name="login" value="1">
                        Login
                    </button>

                    <span class="form-note">
                        Masuk untuk melanjutkan perjalanan Anda.
                    </span>
                </form>
            </div>

            <!-- PANEL GAMBAR -->
            <div class="overlay-container">
                <div class="overlay">
                    <div class="overlay-panel overlay-left">
                        <h1 class="title">
                            Welcome<br>back.
                        </h1>

                        <p>
                            Sudah memiliki akun? Login dan lanjutkan
                            perjalanan Anda.
                        </p>

                        <button
                            type="button"
                            class="ghost"
                            id="login"
                            onclick="document.getElementById('container').classList.remove('right-panel-active')"
                        >
                            Login
                            <i class="lni lni-arrow-left login" aria-hidden="true"></i>
                        </button>
                    </div>

                    <div class="overlay-panel overlay-right">
                        <h1 class="title">
                            Start your<br>journey now.
                        </h1>

                        <p>
                            Belum punya akun? Daftar dan mulai
                            perjalanan Anda bersama RentCar.
                        </p>

                        <button
                            type="button"
                            class="ghost"
                            id="register"
                            onclick="document.getElementById('container').classList.add('right-panel-active')"
                        >
                            Register
                            <i class="lni lni-arrow-right register" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>

</html>
