<?php
session_start();
require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/rentcar_automation.php';

if (!isset($_SESSION['login']) || $_SESSION['login'] !== true) {
    header("Location: ../login.php");
    exit;
}
if (!in_array($_SESSION['level'] ?? '', ['admin', 'petugas'], true)) {
    http_response_code(403);
    exit('Akses ditolak.');
}

mysqli_set_charset($koneksi, "utf8mb4");

try { rc_refresh_automation($koneksi); } catch (Throwable $e) { error_log('[RentCar dashboard refresh] ' . $e->getMessage()); }

$namaPengguna = $_SESSION['nama'] ?? $_SESSION['username'] ?? 'Admin';
$userId = (int) ($_SESSION['id_user'] ?? 0);
$levelPengguna = $_SESSION['level'] ?? 'petugas';

function rc_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function rc_rows(mysqli $db, string $sql): array
{
    $result = $db->query($sql);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function rc_value(mysqli $db, string $sql, string $key, $default = 0)
{
    $result = $db->query($sql);

    if (!$result) {
        return $default;
    }

    $row = $result->fetch_assoc();
    return $row[$key] ?? $default;
}

function rc_money($amount): string
{
    return 'Rp ' . number_format((float) $amount, 0, ',', '.');
}

function rc_date($date): string
{
    if (!$date || strtotime((string) $date) === false) {
        return '-';
    }

    $months = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
        5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu',
        9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'
    ];

    $time = strtotime((string) $date);

    return date('d', $time) . ' ' .
        $months[(int) date('n', $time)] . ' ' .
        date('Y', $time);
}

/*
 * Foto diambil dari kolom kendaraan.foto.
 * Mendukung URL foto atau path relatif terhadap folder proyek.
 */
function rc_photo($photo): ?string
{
    $photo = trim((string) $photo);

    if ($photo === '') {
        return null;
    }

    if (preg_match('#^https?://#i', $photo)) {
        return $photo;
    }

    $photo = str_replace('\\', '/', $photo);
    $photo = ltrim($photo, '/');

    if (
        preg_match('#(^|/)\.\.(/|$)#', $photo) ||
        strpos($photo, "\0") !== false
    ) {
        return null;
    }

    $candidates = [$photo];

    if (strpos($photo, '/') === false) {
        $candidates = array_merge($candidates, [
            'uploads/kendaraan/' . $photo,
            'uploads/' . $photo,
            'assets/images/' . $photo,
            'assets/image/' . $photo,
            'assets/img/' . $photo,
            'images/' . $photo
        ]);
    }

    foreach (array_unique($candidates) as $candidate) {
        $filesystemPath = dirname(__DIR__) . '/' . $candidate;

        if (is_file($filesystemPath)) {
            return '../' . implode(
                '/',
                array_map('rawurlencode', explode('/', $candidate))
            );
        }
    }

    return null;
}

function rc_initial(string $name): string
{
    $name = trim($name);
    return $name === '' ? 'A' : strtoupper(substr($name, 0, 1));
}

/* STATISTIK */

$totalMobil = (int) rc_value(
    $koneksi,
    "SELECT COUNT(*) AS jumlah FROM kendaraan",
    "jumlah"
);

$totalTersedia = (int) rc_value(
    $koneksi,
    "SELECT COUNT(*) AS jumlah
     FROM kendaraan
     WHERE status = 'Tersedia'",
    "jumlah"
);

$totalTransaksi = (int) rc_value(
    $koneksi,
    "SELECT COUNT(*) AS jumlah FROM transaksi",
    "jumlah"
);

$totalBerjalan = (int) rc_value(
    $koneksi,
    "SELECT COUNT(*) AS jumlah
     FROM transaksi
     WHERE status_transaksi IN ('Dipesan', 'Berjalan', 'Terlambat')",
    "jumlah"
);

$totalPelanggan = (int) rc_value(
    $koneksi,
    "SELECT COUNT(*) AS jumlah FROM pelanggan",
    "jumlah"
);

$totalLunas = (float) rc_value(
    $koneksi,
    "SELECT COALESCE(SUM(nominal), 0) AS jumlah
     FROM pembayaran
     WHERE status = 'Diverifikasi'",
    "jumlah"
);

$totalDenda = (float) rc_value(
    $koneksi,
    "SELECT COALESCE(SUM(total_denda), 0) AS jumlah
     FROM denda",
    "jumlah"
);

