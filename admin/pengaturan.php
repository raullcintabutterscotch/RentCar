<?php

session_start();

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/kendaraan_helper.php';
require_once __DIR__ . '/../config/payment_functions.php';

mysqli_set_charset($koneksi, 'utf8mb4');

$userId = (int) ($_SESSION['id_user'] ?? 0);
$isAdmin = (($_SESSION['level'] ?? '') === 'admin');
$errors = [];
$activeTab = (string) ($_GET['tab'] ?? 'profil');
$allowedTabs = $isAdmin ? ['profil', 'keamanan', 'rentcar'] : ['profil', 'keamanan'];
$activeTab = in_array($activeTab, $allowedTabs, true) ? $activeTab : 'profil';
$systemSettings = $koneksi->query(
    'SELECT persentase_dp, denda_per_hari, pengingat_sebelum_jam, maksimal_hari_sewa
     FROM pengaturan_rentcar WHERE id_pengaturan = 1 LIMIT 1'
)->fetch_assoc() ?: [
    'persentase_dp' => 30,
    'denda_per_hari' => 100000,
    'pengingat_sebelum_jam' => 24,
    'maksimal_hari_sewa' => 90,
];

$userStmt = $koneksi->prepare(
    'SELECT id_user, username, nama, password, level
     FROM users
     WHERE id_user = ?
     LIMIT 1'
);
$userStmt->bind_param('i', $userId);
$userStmt->execute();
$user = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

