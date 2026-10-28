<?php

session_start();
require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/kendaraan_helper.php';
require_once __DIR__ . '/../config/rentcar_automation.php';

mysqli_set_charset($koneksi, 'utf8mb4');
try {
    rc_refresh_automation($koneksi);
} catch (Throwable $refreshError) {
    error_log('[RentCar vehicle list refresh] ' . $refreshError->getMessage());
}

if (($_GET['ajax'] ?? '') === 'statuses') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    try {
        $statusResult = $koneksi->query('SELECT id_kendaraan, status FROM kendaraan ORDER BY id_kendaraan');
        $statusRows = $statusResult->fetch_all(MYSQLI_ASSOC);
        $statResult = $koneksi->query(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(status='Tersedia'),0) AS tersedia,
                    COALESCE(SUM(status='Disewa'),0) AS disewa,
                    COALESCE(SUM(status='Perawatan'),0) AS perawatan
             FROM kendaraan"
        );
        echo json_encode([
            'success' => true,
            'vehicles' => array_map(static fn(array $row): array => [
                'id' => (int) $row['id_kendaraan'],
                'status' => (string) $row['status'],
            ], $statusRows),
            'stats' => $statResult->fetch_assoc() ?: [],
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    } catch (Throwable $statusError) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Status kendaraan gagal diperbarui.']);
    }
    exit;
}

$flash = kh_flash_get();
$search = trim((string) ($_GET['q'] ?? ''));
$search = function_exists('mb_substr')
    ? mb_substr($search, 0, 100)
    : substr($search, 0, 100);

$jenisFilter = (string) ($_GET['jenis'] ?? '');
if (!in_array($jenisFilter, ['Mobil', 'Motor'], true)) {
    $jenisFilter = '';
}

$statusFilter = (string) ($_GET['status'] ?? '');
$statusOptions = ['Tersedia', 'Disewa', 'Perawatan'];

if (!in_array($statusFilter, $statusOptions, true)) {
    $statusFilter = '';
}

$perPage = 10;
$page = filter_input(INPUT_GET, 'hal', FILTER_VALIDATE_INT);
$page = max(1, (int) ($page ?: 1));

$whereParts = ['1 = 1'];
$types = '';
$params = [];

if ($jenisFilter !== '') {
    $whereParts[] = 'jenis = ?';
    $types .= 's';
    $params[] = $jenisFilter;
}

if ($statusFilter !== '') {
    $whereParts[] = 'status = ?';
    $types .= 's';
    $params[] = $statusFilter;
}

if ($search !== '') {
    $whereParts[] = '(
        kode_kendaraan LIKE ?
        OR nama_kendaraan LIKE ?
        OR merk LIKE ?
        OR tipe LIKE ?
        OR nomor_polisi LIKE ?
    )';

    $keyword = '%' . $search . '%';
    $types .= 'sssss';

    for ($i = 0; $i < 5; $i++) {
        $params[] = $keyword;
    }
}

$whereSql = 'WHERE ' . implode(' AND ', $whereParts);
$pageError = '';
$stats = [];
$totalRows = 0;
$totalPages = 1;
$vehicles = [];

try {
    $statsResult = $koneksi->query(
        "SELECT
            COUNT(*) AS total,
            COALESCE(SUM(status = 'Tersedia'), 0) AS tersedia,
            COALESCE(SUM(status = 'Disewa'), 0) AS disewa,
            COALESCE(SUM(status = 'Perawatan'), 0) AS perawatan
         FROM kendaraan"
    );

    $stats = $statsResult ? $statsResult->fetch_assoc() : [];

    $countStmt = $koneksi->prepare(
        "SELECT COUNT(*) AS jumlah
         FROM kendaraan
         $whereSql"
    );

    kh_bind($countStmt, $types, $params);
    $countStmt->execute();
    $countData = $countStmt->get_result()->fetch_assoc();
    $totalRows = (int) ($countData['jumlah'] ?? 0);
    $countStmt->close();

    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $listStmt = $koneksi->prepare(
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
         $whereSql
         ORDER BY id_kendaraan DESC
         LIMIT ? OFFSET ?"
    );

    $listTypes = $types . 'ii';
    $listParams = $params;
    $listParams[] = $perPage;
    $listParams[] = $offset;

    kh_bind($listStmt, $listTypes, $listParams);
    $listStmt->execute();
    $result = $listStmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $row['foto_url'] = kh_photo_url($row['foto'] ?? '');
        $vehicles[] = $row;
    }

    $listStmt->close();
} catch (Throwable $exception) {
    $pageError = 'Data kendaraan gagal dimuat. Periksa koneksi dan struktur tabel kendaraan.';
    $stats = $stats ?? [];
}