$totalPengembalian = (int) rc_value(
    $koneksi,
    "SELECT COUNT(*) AS jumlah FROM pengembalian",
    "jumlah"
);

$totalTerlambat = (int) rc_value(
    $koneksi,
    "SELECT COUNT(*) AS jumlah FROM transaksi WHERE status_transaksi = 'Terlambat'",
    "jumlah"
);

$totalPembayaranMenunggu = (int) rc_value(
    $koneksi,
    "SELECT COUNT(*) AS jumlah FROM pembayaran WHERE status = 'Menunggu Verifikasi'",
    "jumlah"
);

$totalPengembalianMendekat = (int) rc_value(
    $koneksi,
    "SELECT COUNT(*) AS jumlah
     FROM transaksi t
     JOIN pengaturan_rentcar s ON s.id_pengaturan = 1
     WHERE t.status_transaksi IN ('Dipesan','Berjalan')
       AND TIMESTAMP(t.tanggal_kembali,t.waktu_kembali) > NOW()
       AND TIMESTAMP(t.tanggal_kembali,t.waktu_kembali) <= DATE_ADD(NOW(), INTERVAL s.pengingat_sebelum_jam HOUR)",
    "jumlah"
);

$notificationRole = $levelPengguna === 'admin' ? 'admin' : 'petugas';
$notificationStmt = $koneksi->prepare(
    'SELECT id_notifikasi, judul, pesan, created_at, dibaca_pada
     FROM notifikasi
     WHERE untuk_role = ? AND (id_user_tujuan IS NULL OR id_user_tujuan = ?)
     ORDER BY (dibaca_pada IS NULL) DESC, created_at DESC
     LIMIT 6'
);
$notificationStmt->bind_param('si', $notificationRole, $userId);
$notificationStmt->execute();
$dashboardNotifications = $notificationStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$notificationStmt->close();
$unreadStmt = $koneksi->prepare(
    'SELECT COUNT(*) AS jumlah
     FROM notifikasi
     WHERE untuk_role = ?
       AND (id_user_tujuan IS NULL OR id_user_tujuan = ?)
       AND dibaca_pada IS NULL'
);
$unreadStmt->bind_param('si', $notificationRole, $userId);
$unreadStmt->execute();
$unreadNotificationCount = (int) ($unreadStmt->get_result()->fetch_assoc()['jumlah'] ?? 0);
$unreadStmt->close();

/* KENDARAAN POPULER */

$mobilPopuler = rc_rows(
    $koneksi,
    "SELECT
        k.id_kendaraan,
        k.kode_kendaraan,
        k.nama_kendaraan,
        k.jenis,
        k.merk,
        k.tipe,
        k.tahun,
        k.nomor_polisi,
        k.harga_sewa,
        k.status,
        k.foto,
        COUNT(t.id_transaksi) AS jumlah_sewa
     FROM kendaraan k
     LEFT JOIN transaksi t
        ON t.id_kendaraan = k.id_kendaraan
     GROUP BY
        k.id_kendaraan,
        k.kode_kendaraan,
        k.nama_kendaraan,
        k.jenis,
        k.merk,
        k.tipe,
        k.tahun,
        k.nomor_polisi,
        k.harga_sewa,
        k.status,
        k.foto
     ORDER BY jumlah_sewa DESC, k.id_kendaraan DESC
     LIMIT 5"
);

foreach ($mobilPopuler as &$mobil) {
    $mobil['foto_url'] = rc_photo($mobil['foto']);
}
unset($mobil);

/* KENDARAAN TERSEDIA */

$mobilTersedia = rc_rows(
    $koneksi,
    "SELECT
        id_kendaraan,
        kode_kendaraan,
        nama_kendaraan,
        jenis,
        merk,
        tipe,
        tahun,
        nomor_polisi,
        harga_sewa,
        status,
        foto
     FROM kendaraan
     WHERE status = 'Tersedia'
     ORDER BY id_kendaraan DESC
     LIMIT 8"
);

foreach ($mobilTersedia as &$mobil) {
    $mobil['foto_url'] = rc_photo($mobil['foto']);
}
unset($mobil);

/* TRANSAKSI TERBARU */

