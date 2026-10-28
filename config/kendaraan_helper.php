<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['login']) || $_SESSION['login'] !== true) {
    header('Location: ../login.php');
    exit;
}

$levelPengguna = $_SESSION['level'] ?? '';

if (!in_array($levelPengguna, ['admin', 'petugas'], true)) {
    http_response_code(403);
    exit('Akses ditolak. Halaman ini hanya untuk admin dan petugas.');
}

if (empty($_SESSION['csrf_kendaraan'])) {
    $_SESSION['csrf_kendaraan'] = bin2hex(random_bytes(32));
}

function kh_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function kh_money($amount): string
{
    return 'Rp ' . number_format((float) $amount, 0, ',', '.');
}

function kh_length(string $value): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);
}

function kh_bind(mysqli_stmt $stmt, string $types, array &$params): void
{
    if ($types === '') {
        return;
    }

    $arguments = [$types];

    foreach ($params as &$value) {
        $arguments[] = &$value;
    }

    unset($value);

    call_user_func_array([$stmt, 'bind_param'], $arguments);
}

function kh_csrf_valid($token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf_kendaraan'])
        && hash_equals($_SESSION['csrf_kendaraan'], $token);
}

function kh_flash_set(string $type, string $message): void
{
    $_SESSION['flash_kendaraan'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function kh_flash_get(): ?array
{
    if (!isset($_SESSION['flash_kendaraan'])) {
        return null;
    }

    $flash = $_SESSION['flash_kendaraan'];
    unset($_SESSION['flash_kendaraan']);

    return $flash;
}

function kh_vehicle_url(array $params = []): string
{
    $allowed = ['q', 'jenis', 'status', 'hal'];
    $query = [];

    foreach ($allowed as $key) {
        if (isset($params[$key]) && $params[$key] !== '' && $params[$key] !== null) {
            $query[$key] = $params[$key];
        }
    }

    return 'kendaraan.php' . ($query ? '?' . http_build_query($query) : '');
}

function kh_photo_url($photo): ?string
{
    $photo = trim((string) $photo);

    if ($photo === '') {
        return null;
    }

    if (
        preg_match('#^https?://#i', $photo)
        && filter_var($photo, FILTER_VALIDATE_URL)
    ) {
        return $photo;
    }

    $photo = str_replace('\\', '/', $photo);
    $photo = ltrim($photo, '/');

    if (preg_match('#(^|/)\.\.(/|$)#', $photo) || strpos($photo, "\0") !== false) {
        return null;
    }

    return '../' . implode('/', array_map('rawurlencode', explode('/', $photo)));
}

function kh_store_photo(array $file): ?string
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Foto gagal diunggah. Silakan coba kembali.');
    }

    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('Ukuran foto maksimal 5 MB.');
    }

    if (!is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new RuntimeException('Berkas foto tidak valid.');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : false;

    if ($finfo) {
        finfo_close($finfo);
    }

    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Format foto harus JPG, PNG, WEBP, atau GIF.');
    }

    $root = dirname(__DIR__);
    $folder = $root . DIRECTORY_SEPARATOR . 'uploads'
        . DIRECTORY_SEPARATOR . 'kendaraan';

    if (!is_dir($folder) && !mkdir($folder, 0755, true) && !is_dir($folder)) {
        throw new RuntimeException('Folder unggahan foto tidak dapat dibuat.');
    }

    $filename = 'kendaraan_' . bin2hex(random_bytes(12)) . '.' . $extensions[$mime];
    $destination = $folder . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException('Foto gagal disimpan ke server.');
    }

    return 'uploads/kendaraan/' . $filename;
}

function kh_remove_photo($photo): void
{
    $photo = trim((string) $photo);

    if (
        $photo === ''
        || preg_match('#^https?://#i', $photo)
        || strpos($photo, 'uploads/kendaraan/') !== 0
    ) {
        return;
    }

    $root = realpath(dirname(__DIR__));
    $folder = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads'
        . DIRECTORY_SEPARATOR . 'kendaraan');

    if (!$root || !$folder) {
        return;
    }

    $relative = str_replace('/', DIRECTORY_SEPARATOR, $photo);
    $path = realpath($root . DIRECTORY_SEPARATOR . $relative);

    if (
        $path
        && is_file($path)
        && strpos($path, rtrim($folder, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) === 0
    ) {
        unlink($path);
    }
}