$baseParams = [
    'q' => $search,
    'jenis' => $jenisFilter,
    'status' => $statusFilter,
];

kh_render_start('Kendaraan');
?>

<section class="vehicle-page-heading">
    <div>
        <div class="vehicle-eyebrow">DATA MASTER</div>
        <h1>Manajemen Kendaraan</h1>
        <p>Kelola informasi, tarif, status, dan foto kendaraan rental.</p>
    </div>

    <a href="tambah_kendaraan.php" class="vehicle-btn vehicle-btn-primary">
        <i class="bi bi-plus-lg"></i>
        <span>Tambah Kendaraan</span>
    </a>
</section>

<?php if ($flash) : ?>
    <div class="vehicle-alert vehicle-alert-<?= kh_h($flash['type'] ?? 'info'); ?>" role="status">
        <i class="bi <?= ($flash['type'] ?? '') === 'success' ? 'bi-check-circle' : 'bi-exclamation-circle'; ?>"></i>
        <span><?= kh_h($flash['message'] ?? ''); ?></span>
        <button type="button" class="vehicle-alert-close" aria-label="Tutup pesan">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>
<?php endif; ?>

<?php if ($pageError !== '') : ?>
    <div class="vehicle-alert vehicle-alert-error" role="alert">
        <i class="bi bi-exclamation-triangle"></i>
        <span><?= kh_h($pageError); ?></span>
    </div>
<?php endif; ?>

<section class="vehicle-stats" aria-label="Ringkasan kendaraan">
    <article class="vehicle-stat-card">
        <span class="vehicle-stat-icon"><i class="bi bi-car-front"></i></span>
        <div>
            <span class="vehicle-stat-label">Total kendaraan</span>
            <strong data-vehicle-stat="total"><?= number_format((int) ($stats['total'] ?? 0), 0, ',', '.'); ?></strong>
        </div>
    </article>

    <article class="vehicle-stat-card">
        <span class="vehicle-stat-icon vehicle-stat-icon-green"><i class="bi bi-check-circle"></i></span>
        <div>
            <span class="vehicle-stat-label">Tersedia</span>
            <strong data-vehicle-stat="tersedia"><?= number_format((int) ($stats['tersedia'] ?? 0), 0, ',', '.'); ?></strong>
        </div>
    </article>

    <article class="vehicle-stat-card">
        <span class="vehicle-stat-icon vehicle-stat-icon-blue"><i class="bi bi-key"></i></span>
        <div>
            <span class="vehicle-stat-label">Sedang disewa</span>
            <strong data-vehicle-stat="disewa"><?= number_format((int) ($stats['disewa'] ?? 0), 0, ',', '.'); ?></strong>
        </div>
    </article>

    <article class="vehicle-stat-card">
        <span class="vehicle-stat-icon vehicle-stat-icon-amber"><i class="bi bi-tools"></i></span>
        <div>
            <span class="vehicle-stat-label">Perawatan</span>
            <strong data-vehicle-stat="perawatan"><?= number_format((int) ($stats['perawatan'] ?? 0), 0, ',', '.'); ?></strong>
        </div>
    </article>
</section>