$transaksiTerbaru = rc_rows(
    $koneksi,
    "SELECT
        t.id_transaksi,
        t.kode_transaksi,
        t.tanggal_sewa,
        t.tanggal_kembali,
        t.lama_sewa,
        t.harga_sewa,
        t.total_harga,
        t.status_pembayaran,
        t.status_transaksi,
        t.created_at,
        p.nama AS nama_pelanggan,
        k.nama_kendaraan,
        k.foto AS foto_mobil
     FROM transaksi t
     LEFT JOIN pelanggan p
        ON t.id_pelanggan = p.id_pelanggan
     LEFT JOIN kendaraan k
        ON t.id_kendaraan = k.id_kendaraan
     ORDER BY t.created_at DESC, t.id_transaksi DESC
     LIMIT 6"
);

foreach ($transaksiTerbaru as &$transaksi) {
    $transaksi['foto_url'] = rc_photo($transaksi['foto_mobil']);
}
unset($transaksi);

/* GRAFIK TRANSAKSI 7 HARI */

$bulan = [
    1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
    5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu',
    9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'
];

$grafikMap = [];
$grafikTanggal = [];
$today = new DateTimeImmutable('today');

for ($i = 6; $i >= 0; $i--) {
    $date = $today->modify("-{$i} days");
    $key = $date->format('Y-m-d');

    $grafikMap[$key] = 0;

    $grafikTanggal[] = $date->format('d') . ' ' .
        $bulan[(int) $date->format('n')];
}

$dataGrafik = rc_rows(
    $koneksi,
    "SELECT
        DATE(created_at) AS tanggal,
        COUNT(*) AS jumlah
     FROM transaksi
     WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
       AND created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
     GROUP BY DATE(created_at)
     ORDER BY tanggal ASC"
);

foreach ($dataGrafik as $item) {
    if (isset($grafikMap[$item['tanggal']])) {
        $grafikMap[$item['tanggal']] = (int) $item['jumlah'];
    }
}

$grafikJumlah = array_values($grafikMap);

/* GRAFIK TIPE KENDARAAN */

$dataTipe = rc_rows(
    $koneksi,
    "SELECT
        COALESCE(NULLIF(TRIM(tipe), ''), jenis, 'Lainnya') AS nama_tipe,
        COUNT(*) AS jumlah
     FROM kendaraan
     GROUP BY COALESCE(NULLIF(TRIM(tipe), ''), jenis, 'Lainnya')
     ORDER BY jumlah DESC
     LIMIT 5"
);

$labelTipe = [];
$jumlahTipe = [];

foreach ($dataTipe as $item) {
    $labelTipe[] = $item['nama_tipe'];
    $jumlahTipe[] = (int) $item['jumlah'];
}

/* PENGEMBALIAN TERBARU */

$pengembalianTerbaru = rc_rows(
    $koneksi,
    "SELECT
        r.id_pengembalian,
        r.id_transaksi,
        r.tanggal_pengembalian,
        r.terlambat,
        r.denda,
        r.total_bayar,
        r.keterangan,
        t.kode_transaksi,
        p.nama AS nama_pelanggan,
        k.nama_kendaraan
     FROM pengembalian r
     LEFT JOIN transaksi t
        ON r.id_transaksi = t.id_transaksi
     LEFT JOIN pelanggan p
        ON t.id_pelanggan = p.id_pelanggan
     LEFT JOIN kendaraan k
        ON t.id_kendaraan = k.id_kendaraan
     ORDER BY r.tanggal_pengembalian DESC, r.id_pengembalian DESC
     LIMIT 5"
);

/* FOTO BANNER DIAMBIL DARI KENDARAAN TERPOPULER */

$fotoBanner = $mobilPopuler[0]['foto_url'] ?? null;
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Dashboard | RentCar</title>
    
    <link rel="icon" href="../assets/image/logo.svg" sizes="64x64" type="image/png">

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <link
        href="https://unpkg.com/lenis@1.3.26/dist/lenis.css"
        rel="stylesheet"
    >

    <link rel="stylesheet" href="../assets/css/dashboard.css?v=4">

    <script
        src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"
        defer
    ></script>

    <script
        src="https://unpkg.com/lenis@1.3.26/dist/lenis.min.js"
        defer
    ></script>

    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        defer
    ></script>

    <script src="../assets/js/dashboard.js?v=4" defer></script>
</head>

<body>