function kh_vehicle_code_exists(mysqli $koneksi, string $code, int $excludeId = 0): bool
{
    if ($excludeId > 0) {
        $stmt = $koneksi->prepare(
            'SELECT id_kendaraan FROM kendaraan WHERE kode_kendaraan = ? AND id_kendaraan <> ? LIMIT 1'
        );

        $stmt->bind_param('si', $code, $excludeId);
    } else {
        $stmt = $koneksi->prepare(
            'SELECT id_kendaraan FROM kendaraan WHERE kode_kendaraan = ? LIMIT 1'
        );

        $stmt->bind_param('s', $code);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result->num_rows > 0;
    $stmt->close();

    return $exists;
}

function kh_render_start(
    string $title,
    string $active = 'kendaraan',
    string $pageCss = 'kendaraan.css',
    string $pageJs = 'kendaraan.js'
): void {
    $namaPengguna = $_SESSION['nama'] ?? $_SESSION['username'] ?? 'Pengguna';
    $level = $_SESSION['level'] ?? 'petugas';

    $sidebarMode = 'vehicle';
    $sidebarActive = $active;
    ?>
    <!DOCTYPE html>
    <html lang="id">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="theme-color" content="#1c1e21">
        <title><?= kh_h($title); ?> | RentCar</title>

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link
            href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@75..125,400..700&display=swap"
            rel="stylesheet"
        >
        <link
            href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
            rel="stylesheet"
        >
        <link rel="stylesheet" href="../assets/css/kendaraan.css?v=3">
        <link rel="stylesheet" href="../assets/css/admin-modules.css?v=1">
        <?php if ($pageCss !== 'kendaraan.css') : ?>
            <link rel="stylesheet" href="../assets/css/<?= kh_h($pageCss); ?>?v=1">
        <?php endif; ?>

        <script src="../assets/js/kendaraan.js?v=3" defer></script>
        <?php if ($pageJs !== 'kendaraan.js') : ?>
            <script src="../assets/js/<?= kh_h($pageJs); ?>?v=1" defer></script>
        <?php endif; ?>
    </head>

    <body class="vehicle-page">
        <div class="vehicle-app">
            <?php
            $vehicleNavItems = [
                ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'bi-grid-1x2', 'url' => 'dashboard.php'],
                ['key' => 'kendaraan', 'label' => 'Kendaraan', 'icon' => 'bi-car-front', 'url' => 'kendaraan.php'],
                ['key' => 'transaksi', 'label' => 'Transaksi', 'icon' => 'bi-receipt', 'url' => 'transaksi.php'],
                ['key' => 'pelanggan', 'label' => 'Pelanggan', 'icon' => 'bi-people', 'url' => 'pelanggan.php'],
                ['key' => 'pengembalian', 'label' => 'Pengembalian', 'icon' => 'bi-arrow-return-left', 'url' => 'pengembalian.php'],
            ];
            if ($level === 'admin') {
                $vehicleNavItems[] = ['key' => 'laporan', 'label' => 'Laporan', 'icon' => 'bi-bar-chart-line', 'url' => 'laporan.php'];
            }
            ?>
            <aside class="vehicle-sidebar" id="vehicleSidebar">
                <a href="dashboard.php" class="vehicle-brand">
                    <img src="../assets/image/logo.svg" class="vehicle-brand-logo" alt="Logo RentCar">
                    <span class="vehicle-brand-name">Rent<span>Car</span></span>
                </a>
                <div class="vehicle-nav-label">Menu Utama</div>
                <nav class="vehicle-nav" aria-label="Navigasi admin">
                    <?php foreach ($vehicleNavItems as $navItem) : ?>
                        <a href="<?= kh_h($navItem['url']); ?>" class="<?= $sidebarActive === $navItem['key'] ? 'active' : ''; ?>" <?= $sidebarActive === $navItem['key'] ? 'aria-current="page"' : ''; ?>>
                            <i class="bi <?= kh_h($navItem['icon']); ?>"></i><span><?= kh_h($navItem['label']); ?></span>
                        </a>
                    <?php endforeach; ?>
                    <div class="vehicle-nav-label vehicle-nav-label-secondary">Lainnya</div>
                    <a href="pengaturan.php" class="<?= $sidebarActive === 'pengaturan' ? 'active' : ''; ?>" <?= $sidebarActive === 'pengaturan' ? 'aria-current="page"' : ''; ?>><i class="bi bi-gear"></i><span>Pengaturan</span></a>
                    <a href="../index.php" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i><span>Lihat Website</span></a>
                    <a href="../logout.php"><i class="bi bi-box-arrow-right"></i><span>Keluar</span></a>
                </nav>
                <div class="vehicle-sidebar-note"><span class="vehicle-sidebar-note-icon"><i class="bi bi-speedometer2"></i></span><strong>Panel RentCar</strong><p>Kelola operasional rental sesuai hak akses Anda.</p></div>
            </aside>
            <div class="vehicle-sidebar-backdrop" id="vehicleSidebarBackdrop"></div>

            <div class="vehicle-workspace">
                <header class="vehicle-topbar">
                    <div class="vehicle-topbar-left">
                        <button
                            type="button"
                            class="vehicle-menu-toggle"
                            id="vehicleMenuToggle"
                            aria-label="Buka menu"
                            aria-expanded="false"
                        >
                            <i class="bi bi-list"></i>
                        </button>

                        <div class="vehicle-breadcrumb">
                            <span>RentCar</span>
                            <i class="bi bi-chevron-right"></i>
                            <strong><?= kh_h($title); ?></strong>
                        </div>
                    </div>

                    <div class="vehicle-user">
                        <a href="../index.php" class="vehicle-visit-site" title="Buka website RentCar">
                            <i class="bi bi-box-arrow-up-right"></i>
                            <span>Lihat Website</span>
                        </a>
                        <span class="vehicle-user-avatar"><i class="bi bi-person-fill"></i></span>
                        <span class="vehicle-user-copy">
                            <strong><?= kh_h($namaPengguna); ?></strong>
                            <small><?= kh_h(ucfirst($level)); ?></small>
                        </span>
                    </div>
                </header>

                <main class="vehicle-content">
    <?php
}

function kh_render_end(): void
{
    ?>
                </main>
            </div>
        </div>
    </body>
    </html>
    <?php
}