if (!$user) {
    http_response_code(404);
    exit('Data akun tidak ditemukan. Silakan keluar dan masuk kembali.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!kh_csrf_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Permintaan tidak valid. Muat ulang halaman, lalu coba kembali.');
    }

    $action = (string) ($_POST['action'] ?? '');


    if ($action === 'update_rentcar') {
        if (!$isAdmin) {
            http_response_code(403);
            exit('Hanya admin yang boleh mengubah pengaturan sistem.');
        }

        $dpPercent = filter_var($_POST['persentase_dp'] ?? null, FILTER_VALIDATE_FLOAT);
        $fineRate = pay_parse_money($_POST['denda_per_hari'] ?? '');
        $reminderHours = filter_var($_POST['pengingat_sebelum_jam'] ?? null, FILTER_VALIDATE_INT);
        $maxRentalDays = filter_var($_POST['maksimal_hari_sewa'] ?? null, FILTER_VALIDATE_INT);

        if ($dpPercent === false || $dpPercent <= 0 || $dpPercent > 100) {
            $errors[] = 'Persentase DP harus lebih dari 0 dan maksimal 100.';
        }
        if ($fineRate === null || $fineRate < 0 || $fineRate > 9999999999.99) {
            $errors[] = 'Tarif denda tidak valid.';
        }
        if ($reminderHours === false || $reminderHours < 1 || $reminderHours > 168) {
            $errors[] = 'Pengingat pengembalian harus antara 1 sampai 168 jam.';
        }
        if ($maxRentalDays === false || $maxRentalDays < 1 || $maxRentalDays > 365) {
            $errors[] = 'Durasi sewa maksimal harus antara 1 sampai 365 hari.';
        }

        if (!$errors) {
            try {
                $koneksi->begin_transaction();
                $updateSettings = $koneksi->prepare(
                    'UPDATE pengaturan_rentcar
                     SET persentase_dp = ?, denda_per_hari = ?,
                         pengingat_sebelum_jam = ?, maksimal_hari_sewa = ?, updated_by = ?
                     WHERE id_pengaturan = 1'
                );
                $updateSettings->bind_param(
                    'ddiii',
                    $dpPercent,
                    $fineRate,
                    $reminderHours,
                    $maxRentalDays,
                    $userId
                );
                $updateSettings->execute();
                $updateSettings->close();

                $audit = $koneksi->prepare(
                    "INSERT INTO audit_log (id_user, aksi, entitas, detail)
                     VALUES (?, 'ubah_pengaturan_rentcar', 'pengaturan_rentcar', ?)"
                );
                $auditDetail = 'DP=' . $dpPercent . '%; denda=' . $fineRate
                    . '; pengingat=' . $reminderHours . ' jam; maksimal_sewa=' . $maxRentalDays . ' hari';
                $audit->bind_param('is', $userId, $auditDetail);
                $audit->execute();
                $audit->close();
                $koneksi->commit();

                kh_flash_set('success', 'Pengaturan penyewaan berhasil diperbarui.');
                header('Location: pengaturan.php?tab=rentcar');
                exit;
            } catch (Throwable $exception) {
                $koneksi->rollback();
                error_log('[RentCar system settings] ' . $exception->getMessage());
                $errors[] = 'Pengaturan gagal disimpan. Periksa konfigurasi database.';
            }
        }
        $activeTab = 'rentcar';
        $systemSettings = array_merge($systemSettings, [
            'persentase_dp' => $dpPercent === false ? $systemSettings['persentase_dp'] : $dpPercent,
            'denda_per_hari' => $fineRate === null ? $systemSettings['denda_per_hari'] : $fineRate,
            'pengingat_sebelum_jam' => $reminderHours === false ? $systemSettings['pengingat_sebelum_jam'] : $reminderHours,
            'maksimal_hari_sewa' => $maxRentalDays === false ? $systemSettings['maksimal_hari_sewa'] : $maxRentalDays,
        ]);
    } elseif ($action === 'update_profile') {
        $nama = trim((string) ($_POST['nama'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));

        if ($nama === '' || kh_length($nama) > 100) {
            $errors[] = 'Nama wajib diisi dan maksimal 100 karakter.';
        }

        if ($username === '' || kh_length($username) > 50) {
            $errors[] = 'Username wajib diisi dan maksimal 50 karakter.';
        }

        if (!$errors) {
            $checkStmt = $koneksi->prepare(
                'SELECT id_user
                 FROM users
                 WHERE username = ? AND id_user <> ?
                 LIMIT 1'
            );
            $checkStmt->bind_param('si', $username, $userId);
            $checkStmt->execute();
            $usernameExists = $checkStmt->get_result()->num_rows > 0;
            $checkStmt->close();

            if ($usernameExists) {
                $errors[] = 'Username sudah digunakan akun lain.';
            }
        }

        if (!$errors) {
            $updateStmt = $koneksi->prepare(
                'UPDATE users
                 SET nama = ?, username = ?
                 WHERE id_user = ?'
            );
            $updateStmt->bind_param('ssi', $nama, $username, $userId);
            $updateStmt->execute();
            $updateStmt->close();

            $_SESSION['nama'] = $nama;
            $_SESSION['username'] = $username;

            kh_flash_set('success', 'Informasi akun berhasil diperbarui.');
            header('Location: pengaturan.php?tab=profil');
            exit;
        }

        $user['nama'] = $nama;
        $user['username'] = $username;
        $activeTab = 'profil';
    } elseif ($action === 'update_password') {
        $currentPassword = (string) ($_POST['password_lama'] ?? '');
        $newPassword = (string) ($_POST['password_baru'] ?? '');
        $confirmPassword = (string) ($_POST['konfirmasi_password'] ?? '');

        $storedPassword = (string) $user['password'];
        $currentValid = password_verify($currentPassword, $storedPassword);

        if (!$currentValid && $currentPassword !== '') {
            $currentValid = hash_equals($storedPassword, $currentPassword);
        }

        if (!$currentValid) {
            $errors[] = 'Password saat ini tidak sesuai.';
        }

        if (strlen($newPassword) < 8) {
            $errors[] = 'Password baru minimal 8 karakter.';
        }

        if ($newPassword !== $confirmPassword) {
            $errors[] = 'Konfirmasi password baru tidak sama.';
        }

        if (!$errors) {
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $updateStmt = $koneksi->prepare(
                'UPDATE users SET password = ? WHERE id_user = ?'
            );
            $updateStmt->bind_param('si', $passwordHash, $userId);
            $updateStmt->execute();
            $updateStmt->close();

            kh_flash_set('success', 'Password berhasil diperbarui.');
            header('Location: pengaturan.php?tab=keamanan');
            exit;
        }

        $activeTab = 'keamanan';
    }
}

