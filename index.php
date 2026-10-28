<?php
session_start();

require_once "config/koneksi.php";

mysqli_set_charset($koneksi, "utf8mb4");

/* SESSION */
$loggedIn = isset($_SESSION['login']) && $_SESSION['login'] === true;
$level = $_SESSION['level'] ?? null;
$namaPengguna = $_SESSION['nama'] ?? $_SESSION['username'] ?? '';
$isStaff = $loggedIn && in_array($level, ['admin', 'petugas'], true);
$isCustomer = $loggedIn && !$isStaff;

/* HELPER */
function home_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function home_money($amount): string
{
    return 'Rp ' . number_format((float) $amount, 0, ',', '.');
}

function home_bind(mysqli_stmt $stmt, string $types, array &$params): void
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

/* FOTO KENDARAAN: mendukung URL dan path file lokal. */
function home_photo($photo): ?string
{
    $photo = trim((string) $photo);

    if ($photo === '') {
        return null;
    }

    if (
        preg_match('#^https?://#i', $photo) &&
        filter_var($photo, FILTER_VALIDATE_URL)
    ) {
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
            'images/' . $photo,
        ]);
    }

    $root = realpath(__DIR__);

    if (!$root) {
        return null;
    }

    $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    foreach (array_unique($candidates) as $candidate) {
        $filePath = $root . DIRECTORY_SEPARATOR . str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $candidate
        );

        $realPath = realpath($filePath);

        if (
            !$realPath ||
            !is_file($realPath) ||
            strncmp($realPath, $prefix, strlen($prefix)) !== 0
        ) {
            continue;
        }

        $relative = substr($realPath, strlen($prefix));
        $relative = str_replace('\\', '/', $relative);

        return implode('/', array_map('rawurlencode', explode('/', $relative)));
    }

    return null;
}

function home_url(string $search, string $jenis = '', int $page = 1): string
{
    $params = [];

    if ($search !== '') {
        $params['cari'] = $search;
    }

    if (in_array($jenis, ['Mobil', 'Motor'], true)) {
        $params['jenis'] = $jenis;
    }

    if ($page > 1) {
        $params['hal'] = $page;
    }

    return 'index.php' . ($params ? '?' . http_build_query($params) : '');
}

/* ENDPOINT SEARCH REALTIME */
if (($_GET['ajax'] ?? '') === 'vehicles') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');

    $query = trim((string) ($_GET['q'] ?? ''));
    $query = function_exists('mb_substr')
        ? mb_substr($query, 0, 80)
        : substr($query, 0, 80);

    $jenis = (string) ($_GET['jenis'] ?? '');

    if (!in_array($jenis, ['Mobil', 'Motor'], true)) {
        $jenis = '';
    }

    $where = "WHERE status IN ('Tersedia', 'Disewa', 'Perawatan')";
    $types = '';
    $params = [];

    if ($jenis !== '') {
        $where .= ' AND jenis = ?';
        $types .= 's';
        $params[] = $jenis;
    }

    if ($query !== '') {
        $where .= ' AND (nama_kendaraan LIKE ? OR merk LIKE ? OR tipe LIKE ?)';

        $keyword = '%' . $query . '%';
        $types .= 'sss';
        $params[] = $keyword;
        $params[] = $keyword;
        $params[] = $keyword;
    }

    $countStmt = $koneksi->prepare(
        "SELECT COUNT(*) AS jumlah FROM kendaraan $where"
    );

    if (!$countStmt) {
        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' => 'Pencarian gagal.',
        ]);

        exit;
    }

    home_bind($countStmt, $types, $params);
    $countStmt->execute();

    $countData = $countStmt->get_result()->fetch_assoc();
    $totalHasilAjax = (int) ($countData['jumlah'] ?? 0);

    $countStmt->close();

    $listStmt = $koneksi->prepare(
        "SELECT id_kendaraan, nama_kendaraan, jenis, merk, tipe, tahun, harga_sewa, status, foto
         FROM kendaraan
         $where
         ORDER BY id_kendaraan DESC
         LIMIT 60"
    );

    if (!$listStmt) {
        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' => 'Data kendaraan gagal dimuat.',
        ]);

        exit;
    }

    home_bind($listStmt, $types, $params);
    $listStmt->execute();

    $result = $listStmt->get_result();
    $items = [];

    while ($row = $result->fetch_assoc()) {
        $items[] = [
            'id' => (int) $row['id_kendaraan'],
            'nama' => $row['nama_kendaraan'],
            'jenis' => $row['jenis'],
            'merk' => $row['merk'],
            'tipe' => $row['tipe'],
            'tahun' => $row['tahun'],
            'harga' => home_money($row['harga_sewa']),
            'status' => (string) ($row['status'] ?? 'Tersedia'),
            'foto_url' => home_photo($row['foto']),
        ];
    }

    $listStmt->close();

    echo json_encode([
        'success' => true,
        'count' => $totalHasilAjax,
        'shown' => count($items),
        'items' => $items,
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

    exit;
}

/* STATISTIK */
$totalTersedia = 0;
$totalMobil = 0;
$totalMotor = 0;

$statsResult = $koneksi->query(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(status = 'Tersedia'), 0) AS total_tersedia,
            COALESCE(SUM(jenis = 'Mobil'), 0) AS total_mobil,
            COALESCE(SUM(jenis = 'Motor'), 0) AS total_motor
     FROM kendaraan
     WHERE status IN ('Tersedia', 'Disewa', 'Perawatan')"
);