<div class="dashboard-app">

    <?php
    $dashboardNavItems = [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'bi-grid-1x2', 'url' => 'dashboard.php'],
        ['key' => 'kendaraan', 'label' => 'Kendaraan', 'icon' => 'bi-car-front', 'url' => 'kendaraan.php'],
        ['key' => 'transaksi', 'label' => 'Transaksi', 'icon' => 'bi-receipt', 'url' => 'transaksi.php'],
        ['key' => 'pelanggan', 'label' => 'Pelanggan', 'icon' => 'bi-people', 'url' => 'pelanggan.php'],
        ['key' => 'pengembalian', 'label' => 'Pengembalian', 'icon' => 'bi-arrow-return-left', 'url' => 'pengembalian.php'],
    ];
    if ($levelPengguna === 'admin') {
        $dashboardNavItems[] = ['key' => 'laporan', 'label' => 'Laporan', 'icon' => 'bi-bar-chart-line', 'url' => 'laporan.php'];
    }
    ?>
    <aside class="sidebar" id="sidebar">
        <a href="dashboard.php" class="brand"><img src="../assets/image/logo.svg" class="brand-mark" alt="Logo RentCar"><span class="brand-text">Rent<span>Car</span></span></a>
        <div class="nav-label">Menu Utama</div>
        <nav class="sidebar-nav" aria-label="Navigasi admin">
            <?php foreach ($dashboardNavItems as $navItem) : ?>
                <a href="<?= rc_h($navItem['url']); ?>" class="<?= $navItem['key'] === 'dashboard' ? 'active' : ''; ?>" <?= $navItem['key'] === 'dashboard' ? 'aria-current="page"' : ''; ?>><i class="bi <?= rc_h($navItem['icon']); ?>"></i><span><?= rc_h($navItem['label']); ?></span></a>
            <?php endforeach; ?>
            <div class="nav-label nav-label-secondary">Lainnya</div>
            <a href="pengaturan.php"><i class="bi bi-gear"></i><span>Pengaturan</span></a>
            <a href="../index.php" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i><span>Lihat Website</span></a>
            <a href="../logout.php"><i class="bi bi-box-arrow-right"></i><span>Keluar</span></a>
        </nav>
        <div class="sidebar-promo"><div class="sidebar-promo-content"><h3>Rental Kendaraan<br>Lebih Mudah</h3><p>Kelola armada dan penyewaan dalam satu dashboard.</p><a href="kendaraan.php" class="btn-sport">Lihat Kendaraan <i class="bi bi-arrow-right"></i></a></div></div>
    </aside>
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <div class="workspace">

        <header class="topbar">

            <div class="topbar-left">

                <button
                    class="mobile-menu-btn"
                    id="sidebarToggle"
                    type="button"
                    aria-label="Buka menu"
                    aria-expanded="false"
                >
                    <i class="bi bi-list"></i>
                </button>

                <label class="search-box">
                    <i class="bi bi-search"></i>

                    <input
                        type="search"
                        id="globalSearch"
                        placeholder="Cari kendaraan atau pelanggan..."
                        autocomplete="off"
                    >
                </label>

            </div>

            <div class="topbar-right">

                <a href="../index.php" class="visit-site-link" target="_blank" rel="noopener">
                    <i class="bi bi-box-arrow-up-right"></i>
                    <span>Lihat Website</span>
                </a>

                <div class="dropdown">
                    <button
                        class="icon-button"
                        type="button"
                        data-bs-toggle="dropdown"
                        aria-expanded="false"
                        aria-label="Notifikasi"
                    >
                        <i class="bi bi-bell"></i>

                        <?php if ($unreadNotificationCount > 0 || $totalTerlambat > 0 || $totalPembayaranMenunggu > 0) : ?>
                            <span class="notification-dot" id="notificationDot"></span>
                        <?php else : ?>
                            <span class="notification-dot" id="notificationDot" hidden></span>
                        <?php endif; ?>
                    </button>

                    <div class="dropdown-menu dropdown-menu-end notification-menu">
                        <div class="notification-title">Notifikasi Operasional</div>
                        <div class="notification-item">
                            <i class="bi bi-clock-history"></i>
                            <span><?= number_format($totalPengembalianMendekat, 0, ',', '.'); ?> pengembalian mendekati batas waktu.</span>
                        </div>
                        <div class="notification-item">
                            <i class="bi bi-exclamation-triangle"></i>
                            <span><?= number_format($totalTerlambat, 0, ',', '.'); ?> transaksi terlambat. Denda diperbarui otomatis.</span>
                        </div>
                        <div class="notification-item">
                            <i class="bi bi-credit-card"></i>
                            <span><?= number_format($totalPembayaranMenunggu, 0, ',', '.'); ?> pembayaran menunggu verifikasi.</span>
                        </div>
                        <div class="notification-title notification-title-recent">Aktivitas terbaru</div>
                        <?php if ($dashboardNotifications) : ?>
                            <?php foreach ($dashboardNotifications as $notice) : ?>
                                <div class="notification-item notification-item-detail">
                                    <i class="bi <?= empty($notice['dibaca_pada']) ? 'bi-bell-fill' : 'bi-check-circle'; ?>"></i>
                                    <span><strong><?= rc_h($notice['judul']); ?></strong><small><?= rc_h($notice['pesan']); ?></small></span>
                                </div>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <div class="notification-item"><i class="bi bi-check-circle"></i><span>Belum ada aktivitas baru.</span></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="profile-divider"></div>

                <div class="profile">

                    <div class="profile-avatar">
                        <?= rc_h(rc_initial($namaPengguna)); ?>
                    </div>

                    <div class="profile-details">
                        <div class="profile-name">
                            <?= rc_h($namaPengguna); ?>
                        </div>

                        <div class="profile-role">
                            <?= rc_h(ucfirst($levelPengguna)); ?>
                        </div>
                    </div>

                    <a
                        href="pengaturan.php"
                        class="profile-chevron"
                        aria-label="Pengaturan akun"
                    >
                        <i class="bi bi-chevron-down"></i>
                    </a>

                </div>

            </div>
        </header>

        <main class="dashboard-main" id="dashboardScroll">

            <div class="dashboard-grid" id="dashboardContent">

                <section class="main-column">

                    <div class="welcome-row">

                        <div class="welcome-copy">

                            <h1>
                                Selamat datang, <?= rc_h($namaPengguna); ?>
                            </h1>

                            <p>
                                Kelola penyewaan kendaraan dengan mudah dan cepat.
                            </p>

                        </div>

                        <div class="hero-banner">

                            <?php if ($fotoBanner) : ?>
                                <img
                                    class="hero-image"
                                    src="<?= rc_h($fotoBanner); ?>"
                                    alt=""
                                >
                            <?php endif; ?>

                            <div class="hero-copy">

                                <div class="eyebrow">RENTCAR</div>

                                <h2>
                                    Kendaraan Pilihanmu<br>
                                    Siap Disewa
                                </h2>

                                <p>
                                    Aman <span>•</span> Nyaman
                                    <span>•</span> Terpercaya
                                </p>

                            </div>

                        </div>

                    </div>

                    <div class="stats-grid">

                        <article class="stat-card">

                            <div class="stat-icon">
                                <i class="bi bi-car-front"></i>
                            </div>

                            <div class="stat-content">
                                <div class="stat-title">Total Kendaraan</div>

                                <div class="stat-value" data-live-stat="vehicles">
                                    <?= number_format($totalMobil, 0, ',', '.'); ?>
                                </div>

                                <div class="stat-meta">
                                    <i class="bi bi-check-circle"></i>
                                    <span data-live-stat="available"><?= number_format($totalTersedia, 0, ',', '.'); ?></span>
                                    tersedia
                                </div>
                            </div>

                        </article>

                        <article class="stat-card">

                            <div class="stat-icon">
                                <i class="bi bi-calendar2-check"></i>
                            </div>

                            <div class="stat-content">
                                <div class="stat-title">Total Transaksi</div>

                                <div class="stat-value" data-live-stat="transactions">
                                    <?= number_format($totalTransaksi, 0, ',', '.'); ?>
                                </div>

                                <div class="stat-meta">
                                    <i class="bi bi-arrow-repeat"></i>
                                    <span data-live-stat="running"><?= number_format($totalBerjalan, 0, ',', '.'); ?></span>
                                    aktif atau terlambat
                                </div>
                            </div>

                        </article>

                        <article class="stat-card">

                            <div class="stat-icon">
                                <i class="bi bi-people"></i>
                            </div>

                            <div class="stat-content">
                                <div class="stat-title">Total Pelanggan</div>

                                <div class="stat-value" data-live-stat="customers">
                                    <?= number_format($totalPelanggan, 0, ',', '.'); ?>
                                </div>

                                <div class="stat-meta stat-meta-neutral">
                                    <i class="bi bi-database"></i>
                                    Data database
                                </div>
                            </div>

                        </article>

                        <article class="stat-card">

                            <div class="stat-icon">
                                <i class="bi bi-wallet2"></i>
                            </div>

                            <div class="stat-content">
                                <div class="stat-title">Pembayaran Diterima</div>

                                <div class="stat-value stat-value-money" data-live-stat="paidTotal" data-money="1">
                                    <?= rc_h(rc_money($totalLunas)); ?>
                                </div>

                                <div class="stat-meta stat-meta-neutral">
                                    <i class="bi bi-cash-stack"></i>
                                    Seluruh pembayaran terverifikasi
                                </div>
                            </div>

                        </article>

                    </div>

                    <div class="analytics-grid">

                        <section class="panel">

                            <div class="panel-header">
                                <div>
                                    <h2 class="panel-title">Aktivitas Transaksi</h2>

                                    <p class="panel-subtitle">
                                        Jumlah transaksi per hari, 7 hari terakhir
                                    </p>
                                </div>

                                <span class="panel-tag">7 Hari</span>
                            </div>

                            <div class="chart-wrap">
                                <canvas id="salesChart"></canvas>
                            </div>

                        </section>

                        <section class="panel">

                            <div class="panel-header">
                                <div>
                                    <h2 class="panel-title">Tipe Kendaraan</h2>

                                    <p class="panel-subtitle">
                                        Distribusi berdasarkan tipe kendaraan
                                    </p>
                                </div>
                            </div>

                            <div class="donut-layout">

                                <div class="donut-wrap">
                                    <canvas id="typeChart"></canvas>

                                    <div class="donut-center">
                                        <strong>
                                            <?= number_format($totalMobil, 0, ',', '.'); ?>
                                        </strong>

                                        <span>Kendaraan</span>
                                    </div>
                                </div>

                                <div class="legend-list">

                                    <?php
                                    $legendColors = [
                                        '#e32636', '#c7ccd2', '#687587',
                                        '#8e98a8', '#454f5e'
                                    ];
                                    ?>

                                    <?php foreach ($labelTipe as $index => $label) : ?>

                                        <?php
                                        $jumlah = $jumlahTipe[$index] ?? 0;

                                        $persentase = $totalMobil > 0
                                            ? round(($jumlah / $totalMobil) * 100)
                                            : 0;
                                        ?>

                                        <div class="legend-item">
                                            <span
                                                class="legend-dot"
                                                style="background:<?= $legendColors[$index % count($legendColors)]; ?>"
                                            ></span>

                                            <span class="legend-name">
                                                <?= rc_h($label); ?>
                                            </span>

                                            <span class="legend-percent">
                                                <?= $persentase; ?>%
                                            </span>
                                        </div>

                                    <?php endforeach; ?>

                                    <?php if (!$labelTipe) : ?>
                                        <div class="empty-inline">
                                            Belum ada data kendaraan.
                                        </div>
                                    <?php endif; ?>

                                </div>

                            </div>

                        </section>

                    </div>

                    <section class="panel cars-panel">

                        <div class="cars-heading">
                            <div>
                                <h2 class="panel-title">Kendaraan Tersedia</h2>

                                <p class="panel-subtitle">
                                    Kendaraan dengan status Tersedia di database
                                </p>
                            </div>

                            <a href="kendaraan.php" class="btn-sport">
                                Lihat Semua
                                <i class="bi bi-arrow-right"></i>
                            </a>
                        </div>

                        <?php if ($mobilTersedia) : ?>

                            <div class="cars-grid">

                                <?php foreach ($mobilTersedia as $mobil) : ?>

                                    <article
                                        class="car-card searchable-item"
                                        data-search="<?= rc_h(
                                            $mobil['nama_kendaraan'] . ' ' .
                                            $mobil['merk'] . ' ' .
                                            $mobil['jenis'] . ' ' .
                                            $mobil['tipe'] . ' ' .
                                            $mobil['nomor_polisi']
                                        ); ?>"
                                    >

                                        <div class="car-image-wrap">

                                            <span class="car-type">
                                                <?= rc_h($mobil['tipe'] ?: $mobil['jenis']); ?>
                                            </span>

                                            <?php if ($mobil['foto_url']) : ?>

                                                <img
                                                    src="<?= rc_h($mobil['foto_url']); ?>"
                                                    alt="<?= rc_h($mobil['nama_kendaraan']); ?>"
                                                    loading="lazy"
                                                >

                                            <?php else : ?>

                                                <div class="car-image-placeholder">
                                                    <i class="bi bi-car-front"></i>
                                                    <span>Foto belum tersedia</span>
                                                </div>

                                            <?php endif; ?>

                                        </div>

                                        <div class="car-body">

                                            <h3 class="car-name">
                                                <?= rc_h($mobil['nama_kendaraan']); ?>
                                            </h3>

                                            <div class="car-detail">
                                                <?= rc_h($mobil['merk']); ?>

                                                <?php if (!empty($mobil['tahun'])) : ?>
                                                    · <?= rc_h($mobil['tahun']); ?>
                                                <?php endif; ?>
                                            </div>

                                            <div class="car-price">
                                                <?= rc_h(rc_money($mobil['harga_sewa'])); ?>
                                                <small>/ hari</small>
                                            </div>

                                            <a
                                                href="kendaraan.php"
                                                class="btn-sport btn-sport-full"
                                            >
                                                Detail
                                                <i class="bi bi-arrow-right"></i>
                                            </a>

                                        </div>

                                    </article>

                                <?php endforeach; ?>

                            </div>

                        <?php else : ?>

                            <div class="empty-state">
                                <i class="bi bi-car-front"></i>

                                <h3>Belum ada kendaraan tersedia</h3>

                                <p>
                                    Kendaraan akan muncul setelah datanya
                                    tersimpan dan statusnya menjadi Tersedia.
                                </p>
                            </div>

                        <?php endif; ?>

                    </section>

                    <section class="panel returns-panel">

                        <div class="cars-heading">
                            <div>
                                <h2 class="panel-title">Pengembalian Terbaru</h2>

                                <p class="panel-subtitle">
                                    Ringkasan pengembalian kendaraan
                                </p>
                            </div>

                            <a href="pengembalian.php" class="panel-action">
                                Lihat Semua
                                <i class="bi bi-arrow-right"></i>
                            </a>
                        </div>

                        <div class="return-summary">

                            <div class="return-metric">
                                <span>Total Pengembalian</span>

                                <strong>
                                    <?= number_format($totalPengembalian, 0, ',', '.'); ?>
                                </strong>
                            </div>

                            <div class="return-metric">
                                <span>Total Denda Tercatat</span>

                                <strong class="text-red">
                                    <?= rc_h(rc_money($totalDenda)); ?>
                                </strong>
                            </div>

                        </div>

                        <?php if ($pengembalianTerbaru) : ?>

                            <div class="table-responsive dashboard-table-wrap">

                                <table class="table dashboard-table align-middle mb-0">

                                    <thead>
                                        <tr>
                                            <th>Kode Transaksi</th>
                                            <th>Pelanggan</th>
                                            <th>Kendaraan</th>
                                            <th>Tanggal Kembali</th>
                                            <th>Terlambat</th>
                                            <th>Denda</th>
                                        </tr>
                                    </thead>

                                    <tbody>

                                        <?php foreach ($pengembalianTerbaru as $data) : ?>

                                            <tr>
                                                <td>
                                                    <?= rc_h($data['kode_transaksi'] ?? '-'); ?>
                                                </td>

                                                <td>
                                                    <?= rc_h($data['nama_pelanggan'] ?? '-'); ?>
                                                </td>

                                                <td>
                                                    <?= rc_h($data['nama_kendaraan'] ?? '-'); ?>
                                                </td>

                                                <td>
                                                    <?= rc_h(rc_date($data['tanggal_pengembalian'])); ?>
                                                </td>

                                                <td>
                                                    <?= number_format((int) $data['terlambat'], 0, ',', '.'); ?>
                                                    hari
                                                </td>

                                                <td class="text-red">
                                                    <?= rc_h(rc_money($data['denda'])); ?>
                                                </td>
                                            </tr>

                                        <?php endforeach; ?>

                                    </tbody>

                                </table>

                            </div>

                        <?php else : ?>

                            <div class="empty-inline">
                                Belum ada data pengembalian.
                            </div>

                        <?php endif; ?>

                    </section>

                </section>

                <aside class="right-column">

                    <section class="panel popular-panel">

                        <div class="panel-header">
                            <h2 class="panel-title">Kendaraan Populer</h2>

                            <a href="kendaraan.php" class="panel-action">
                                Lihat Semua
                                <i class="bi bi-arrow-right"></i>
                            </a>
                        </div>

                        <?php if ($mobilPopuler) : ?>

                            <?php foreach ($mobilPopuler as $mobil) : ?>

                                <article
                                    class="popular-row searchable-item"
                                    data-search="<?= rc_h(
                                        $mobil['nama_kendaraan'] . ' ' .
                                        $mobil['merk'] . ' ' .
                                        $mobil['jenis'] . ' ' .
                                        $mobil['tipe'] . ' ' .
                                        $mobil['nomor_polisi']
                                    ); ?>"
                                >

                                    <div class="popular-image">

                                        <?php if ($mobil['foto_url']) : ?>

                                            <img
                                                src="<?= rc_h($mobil['foto_url']); ?>"
                                                alt="<?= rc_h($mobil['nama_kendaraan']); ?>"
                                                loading="lazy"
                                            >

                                        <?php else : ?>

                                            <div class="car-image-placeholder">
                                                <i class="bi bi-car-front"></i>
                                            </div>

                                        <?php endif; ?>

                                    </div>

                                    <div class="popular-info">

                                        <div class="popular-name">
                                            <?= rc_h($mobil['nama_kendaraan']); ?>
                                        </div>

                                        <div class="popular-price">
                                            <?= rc_h(rc_money($mobil['harga_sewa'])); ?>
                                            <small>/ hari</small>
                                        </div>

                                        <div class="popular-status">
                                            <?= rc_h($mobil['status']); ?>
                                        </div>

                                    </div>

                                    <span class="popular-count">
                                        <?= number_format((int) $mobil['jumlah_sewa'], 0, ',', '.'); ?>x
                                    </span>

                                </article>

                            <?php endforeach; ?>

                        <?php else : ?>

                            <div class="empty-state">
                                <i class="bi bi-car-front"></i>
                                Belum ada data kendaraan.
                            </div>

                        <?php endif; ?>

                    </section>

                    <section class="panel recent-panel">

                        <div class="panel-header">
                            <h2 class="panel-title">Transaksi Terbaru</h2>

                            <a href="transaksi.php" class="panel-action">
                                Lihat Semua
                                <i class="bi bi-arrow-right"></i>
                            </a>
                        </div>

                        <?php if ($transaksiTerbaru) : ?>

                            <?php foreach ($transaksiTerbaru as $transaksi) : ?>

                                <?php
                                $statusClass = $transaksi['status_transaksi'] === 'Selesai'
                                    ? 'success'
                                    : 'pending';
                                ?>

                                <article
                                    class="recent-row searchable-item"
                                    data-search="<?= rc_h(
                                        $transaksi['kode_transaksi'] . ' ' .
                                        ($transaksi['nama_pelanggan'] ?? '') . ' ' .
                                        ($transaksi['nama_kendaraan'] ?? '')
                                    ); ?>"
                                >

                                    <div class="recent-image">

                                        <?php if ($transaksi['foto_url']) : ?>

                                            <img
                                                src="<?= rc_h($transaksi['foto_url']); ?>"
                                                alt="<?= rc_h($transaksi['nama_kendaraan'] ?? 'Kendaraan'); ?>"
                                                loading="lazy"
                                            >

                                        <?php else : ?>

                                            <div class="car-image-placeholder">
                                                <i class="bi bi-car-front"></i>
                                            </div>

                                        <?php endif; ?>

                                    </div>

                                    <div class="recent-info">

                                        <div class="recent-name">
                                            <?= rc_h($transaksi['nama_kendaraan'] ?: 'Kendaraan'); ?>
                                        </div>

                                        <div class="recent-customer">
                                            <?= rc_h($transaksi['nama_pelanggan'] ?: 'Pelanggan'); ?>
                                        </div>

                                        <div class="recent-date">
                                            <?= rc_h(rc_date($transaksi['created_at'])); ?>
                                        </div>

                                        <div class="payment-label">
                                            <?= rc_h($transaksi['status_pembayaran']); ?>
                                        </div>

                                    </div>

                                    <span class="status-badge <?= rc_h($statusClass); ?>">
                                        <?= rc_h($transaksi['status_transaksi']); ?>
                                    </span>

                                </article>

                            <?php endforeach; ?>

                        <?php else : ?>

                            <div class="empty-state">
                                <i class="bi bi-receipt"></i>
                                Belum ada transaksi.
                            </div>

                        <?php endif; ?>

                    </section>

                </aside>

            </div>

        </main>

    </div>

</div>

<script>
window.RentCarDashboard = {
    chartLabels: <?= json_encode(
        $grafikTanggal,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
    ); ?>,

    chartValues: <?= json_encode(
        $grafikJumlah,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
    ); ?>,

    typeLabels: <?= json_encode(
        $labelTipe,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
    ); ?>,

    typeValues: <?= json_encode(
        $jumlahTipe,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
    ); ?>
};
</script>

</body>
</html>