<section class="vehicle-panel" data-status-filter="<?= kh_h($statusFilter); ?>">
    <div class="vehicle-panel-heading">
        <div>
            <h2>Daftar Kendaraan</h2>
            <p><?= number_format($totalRows, 0, ',', '.'); ?> data sesuai filter.</p>
        </div>
    </div>

    <form method="get" action="kendaraan.php" class="vehicle-filters" role="search">
        <label class="vehicle-search-field">
            <i class="bi bi-search"></i>
            <input
                type="search"
                name="q"
                value="<?= kh_h($search); ?>"
                placeholder="Cari kode, nama, merek, atau nomor polisi..."
                maxlength="100"
                autocomplete="off"
            >
        </label>

        <label class="vehicle-select-field">
            <span>Jenis</span>
            <select name="jenis">
                <option value="">Semua jenis</option>
                <option value="Mobil" <?= $jenisFilter === 'Mobil' ? 'selected' : ''; ?>>Mobil</option>
                <option value="Motor" <?= $jenisFilter === 'Motor' ? 'selected' : ''; ?>>Motor</option>
            </select>
        </label>

        <label class="vehicle-select-field">
            <span>Status</span>
            <select name="status">
                <option value="">Semua status</option>
                <?php foreach ($statusOptions as $statusOption) : ?>
                    <option value="<?= kh_h($statusOption); ?>" <?= $statusFilter === $statusOption ? 'selected' : ''; ?>>
                        <?= kh_h($statusOption); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <button type="submit" class="vehicle-btn vehicle-btn-secondary">
            <i class="bi bi-funnel"></i>
            <span>Filter</span>
        </button>

        <?php if ($search !== '' || $jenisFilter !== '' || $statusFilter !== '') : ?>
            <a href="kendaraan.php" class="vehicle-btn vehicle-btn-quiet">Reset</a>
        <?php endif; ?>
    </form>

    <div class="vehicle-table-wrap">
        <table class="vehicle-table">
            <thead>
                <tr>
                    <th scope="col">Kendaraan</th>
                    <th scope="col">Kode / Polisi</th>
                    <th scope="col">Jenis</th>
                    <th scope="col">Tarif per hari</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="vehicle-actions-heading">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($vehicles) : ?>
                    <?php foreach ($vehicles as $vehicle) : ?>
                        <?php
                        $statusClass = strtolower((string) $vehicle['status']);
                        $statusClass = preg_replace('/[^a-z0-9-]/', '', $statusClass);
                        ?>
                        <tr data-vehicle-row="<?= (int) $vehicle['id_kendaraan']; ?>">
                            <td>
                                <div class="vehicle-cell-main">
                                    <div class="vehicle-thumb">
                                        <?php if (!empty($vehicle['foto_url'])) : ?>
                                            <img
                                                src="<?= kh_h($vehicle['foto_url']); ?>"
                                                alt="<?= kh_h($vehicle['nama_kendaraan']); ?>"
                                                loading="lazy"
                                                decoding="async"
                                            >
                                        <?php else : ?>
                                            <i class="bi bi-car-front"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="vehicle-cell-copy">
                                        <strong><?= kh_h($vehicle['nama_kendaraan']); ?></strong>
                                        <span>
                                            <?= kh_h($vehicle['merk'] ?: '-'); ?>
                                            <?php if (!empty($vehicle['tipe'])) : ?>
                                                <span class="vehicle-dot">·</span>
                                                <?= kh_h($vehicle['tipe']); ?>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="vehicle-code-copy">
                                    <strong><?= kh_h($vehicle['kode_kendaraan']); ?></strong>
                                    <span><?= kh_h($vehicle['nomor_polisi'] ?: 'Nomor polisi belum diisi'); ?></span>
                                </div>
                            </td>
                            <td>
                                <span class="vehicle-type-badge">
                                    <i class="bi <?= $vehicle['jenis'] === 'Mobil' ? 'bi-car-front' : 'bi-bicycle'; ?>"></i>
                                    <?= kh_h($vehicle['jenis']); ?>
                                </span>
                                <?php if (!empty($vehicle['tahun'])) : ?>
                                    <small class="vehicle-year"><?= kh_h($vehicle['tahun']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="vehicle-price-cell"><?= kh_h(kh_money($vehicle['harga_sewa'])); ?></td>
                            <td>
                                <span class="vehicle-status vehicle-status-<?= kh_h($statusClass); ?>" data-vehicle-status="<?= (int) $vehicle['id_kendaraan']; ?>" data-current-status="<?= kh_h($vehicle['status']); ?>">
                                    <span></span>
                                    <span data-vehicle-status-label><?= kh_h($vehicle['status']); ?></span>
                                </span>
                            </td>
                            <td>
                                <div class="vehicle-row-actions">
                                    <a
                                        href="edit_kendaraan.php?id=<?= (int) $vehicle['id_kendaraan']; ?>"
                                        class="vehicle-icon-btn vehicle-icon-edit"
                                        aria-label="Edit <?= kh_h($vehicle['nama_kendaraan']); ?>"
                                        title="Edit kendaraan"
                                    >
                                        <i class="bi bi-pencil-square"></i>
                                    </a>

                                    <form
                                        action="hapus_kendaraan.php"
                                        method="post"
                                        class="vehicle-delete-form"
                                        data-confirm-delete
                                        data-vehicle-name="<?= kh_h($vehicle['nama_kendaraan']); ?>"
                                    >
                                        <input type="hidden" name="csrf_token" value="<?= kh_h($_SESSION['csrf_kendaraan']); ?>">
                                        <input type="hidden" name="id_kendaraan" value="<?= (int) $vehicle['id_kendaraan']; ?>">
                                        <button
                                            type="submit"
                                            class="vehicle-icon-btn vehicle-icon-delete"
                                            aria-label="Hapus <?= kh_h($vehicle['nama_kendaraan']); ?>"
                                            title="Hapus kendaraan"
                                        >
                                            <i class="bi bi-trash3"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="6">
                            <div class="vehicle-empty-state">
                                <span><i class="bi bi-car-front"></i></span>
                                <strong>Data kendaraan belum ditemukan</strong>
                                <p>Coba ubah kata pencarian atau filter, atau tambahkan kendaraan baru.</p>
                                <a href="tambah_kendaraan.php" class="vehicle-btn vehicle-btn-primary">
                                    <i class="bi bi-plus-lg"></i>
                                    Tambah Kendaraan
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1) : ?>
        <nav class="vehicle-pagination" aria-label="Navigasi halaman kendaraan">
            <span>Halaman <?= $page; ?> dari <?= $totalPages; ?></span>
            <div class="vehicle-pagination-links">
                <?php if ($page > 1) : ?>
                    <a href="<?= kh_h(kh_vehicle_url($baseParams + ['hal' => $page - 1])); ?>" aria-label="Halaman sebelumnya">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                <?php endif; ?>

                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);

                for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++) :
                    $pageParams = $baseParams;
                    $pageParams['hal'] = $pageNumber;
                ?>
                    <a
                        href="<?= kh_h(kh_vehicle_url($pageParams)); ?>"
                        class="<?= $pageNumber === $page ? 'active' : ''; ?>"
                        <?= $pageNumber === $page ? 'aria-current="page"' : ''; ?>
                    >
                        <?= $pageNumber; ?>
                    </a>
                <?php endfor; ?>

                <?php if ($page < $totalPages) : ?>
                    <?php $nextParams = $baseParams; ?>
                    <?php $nextParams['hal'] = $page + 1; ?>
                    <a href="<?= kh_h(kh_vehicle_url($nextParams)); ?>" aria-label="Halaman berikutnya">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                <?php endif; ?>
            </div>
        </nav>
    <?php endif; ?>
</section>

<?php kh_render_end(); ?>