if ($statsResult) {
    $stats = $statsResult->fetch_assoc();

    $totalTersedia = (int) ($stats['total_tersedia'] ?? 0);
    $totalMobil = (int) ($stats['total_mobil'] ?? 0);
    $totalMotor = (int) ($stats['total_motor'] ?? 0);
}

/* FILTER DAN PAGINATION */
$search = trim((string) ($_GET['cari'] ?? ''));
$search = function_exists('mb_substr')
    ? mb_substr($search, 0, 80)
    : substr($search, 0, 80);

$jenisFilter = (string) ($_GET['jenis'] ?? '');

if (!in_array($jenisFilter, ['Mobil', 'Motor'], true)) {
    $jenisFilter = '';
}

$perPage = 8;
$page = filter_input(INPUT_GET, 'hal', FILTER_VALIDATE_INT);
$page = max(1, (int) ($page ?: 1));

$where = "WHERE status IN ('Tersedia', 'Disewa', 'Perawatan')";
$types = '';
$params = [];

if ($jenisFilter !== '') {
    $where .= ' AND jenis = ?';
    $types .= 's';
    $params[] = $jenisFilter;
}

if ($search !== '') {
    $where .= ' AND (nama_kendaraan LIKE ? OR merk LIKE ? OR tipe LIKE ?)';

    $keyword = '%' . $search . '%';
    $types .= 'sss';
    $params[] = $keyword;
    $params[] = $keyword;
    $params[] = $keyword;
}

/* JUMLAH HASIL */
$totalHasil = 0;

$countStmt = $koneksi->prepare(
    "SELECT COUNT(*) AS jumlah FROM kendaraan $where"
);

if ($countStmt) {
    home_bind($countStmt, $types, $params);
    $countStmt->execute();

    $countData = $countStmt->get_result()->fetch_assoc();
    $totalHasil = (int) ($countData['jumlah'] ?? 0);

    $countStmt->close();
}