$flash = kh_flash_get();
kh_render_start('Pengaturan', 'pengaturan', 'pengaturan.css', 'pengaturan.js');
?>

<section class="vehicle-page-heading">
    <div>
        <div class="vehicle-eyebrow">AKUN DAN PREFERENSI</div>
        <h1>Pengaturan</h1>
        <p>Perbarui informasi akun dan jaga keamanan akses RentCar.</p>
    </div>
</section>

<?php if ($flash) : ?>
    <div class="vehicle-alert vehicle-alert-<?= kh_h($flash['type'] ?? 'info'); ?>" role="status">
        <i class="bi bi-check-circle"></i>
        <span><?= kh_h($flash['message'] ?? ''); ?></span>
    </div>
<?php endif; ?>

<?php if ($errors) : ?>
    <div class="vehicle-alert vehicle-alert-error" role="alert">
        <i class="bi bi-exclamation-triangle"></i>
        <div>
            <strong>Periksa kembali data.</strong>
            <ul>
                <?php foreach ($errors as $error) : ?>
                    <li><?= kh_h($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>

<div class="settings-layout">
    <aside class="settings-menu" aria-label="Menu pengaturan">
        <a href="pengaturan.php?tab=profil" class="<?= $activeTab === 'profil' ? 'active' : ''; ?>">
            <i class="bi bi-person-lines-fill"></i>
            <span>Profil akun</span>
        </a>
        <a href="pengaturan.php?tab=keamanan" class="<?= $activeTab === 'keamanan' ? 'active' : ''; ?>">
            <i class="bi bi-shield-lock"></i>
            <span>Keamanan</span>
        </a>
        <?php if ($isAdmin) : ?>
            <a href="pengaturan.php?tab=rentcar" class="<?= $activeTab === 'rentcar' ? 'active' : ''; ?>">
                <i class="bi bi-sliders"></i>
                <span>Aturan RentCar</span>
            </a>
        <?php endif; ?>
    </aside>

    <section class="settings-panel">
        <?php if ($activeTab === 'profil') : ?>
            <div class="module-panel-heading">
                <div>
                    <h2>Informasi akun</h2>
                    <p>Nama dan username ini digunakan saat kamu masuk ke panel admin.</p>
                </div>
                <span class="settings-avatar"><i class="bi bi-person-fill"></i></span>
            </div>

            <form method="post" class="module-form" autocomplete="on">
                <input type="hidden" name="csrf_token" value="<?= kh_h($_SESSION['csrf_kendaraan']); ?>">
                <input type="hidden" name="action" value="update_profile">

                <label class="module-field">
                    <span>Nama lengkap</span>
                    <input
                        type="text"
                        name="nama"
                        maxlength="100"
                        value="<?= kh_h($user['nama']); ?>"
                        autocomplete="name"
                        required
                    >
                </label>

                <label class="module-field">
                    <span>Username</span>
                    <input
                        type="text"
                        name="username"
                        maxlength="50"
                        value="<?= kh_h($user['username']); ?>"
                        autocomplete="username"
                        required
                    >
                </label>

                <label class="module-field">
                    <span>Peran akun</span>
                    <input type="text" value="<?= kh_h(ucfirst((string) $user['level'])); ?>" readonly>
                    <small>Peran hanya bisa diubah melalui pengelolaan akun oleh sistem.</small>
                </label>

                <div class="module-form-actions">
                    <button type="submit" class="vehicle-btn vehicle-btn-primary">
                        <i class="bi bi-check2-circle"></i>
                        Simpan perubahan
                    </button>
                </div>
            </form>
        <?php elseif ($activeTab === 'rentcar' && $isAdmin) : ?>
            <div class="module-panel-heading">
                <div>
                    <h2>Aturan penyewaan</h2>
                    <p>Atur DP minimum, tarif keterlambatan, dan pengingat pengembalian.</p>
                </div>
                <span class="settings-avatar"><i class="bi bi-sliders"></i></span>
            </div>

            <form method="post" class="module-form" id="rentcarSettingsForm">
                <input type="hidden" name="csrf_token" value="<?= kh_h($_SESSION['csrf_kendaraan']); ?>">
                <input type="hidden" name="action" value="update_rentcar">

                <label class="module-field">
                    <span>Persentase minimum DP (%)</span>
                    <input type="number" name="persentase_dp" min="0.01" max="100" step="0.01" value="<?= kh_h($systemSettings['persentase_dp']); ?>" required>
                    <small>Jumlah DP minimum dihitung dari total sewa.</small>
                </label>

                <label class="module-field">
                    <span>Denda per hari (Rp)</span>
                    <input type="text" name="denda_per_hari" inputmode="numeric" data-money-input value="<?= kh_h(number_format((float) $systemSettings['denda_per_hari'], 0, ',', '.')); ?>" required>
                </label>

                <label class="module-field">
                    <span>Pengingat sebelum pengembalian (jam)</span>
                    <input type="number" name="pengingat_sebelum_jam" min="1" max="168" step="1" value="<?= kh_h($systemSettings['pengingat_sebelum_jam']); ?>" required>
                </label>

                <label class="module-field">
                    <span>Durasi sewa maksimum (hari)</span>
                    <input type="number" name="maksimal_hari_sewa" min="1" max="365" step="1" value="<?= kh_h($systemSettings['maksimal_hari_sewa']); ?>" required>
                </label>

                <div class="module-form-actions">
                    <button type="submit" class="vehicle-btn vehicle-btn-primary">
                        <i class="bi bi-check2-circle"></i>
                        Simpan aturan
                    </button>
                </div>
            </form>
        <?php else : ?>
            <div class="module-panel-heading">
                <div>
                    <h2>Ubah password</h2>
                    <p>Gunakan password yang kuat dan jangan membagikannya kepada orang lain.</p>
                </div>
                <span class="settings-avatar"><i class="bi bi-shield-lock"></i></span>
            </div>

            <form method="post" class="module-form" id="passwordSettingsForm" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= kh_h($_SESSION['csrf_kendaraan']); ?>">
                <input type="hidden" name="action" value="update_password">

                <label class="module-field">
                    <span>Password saat ini</span>
                    <div class="password-input-wrap">
                        <input type="password" name="password_lama" autocomplete="current-password" required>
                        <button type="button" class="password-visibility" aria-label="Tampilkan password">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </label>

                <label class="module-field">
                    <span>Password baru</span>
                    <div class="password-input-wrap">
                        <input type="password" name="password_baru" minlength="8" autocomplete="new-password" required>
                        <button type="button" class="password-visibility" aria-label="Tampilkan password">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    <small>Minimal 8 karakter.</small>
                </label>

                <label class="module-field">
                    <span>Konfirmasi password baru</span>
                    <div class="password-input-wrap">
                        <input type="password" name="konfirmasi_password" minlength="8" autocomplete="new-password" required>
                        <button type="button" class="password-visibility" aria-label="Tampilkan password">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </label>

                <div class="module-form-actions">
                    <button type="submit" class="vehicle-btn vehicle-btn-primary">
                        <i class="bi bi-lock"></i>
                        Perbarui password
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </section>
</div>

<script src="../assets/js/money-input.js" defer></script>
<?php kh_render_end(); ?>