$totalPages = max(1, (int) ceil($totalHasil / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

/* DATA KENDARAAN */
$kendaraan = [];

$listStmt = $koneksi->prepare(
    "SELECT id_kendaraan, kode_kendaraan, nama_kendaraan, jenis, merk, tipe,
            tahun, harga_sewa, status, foto
     FROM kendaraan
     $where
     ORDER BY id_kendaraan DESC
     LIMIT $perPage OFFSET $offset"
);

if ($listStmt) {
    home_bind($listStmt, $types, $params);
    $listStmt->execute();

    $result = $listStmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $row['foto_url'] = home_photo($row['foto']);
        $kendaraan[] = $row;
    }

    $listStmt->close();
}

/* KENDARAAN UNGGULAN DARI DATABASE */
$heroVehicle = null;
$heroPhoto = null;

$heroResult = $koneksi->query(
    "SELECT id_kendaraan, nama_kendaraan, merk, jenis, tipe, harga_sewa, foto
    FROM kendaraan
    WHERE status IN ('Tersedia', 'Disewa', 'Perawatan')
    AND foto IS NOT NULL
    AND TRIM(foto) <> ''
    ORDER BY (status = 'Tersedia') DESC, id_kendaraan DESC
    LIMIT 30"
);

if ($heroResult) {
    while ($row = $heroResult->fetch_assoc()) {
        $photo = home_photo($row['foto']);

        if ($photo) {
            $heroVehicle = $row;
            $heroPhoto = $photo;
            break;
        }
    }
}

$heroName = $heroVehicle['nama_kendaraan'] ?? 'Pilihan kendaraan';
$heroMerk = $heroVehicle['merk'] ?? '';
$heroPrice = $heroVehicle['harga_sewa'] ?? 0;

/* ULASAN STATIS: ubah nama, rating, kutipan, dan foto langsung di array ini.
 * Contoh teks disediakan sebagai placeholder. Ganti dengan ulasan pelanggan asli.
 */
$ulasanPelanggan = [
    [
        'nama' => 'Elon Musk',
        'rating' => 5,
        'komentar' => '“Pelayanan RentCar sangat baik dan proses penyewaan kendaraan mudah. Pilihan kendaraan juga beragam dan kondisinya terawat.”',
        'foto' => 'assets/image/testimoni/elon.jpg',
    ],
    [
        'nama' => 'Prabowo Subianto',
        'rating' => 5,
        'komentar' => 'Website RentCar memiliki tampilan yang modern dan mudah digunakan. Saya mudah menemukan kendaraan sesuai kebutuhan.',
        'foto' => 'assets/image/testimoni/prabowo.jpg',
    ],
    [
        'nama' => 'Athifah',
        'rating' => 5,
        'komentar' => 'RentCar memberikan pengalaman penyewaan yang nyaman. Informasi kendaraan lengkap sehingga memudahkan pelanggan memilih kendaraan.',
        'foto' => 'assets/image/testimoni/athifah.jpg',
    ],
    [
        'nama' => 'Gibran Rakabuming Raka',
        'rating' => 5,
        'komentar' => 'Proses pemesanan di RentCar praktis dan informasinya jelas. Cocok untuk membantu kebutuhan transportasi sehari-hari.',
        'foto' => 'assets/image/testimoni/gibran.jpg',
    ],
];
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Lihat pilihan kendaraan, tarif sewa, dan informasi RentCar.">
    <meta name="theme-color" content="#1c1e21">

    <title>RentCar | Rental Kendaraan</title>

    <?php if ($heroPhoto) : ?>
        <link
            rel="preload"
            as="image"
            href="<?= home_h($heroPhoto); ?>"
            fetchpriority="high"
        >
    <?php endif; ?>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >
    <link
        href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@75..125,400..700&display=swap"
        rel="stylesheet"
    >
    <link
        href="https://unpkg.com/lenis@1.3.26/dist/lenis.css"
        rel="stylesheet"
    >
    <link rel="stylesheet" href="assets/css/index.css?v=4">

    <!-- Override hero GIF: sengaja dimuat setelah stylesheet utama. -->
    <!-- Halaman menggunakan stylesheet terpisah. -->

    <script src="https://unpkg.com/lenis@1.3.26/dist/lenis.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/gsap@3.13.0/dist/gsap.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/gsap@3.13.0/dist/ScrollTrigger.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" defer></script>
    <script src="assets/js/index.js?v=4" defer></script>
</head>

<body>
    <!-- NAVBAR -->
    <header class="site-header">
        <nav class="site-nav" id="siteNavWrapper">
        <a href="index.php" class="brand">
            <img src="assets/image/logo.svg" class="brand-mark" alt="logo">
            <span class="brand-text">Rent<span>Car</span></span>
        </a>

            <button
                type="button"
                class="nav-toggle"
                id="navToggle"
                aria-label="Buka menu navigasi"
                aria-expanded="false"
                aria-controls="siteNav"
            >
                <i class="bi bi-list"></i>
            </button>

            <div class="nav-links" id="siteNav">
                <a href="#beranda" class="nav-link-item active">Beranda</a>
                <a href="#kendaraan" class="nav-link-item">Kendaraan</a>
                <a href="#tentang-kami" class="nav-link-item">Tentang Kami</a>
                <a href="#cara-sewa" class="nav-link-item">Cara Sewa</a>
                <a href="#testimoni" class="nav-link-item">Testimoni</a>
                <a href="#footer" class="nav-link-item">Kontak</a>
            </div>

            <div class="nav-actions">
                <?php if (!$loggedIn) : ?>
                    <a href="login.php" class="btn-outline">Masuk</a>
                <?php elseif ($isStaff) : ?>
                    <a href="./admin/dashboard.php" class="btn-outline">Dashboard</a>
                    <a href="logout.php" class="btn-red">Keluar</a>
                <?php else : ?>
                    <a href="profil.php" class="account-name" aria-label="Buka profil pengguna">
                        <i class="bi bi-person-circle" aria-hidden="true"></i>
                        <?= home_h($namaPengguna); ?>
                    </a>
                    <a href="logout.php" class="btn-outline">Keluar</a>
                <?php endif; ?>
            </div>
        </nav>
    </header>

    <main>
        <!-- HERO -->
        <section class="hero-section" id="beranda">
            <div class="hero-background" id="heroBackground">
                <img
                    src="assets/image/image.gif?v=3"
                    alt=""
                    aria-hidden="true"
                    class="hero-background-image"
                    width="1920"
                    height="1080"
                    fetchpriority="high"
                    decoding="async"
                    onerror="this.style.display='none'"
                >
            </div>

            <div class="hero-overlay"></div>

            <div class="hero-content">
                <div class="hero-copy">
                    <h1>
                        Kendaraan yang tepat untuk <span>perjalanan Anda.</span>
                    </h1>

                    <p>
                        Solusi Sewa Kendaraan Terpercaya
                    </p>

                    <div class="hero-actions">
                        <a href="#kendaraan" class="btn-red btn-large">
                            Jelajahi Kendaraan <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                </div>

                <?php if ($heroVehicle) : ?>
                    <div class="hero-vehicle-card">
                        <div class="hero-vehicle-label">PILIHAN KENDARAAN</div>

                        <h2><?= home_h($heroName); ?></h2>

                        <p>
                            <?= home_h($heroMerk); ?>

                            <?php if (!empty($heroVehicle['tipe'])) : ?>
                                <span>·</span>
                                <?= home_h($heroVehicle['tipe']); ?>
                            <?php endif; ?>
                        </p>

                        <div class="hero-price">
                            <?= home_h(home_money($heroPrice)); ?>
                            <span>/ hari</span>
                        </div>
                    </div>
                <?php endif; ?>

                
            </div>
        </section>

        <!-- KATALOG KENDARAAN -->
        <section
            class="catalog-section cover-section"
            id="kendaraan"
            data-account-type="<?= $isStaff ? 'staff' : ($isCustomer ? 'customer' : 'guest'); ?>"
        >
            <div class="content-container">
                <div class="section-heading">
                    <div>
                        <div class="section-kicker">KATALOG RENTCAR</div>
                        <h2>Katalog kendaraan</h2>
                        <p>
                            Lihat kendaraan yang tersedia, sedang disewa, atau dalam perawatan.
                        </p>
                    </div>

                    <div class="catalog-count">
                        <strong id="vehicleResultCount">
                            <?= number_format($totalHasil, 0, ',', '.'); ?>
                        </strong>
                        <span id="vehicleResultLabel">kendaraan</span>
                    </div>
                </div>

                <div class="catalog-toolbar">
                    <form
                        action="index.php"
                        method="get"
                        class="catalog-search"
                        id="catalogSearchForm"
                        role="search"
                    >
                        <i class="bi bi-search"></i>

                        <input
                            type="search"
                            id="catalogSearchInput"
                            name="cari"
                            value="<?= home_h($search); ?>"
                            placeholder="Cari nama kendaraan, merek, atau tipe..."
                            aria-label="Cari kendaraan"
                            maxlength="80"
                            autocomplete="off"
                        >

                        <?php if ($jenisFilter !== '') : ?>
                            <input
                                type="hidden"
                                name="jenis"
                                value="<?= home_h($jenisFilter); ?>"
                            >
                        <?php endif; ?>

                        <button type="submit" class="search-submit">Cari</button>
                    </form>

                    <div class="filter-tabs">
                        <a
                            class="filter-tab <?= $jenisFilter === '' ? 'active' : ''; ?>"
                            href="<?= home_h(home_url($search)); ?>"
                        >
                            Semua
                        </a>

                        <a
                            class="filter-tab <?= $jenisFilter === 'Mobil' ? 'active' : ''; ?>"
                            href="<?= home_h(home_url($search, 'Mobil')); ?>"
                        >
                            <i class="bi bi-car-front"></i>
                            Mobil
                            <span><?= number_format($totalMobil, 0, ',', '.'); ?></span>
                        </a>

                        <a
                            class="filter-tab <?= $jenisFilter === 'Motor' ? 'active' : ''; ?>"
                            href="<?= home_h(home_url($search, 'Motor')); ?>"
                        >
                            <i class="bi bi-bicycle"></i>
                            Motor
                            <span><?= number_format($totalMotor, 0, ',', '.'); ?></span>
                        </a>
                    </div>
                </div>

                <?php if ($kendaraan) : ?>
                    <div class="vehicle-grid" id="vehicleGrid">
                        <?php foreach ($kendaraan as $item) : ?>
                            <article class="vehicle-card">
                                <div
                                    class="vehicle-media <?= $item['foto_url'] ? 'skeleton-media' : 'no-image'; ?>"
                                >
                                    <?php if ($item['foto_url']) : ?>
                                        <img
                                            src="<?= home_h($item['foto_url']); ?>"
                                            alt="<?= home_h($item['nama_kendaraan']); ?>"
                                            class="media-image"
                                            data-skeleton
                                            width="600"
                                            height="400"
                                            loading="lazy"
                                            decoding="async"
                                        >
                                    <?php endif; ?>

                                    <div class="image-placeholder">
                                        <i class="bi bi-car-front"></i>
                                        <span>Foto belum tersedia</span>
                                    </div>

                                    <span class="vehicle-type">
                                        <?= home_h($item['tipe'] ?: $item['jenis']); ?>
                                    </span>

                                    <?php
                                    $vehicleStatus = (string) ($item['status'] ?? 'Tersedia');
                                    $statusClass = $vehicleStatus === 'Disewa'
                                        ? 'status-rented'
                                        : ($vehicleStatus === 'Perawatan' ? 'status-maintenance' : 'status-available');
                                    ?>
                                    <span class="availability-badge <?= home_h($statusClass); ?>">
                                        <span class="availability-dot"></span>
                                        <span class="availability-label"><?= home_h($vehicleStatus); ?></span>
                                    </span>
                                </div>

                                <div class="vehicle-body">
                                    <div class="vehicle-make">
                                        <?= home_h($item['merk']); ?>

                                        <?php if (!empty($item['tahun'])) : ?>
                                            <span>·</span>
                                            <?= home_h($item['tahun']); ?>
                                        <?php endif; ?>
                                    </div>

                                    <h3 class="vehicle-name">
                                        <?= home_h($item['nama_kendaraan']); ?>
                                    </h3>

                                    <div class="vehicle-divider"></div>

                                    <div class="vehicle-footer">
                                        <div class="vehicle-price">
                                            <strong>
                                                <?= home_h(home_money($item['harga_sewa'])); ?>
                                            </strong>
                                            <span>/ hari</span>
                                        </div>

                                        <?php if ($isStaff) : ?>
                                            <a
                                                href="./admin/kendaraan.php"
                                                class="vehicle-action"
                                                aria-label="Kelola kendaraan <?= home_h($item['nama_kendaraan']); ?>"
                                            >
                                                <i class="bi bi-arrow-up-right"></i>
                                            </a>
                                        <?php elseif (($item['status'] ?? 'Tersedia') !== 'Tersedia') : ?>
                                            <span
                                                class="vehicle-action vehicle-action-disabled"
                                                title="Kendaraan sedang <?= home_h(strtolower((string) $item['status'])); ?>"
                                                aria-label="Kendaraan sedang <?= home_h(strtolower((string) $item['status'])); ?>"
                                            >
                                                <i class="bi bi-lock-fill"></i>
                                            </span>
                                        <?php else : ?>
                                            <a
                                                href="<?= $isCustomer ? 'kendaraan.php?sewa=' . (int) $item['id_kendaraan'] : 'login.php'; ?>"
                                                class="vehicle-action"
                                                aria-label="Sewa kendaraan <?= home_h($item['nama_kendaraan']); ?>"
                                            >
                                                <i class="bi bi-arrow-up-right"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>

                                    <?php if (!$loggedIn && ($item['status'] ?? 'Tersedia') === 'Tersedia') : ?>
                                        <a href="login.php" class="vehicle-login-link">
                                            Masuk untuk melanjutkan
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else : ?>
                    <div class="catalog-empty" id="catalogEmpty">
                        <div class="empty-icon">
                            <i class="bi bi-car-front"></i>
                        </div>

                        <?php if ($search !== '' || $jenisFilter !== '') : ?>
                            <h3>Kendaraan tidak ditemukan</h3>
                            <p>Coba kata pencarian atau filter lainnya.</p>
                            <a href="index.php#kendaraan" class="btn-red">
                                Reset pencarian
                            </a>
                        <?php else : ?>
                            <h3>Belum ada kendaraan terdaftar</h3>
                            <p>
                                Kendaraan akan tampil setelah admin atau petugas menambahkan data kendaraan.
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <nav
                    class="catalog-pagination"
                    id="catalogPagination"
                    aria-label="Halaman katalog"
                    <?= $totalPages <= 1 ? 'hidden' : ''; ?>
                >
                    <?php if ($page > 1) : ?>
                        <a
                            class="page-link-custom"
                            href="<?= home_h(home_url($search, $jenisFilter, $page - 1)); ?>"
                            aria-label="Halaman sebelumnya"
                        >
                            <i class="bi bi-chevron-left"></i>
                        </a>
                    <?php endif; ?>

                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);

                    for ($p = $startPage; $p <= $endPage; $p++) :
                    ?>
                        <a
                            class="page-link-custom <?= $p === $page ? 'active' : ''; ?>"
                            href="<?= home_h(home_url($search, $jenisFilter, $p)); ?>"
                            <?= $p === $page ? 'aria-current="page"' : ''; ?>
                        >
                            <?= $p; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages) : ?>
                        <a
                            class="page-link-custom"
                            href="<?= home_h(home_url($search, $jenisFilter, $page + 1)); ?>"
                            aria-label="Halaman berikutnya"
                        >
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </nav>

                <div
                    class="catalog-live-message"
                    id="catalogLiveMessage"
                    aria-live="polite"
                ></div>
            </div>
        </section>

        <!-- TENTANG KAMI -->
        <section class="about-section" id="tentang-kami">
            <div class="about-container">
                <div class="about-copy">
                    <div class="section-kicker">TENTANG RENTCAR</div>
                    <h2>Layanan rental kendaraan dengan informasi yang jelas.</h2>

                    <p>
                        RentCar adalah layanan penyewaan kendaraan yang dirancang untuk memudahkan pelanggan menemukan kendaraan sesuai kebutuhan. Kami menyediakan informasi kendaraan secara lengkap agar pelanggan lebih mudah memilih kendaraan untuk perjalanan pribadi, keluarga, maupun keperluan lainnya. Dengan tampilan website yang sederhana dan proses pemesanan yang praktis, RentCar hadir sebagai solusi transportasi yang nyaman dan efisien.
                    </p>

                    <a href="#kendaraan" class="btn-red">
                        Lihat Katalog <i class="bi bi-arrow-right"></i>
                    </a>
                </div>

                <div class="about-details">
                    <article class="about-detail-card">
                        <span class="about-detail-index">01</span>

                        <div class="about-detail-icon">
                            <i class="bi bi-card-list"></i>
                        </div>

                        <div>
                            <h3>Informasi kendaraan</h3>
                            <p>
                                Nama kendaraan, foto, tipe, dan tarif ditampilkan dari database.
                            </p>
                        </div>
                    </article>

                    <article class="about-detail-card">
                        <span class="about-detail-index">02</span>

                        <div class="about-detail-icon">
                            <i class="bi bi-search"></i>
                        </div>

                        <div>
                            <h3>Pencarian praktis</h3>
                            <p>Cari kendaraan berdasarkan nama, merek, atau tipe.</p>
                        </div>
                    </article>

                    <article class="about-detail-card">
                        <span class="about-detail-index">03</span>

                        <div class="about-detail-icon">
                            <i class="bi bi-person-check"></i>
                        </div>

                        <div>
                            <h3>Akun sesuai kebutuhan</h3>
                            <p>
                                Pengunjung melihat katalog, pelanggan masuk ke website,
                                dan staf mengelola operasional.
                            </p>
                        </div>
                    </article>
                </div>
            </div>
        </section>

        <!-- CARA SEWA -->
        <section class="process-section" id="cara-sewa">
            <div class="section-container">
                <div class="section-heading">
                    <div>
                        <div class="section-kicker">LANGKAH MUDAH</div>
                        <h2>Cara menyewa kendaraan</h2>
                        <p>
                            Ikuti langkah berikut untuk memulai proses sewa di RentCar.
                        </p>
                    </div>
                </div>

                <div class="process-grid">
                    <article class="process-card">
                        <span class="process-number">01</span>

                        <div class="process-icon">
                            <i class="bi bi-search"></i>
                        </div>

                        <h3>Pilih kendaraan</h3>
                        <p>
                            Buka katalog, cari kendaraan, lalu periksa jenis, foto, dan
                            tarif sewa per hari.
                        </p>

                        <a href="#kendaraan" class="process-link">
                            Lihat kendaraan <i class="bi bi-arrow-right"></i>
                        </a>
                    </article>

                    <article class="process-card">
                        <span class="process-number">02</span>

                        <div class="process-icon">
                            <i class="bi bi-person-check"></i>
                        </div>

                        <h3>Masuk ke akun</h3>
                        <p>
                            Masuk dengan akun pelanggan untuk melanjutkan sesuai fitur
                            yang tersedia di website.
                        </p>

                        <a
                            href="<?= $loggedIn ? ($isStaff ? './admin/dashboard.php' : 'kendaraan.php') : 'login.php'; ?>"
                            class="process-link"
                        >
                            <?= $loggedIn ? 'Lanjutkan' : 'Masuk sekarang'; ?>
                            <i class="bi bi-arrow-right"></i>
                        </a>
                    </article>

                    <article class="process-card">
                        <span class="process-number">03</span>

                        <div class="process-icon">
                            <i class="bi bi-clipboard-check"></i>
                        </div>

                        <h3>Konfirmasi penyewaan</h3>
                        <p>
                            Periksa kembali data kendaraan dan detail sewa, lalu ikuti
                            petunjuk konfirmasi dari petugas.
                        </p>

                        <a href="#footer" class="process-link">
                            Hubungi kami <i class="bi bi-arrow-right"></i>
                        </a>
                    </article>
                </div>
            </div>
        </section>

        <!-- TESTIMONI -->
        <section class="testimonial-section" id="testimoni">
            <div class="testimonial-container">
                <div class="testimonial-heading">
                    <div>
                        <div class="section-kicker">ULASAN PELANGGAN</div>
                        <h2>Pengalaman pelanggan</h2>
                    </div>
                </div>

                <?php if (!empty($ulasanPelanggan)) : ?>
                    <div class="testimonial-marquee">
                        <div class="testimonial-track">
                            <?php
                            /* Dua grup serupa membantu marquee berjalan mulus. */
                            for ($groupIndex = 0; $groupIndex < 2; $groupIndex++) :
                            ?>
                                <div class="testimonial-group" <?= $groupIndex === 1 ? 'aria-hidden="true"' : ''; ?>>
                                    <?php foreach ($ulasanPelanggan as $ulasan) :
                                        $rating = max(1, min(5, (int) ($ulasan['rating'] ?? 5)));
                                        $namaUlasan = trim((string) ($ulasan['nama'] ?? 'Pelanggan'));

                                        $inisial = function_exists('mb_substr')
                                            ? mb_strtoupper(mb_substr($namaUlasan, 0, 1))
                                            : strtoupper(substr($namaUlasan, 0, 1));
                                    ?>
                                        <article class="testimonial-card">
                                            <div
                                                class="testimonial-rating"
                                                aria-label="Rating <?= $rating; ?> dari 5"
                                            >
                                                <?php for ($star = 1; $star <= 5; $star++) : ?>
                                                    <i class="bi <?= $star <= $rating ? 'bi-star-fill' : 'bi-star'; ?>"></i>
                                                <?php endfor; ?>
                                            </div>

                                            <blockquote>
                                                <?= home_h($ulasan['komentar'] ?? ''); ?>
                                            </blockquote>

                                            <div class="testimonial-person">
                                                <div class="testimonial-avatar">
                                                    <span class="testimonial-avatar-fallback"><?= home_h($inisial !== '' ? $inisial : 'P'); ?></span>
                                                    <img
                                                        src="<?= home_h($ulasan['foto'] ?? ''); ?>"
                                                        alt="Foto <?= home_h($namaUlasan); ?>"
                                                        loading="lazy"
                                                        decoding="async"
                                                        onerror="this.style.display='none'"
                                                    >
                                                </div>

                                                <div class="testimonial-person-info">
                                                    <strong>
                                                        <?= home_h($namaUlasan !== '' ? $namaUlasan : 'Pelanggan'); ?>
                                                    </strong>
                                                    <span>Pelanggan RentCar</span>
                                                </div>
                                            </div>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                <?php else : ?>
                    <div class="testimonial-empty">
                        <div class="empty-icon">
                            <i class="bi bi-chat-quote"></i>
                        </div>

                        <h3>Ulasan belum tersedia</h3>
                        <p>
                            Testimoni pelanggan akan muncul di sini setelah ulasan
                            disimpan dan berstatus Tampil.
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>

    <!-- FOOTER / KONTAK -->
    <footer class="site-footer" id="footer">
        <div class="footer-main">
            <div class="footer-column">
            <a href="index.php" class="brand">
            <img src="assets/image/logo.svg" class="brand-mark" alt="logo">
            <span class="brand-text">Rent<span>Car</span></span>
        </a>

                <p>
                    RentCar adalah platform penyewaan kendaraan yang dirancang untuk memberikan
                    kemudahan bagi setiap pelanggan dalam menemukan kendaraan sesuai kebutuhan.
                    Kami berkomitmen menghadirkan informasi kendaraan yang lengkap, proses 
                    penyewaan yang praktis, serta pengalaman penggunaan website yang nyaman dan 
                    mudah dipahami.
                </p>
            </div>

            <div class="footer-column">
                <h3>Navigasi</h3>
                <a href="#beranda">Beranda</a>
                <a href="#kendaraan">Kendaraan</a>
                <a href="#tentang-kami">Tentang Kami</a>
                <a href="#cara-sewa">Cara Sewa</a>
                <a href="#testimoni">Testimoni</a>
            </div>

            <div class="footer-column">
                <h3>Layanan</h3>
                <a href="#kendaraan">Katalog Mobil</a>
                <a href="#kendaraan">Katalog Motor</a>
                <a href="<?= $loggedIn ? ($isStaff ? './admin/dashboard.php' : 'kendaraan.php') : 'login.php'; ?>">
                    <?= $loggedIn ? ($isStaff ? 'Dashboard' : 'Akun Saya') : 'Masuk ke Akun'; ?>
                </a>
                <a href="#cara-sewa">Panduan Penyewaan</a>
            </div>

            <div class="footer-column">
                <h3>Kontak</h3>

                <a href="https://www.instagram.com/raull752/">
                    <i class="bi bi-instagram"> Instagram</i>
                </a>

                <a href="https://wa.me/6281347003870">
                    <i class="bi bi-whatsapp"> WhatsApp</i>
                </a>

                <a href="https://www.facebook.com/profile.php?id=100035907471876&mibextid=wwXIfr&mibextid=wwXIfr">
                    <i class="bi bi-facebook"> Facebook</i>
                </a>

            </div>
        </div>

        <div class="footer-bottom">
            <span>&copy; <?= date('Y'); ?> Muhammad Raul Zia Parsa || RentCar. All rights reserved.</span>

            <a href="#beranda">
                <span>Kembali ke atas</span>
                <i class="bi bi-arrow-up"></i>
            </a>
        </div>
    </footer>
</body>

</html>
