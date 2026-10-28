<?php

session_start();

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/kendaraan_helper.php';

mysqli_set_charset($koneksi, 'utf8mb4');

$errors = [];
$flash = kh_flash_get();
$editId = filter_var($_GET['edit'] ?? 0, FILTER_VALIDATE_INT);
$editId = max(0, (int) ($editId ?: 0));
$formData = [
    'nama' => '',
    'nik' => '',
    'no_hp' => '',
    'alamat' => '',
    'jenis_kelamin' => 'Laki-laki',
    'no_sim' => '',
];

if ($editId > 0) {
    $editStmt = $koneksi->prepare(
        'SELECT id_pelanggan, nama, nik, no_hp, alamat, jenis_kelamin, no_sim
         FROM pelanggan
         WHERE id_pelanggan = ?
         LIMIT 1'
    );
    $editStmt->bind_param('i', $editId);
    $editStmt->execute();
    $editCustomer = $editStmt->get_result()->fetch_assoc();
    $editStmt->close();

    if ($editCustomer) {
        foreach ($formData as $field => $value) {
            $formData[$field] = (string) ($editCustomer[$field] ?? $value);
        }
    } else {
        $editId = 0;
        kh_flash_set('error', 'Data pelanggan yang akan diedit tidak ditemukan.');
        header('Location: pelanggan.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!kh_csrf_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Permintaan tidak valid. Muat ulang halaman, lalu coba kembali.');
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'simpan_pelanggan') {
        $editId = max(0, (int) ($_POST['id_pelanggan'] ?? 0));

        foreach ($formData as $field => $default) {
            $formData[$field] = trim((string) ($_POST[$field] ?? ''));
        }

        if ($formData['nama'] === '' || kh_length($formData['nama']) > 100) {
            $errors[] = 'Nama wajib diisi dan maksimal 100 karakter.';
        }

        if ($formData['nik'] === '' || kh_length($formData['nik']) > 30) {
            $errors[] = 'NIK wajib diisi dan maksimal 30 karakter.';
        }

        if ($formData['no_hp'] === '' || kh_length($formData['no_hp']) > 20) {
            $errors[] = 'Nomor HP wajib diisi dan maksimal 20 karakter.';
        }

        if ($formData['alamat'] === '') {
            $errors[] = 'Alamat wajib diisi.';
        }

        if (!in_array($formData['jenis_kelamin'], ['Laki-laki', 'Perempuan'], true)) {
            $errors[] = 'Jenis kelamin tidak valid.';
        }

        if ($formData['no_sim'] === '' || kh_length($formData['no_sim']) > 50) {
            $errors[] = 'Nomor SIM wajib diisi dan maksimal 50 karakter.';
        }

        if (!$errors) {
            $duplicateStmt = $koneksi->prepare(
                'SELECT id_pelanggan
                 FROM pelanggan
                 WHERE nik = ? AND id_pelanggan <> ?
                 LIMIT 1'
            );
            $duplicateStmt->bind_param('si', $formData['nik'], $editId);
            $duplicateStmt->execute();
            $duplicateNik = $duplicateStmt->get_result()->num_rows > 0;
            $duplicateStmt->close();

            if ($duplicateNik) {
                $errors[] = 'NIK sudah terdaftar pada pelanggan lain.';
            }
        }

        if (!$errors) {
            try {
                if ($editId > 0) {
                    $saveStmt = $koneksi->prepare(
                        'UPDATE pelanggan
                         SET nama = ?, nik = ?, no_hp = ?, alamat = ?, jenis_kelamin = ?, no_sim = ?
                         WHERE id_pelanggan = ?'
                    );
                    $saveStmt->bind_param(
                        'ssssssi',
                        $formData['nama'],
                        $formData['nik'],
                        $formData['no_hp'],
                        $formData['alamat'],
                        $formData['jenis_kelamin'],
                        $formData['no_sim'],
                        $editId
                    );
                    $saveStmt->execute();
                    $saveStmt->close();
                    kh_flash_set('success', 'Data pelanggan berhasil diperbarui.');
                } else {
                    $saveStmt = $koneksi->prepare(
                        'INSERT INTO pelanggan (nama, nik, no_hp, alamat, jenis_kelamin, no_sim)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    );
                    $saveStmt->bind_param(
                        'ssssss',
                        $formData['nama'],
                        $formData['nik'],
                        $formData['no_hp'],
                        $formData['alamat'],
                        $formData['jenis_kelamin'],
                        $formData['no_sim']
                    );
                    $saveStmt->execute();
                    $saveStmt->close();
                    kh_flash_set('success', 'Pelanggan baru berhasil ditambahkan.');
                }

                header('Location: pelanggan.php');
                exit;
            } catch (Throwable $exception) {
                $errors[] = 'Data pelanggan gagal disimpan. Periksa data dan struktur tabel.';
            }
        }
    } elseif ($action === 'hapus_pelanggan') {
        $deleteId = filter_var($_POST['id_pelanggan'] ?? null, FILTER_VALIDATE_INT);

        if (!$deleteId || $deleteId < 1) {
            $errors[] = 'ID pelanggan tidak valid.';
        } else {
            try {
                $deleteStmt = $koneksi->prepare(
                    'DELETE FROM pelanggan WHERE id_pelanggan = ?'
                );
                $deleteStmt->bind_param('i', $deleteId);
                $deleteStmt->execute();
                $deleted = $deleteStmt->affected_rows === 1;
                $deleteStmt->close();

                kh_flash_set(
                    $deleted ? 'success' : 'error',
                    $deleted
                        ? 'Pelanggan berhasil dihapus.'
                        : 'Pelanggan tidak ditemukan.'
                );
                header('Location: pelanggan.php');
                exit;
            } catch (Throwable $exception) {
                $errors[] = 'Pelanggan tidak bisa dihapus karena masih terhubung dengan transaksi. Simpan riwayat transaksi terlebih dahulu.';
            }
        }
    }
}

$search = trim((string) ($_GET['q'] ?? ''));
$search = function_exists('mb_substr') ? mb_substr($search, 0, 80) : substr($search, 0, 80);
$customers = [];
$totalCustomers = 0;
$pageError = '';

try {
    if ($search !== '') {
        $keyword = '%' . $search . '%';
        $searchStmt = $koneksi->prepare(
            'SELECT id_pelanggan, nama, nik, no_hp, alamat, jenis_kelamin, no_sim
             FROM pelanggan
             WHERE nama LIKE ? OR nik LIKE ? OR no_hp LIKE ? OR no_sim LIKE ?
             ORDER BY id_pelanggan DESC
             LIMIT 100'
        );
        $searchStmt->bind_param('ssss', $keyword, $keyword, $keyword, $keyword);
        $searchStmt->execute();
        $customers = $searchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $searchStmt->close();
    } else {
        $customerResult = $koneksi->query(
            'SELECT id_pelanggan, nama, nik, no_hp, alamat, jenis_kelamin, no_sim
             FROM pelanggan
             ORDER BY id_pelanggan DESC
             LIMIT 100'
        );
        $customers = $customerResult ? $customerResult->fetch_all(MYSQLI_ASSOC) : [];
    }

    $totalCustomers = (int) $koneksi->query('SELECT COUNT(*) AS jumlah FROM pelanggan')->fetch_assoc()['jumlah'];
} catch (Throwable $exception) {
    $pageError = 'Data pelanggan gagal dimuat. Periksa struktur tabel pelanggan.';
}

kh_render_start('Pelanggan', 'pelanggan', 'pelanggan.css', 'pelanggan.js');
?>

<section class="vehicle-page-heading">
    <div>
        <div class="vehicle-eyebrow">DATA PELANGGAN</div>
        <h1>Manajemen Pelanggan</h1>
        <p>Kelola identitas dan kontak pelanggan yang melakukan penyewaan.</p>
    </div>
</section>

<?php if ($flash) : ?>
    <div class="vehicle-alert vehicle-alert-<?= kh_h($flash['type'] ?? 'info'); ?>" role="status">
        <i class="bi bi-info-circle"></i>
        <span><?= kh_h($flash['message'] ?? ''); ?></span>
    </div>
<?php endif; ?>

<?php if ($errors) : ?>
    <div class="vehicle-alert vehicle-alert-error" role="alert">
        <i class="bi bi-exclamation-triangle"></i>
        <div>
            <strong>Periksa data pelanggan.</strong>
            <ul>
                <?php foreach ($errors as $error) : ?>
                    <li><?= kh_h($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>

<?php if ($pageError !== '') : ?>
    <div class="vehicle-alert vehicle-alert-error" role="alert">
        <i class="bi bi-exclamation-circle"></i>
        <span><?= kh_h($pageError); ?></span>
    </div>
<?php endif; ?>

<section class="module-stats module-stats-compact">
    <article class="module-stat-card">
        <span class="module-stat-icon"><i class="bi bi-people"></i></span>
        <div><span>Total pelanggan</span><strong><?= number_format($totalCustomers, 0, ',', '.'); ?></strong></div>
    </article>
    <article class="module-stat-card">
        <span class="module-stat-icon is-blue"><i class="bi bi-person-check"></i></span>
        <div><span>Hasil pencarian</span><strong><?= number_format(count($customers), 0, ',', '.'); ?></strong></div>
    </article>
</section>

<div class="customer-page-grid">
    <section class="vehicle-panel customer-form-panel" id="form-pelanggan">
        <div class="vehicle-panel-heading">
            <div>
                <h2><?= $editId > 0 ? 'Edit Pelanggan' : 'Tambah Pelanggan'; ?></h2>
                <p>Pastikan informasi sesuai dengan identitas pelanggan.</p>
            </div>
            <?php if ($editId > 0) : ?>
                <a href="pelanggan.php" class="vehicle-btn vehicle-btn-quiet">Batal</a>
            <?php endif; ?>
        </div>

        <form method="post" class="module-form customer-form" id="customerForm">
            <input type="hidden" name="csrf_token" value="<?= kh_h($_SESSION['csrf_kendaraan']); ?>">
            <input type="hidden" name="action" value="simpan_pelanggan">
            <input type="hidden" name="id_pelanggan" value="<?= (int) $editId; ?>">

            <label class="module-field">
                <span>Nama lengkap</span>
                <input type="text" name="nama" maxlength="100" value="<?= kh_h($formData['nama']); ?>" required>
            </label>

            <label class="module-field">
                <span>NIK</span>
                <input type="text" name="nik" maxlength="30" value="<?= kh_h($formData['nik']); ?>" required>
            </label>

            <label class="module-field">
                <span>Nomor HP</span>
                <input type="tel" name="no_hp" maxlength="20" value="<?= kh_h($formData['no_hp']); ?>" autocomplete="tel" required>
            </label>

            <label class="module-field">
                <span>Nomor SIM</span>
                <input type="text" name="no_sim" maxlength="50" value="<?= kh_h($formData['no_sim']); ?>" required>
            </label>

            <label class="module-field">
                <span>Jenis kelamin</span>
                <select name="jenis_kelamin" required>
                    <option value="Laki-laki" <?= $formData['jenis_kelamin'] === 'Laki-laki' ? 'selected' : ''; ?>>Laki-laki</option>
                    <option value="Perempuan" <?= $formData['jenis_kelamin'] === 'Perempuan' ? 'selected' : ''; ?>>Perempuan</option>
                </select>
            </label>

            <label class="module-field">
                <span>Alamat</span>
                <textarea name="alamat" rows="3" required><?= kh_h($formData['alamat']); ?></textarea>
            </label>

            <div class="module-form-actions module-form-actions-wide">
                <button type="submit" class="vehicle-btn vehicle-btn-primary">
                    <i class="bi bi-check2-circle"></i>
                    <?= $editId > 0 ? 'Simpan perubahan' : 'Simpan pelanggan'; ?>
                </button>
            </div>
        </form>
    </section>

    <section class="vehicle-panel customer-list-panel">
        <div class="vehicle-panel-heading">
            <div>
                <h2>Daftar Pelanggan</h2>
                <p>Maksimal 100 data terbaru ditampilkan.</p>
            </div>
        </div>

        <form method="get" action="pelanggan.php" class="module-filters" role="search">
            <label class="module-search-field">
                <i class="bi bi-search"></i>
                <input type="search" name="q" value="<?= kh_h($search); ?>" placeholder="Cari nama, NIK, nomor HP, atau SIM..." maxlength="80">
            </label>
            <button type="submit" class="vehicle-btn vehicle-btn-secondary"><i class="bi bi-search"></i> Cari</button>
            <?php if ($search !== '') : ?>
                <a href="pelanggan.php" class="vehicle-btn vehicle-btn-quiet">Reset</a>
            <?php endif; ?>
        </form>

        <div class="module-table-wrap">
            <table class="vehicle-table module-table">
                <thead>
                    <tr>
                        <th>Pelanggan</th>
                        <th>NIK / SIM</th>
                        <th>Kontak</th>
                        <th>Jenis kelamin</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($customers) : ?>
                        <?php foreach ($customers as $customer) : ?>
                            <tr>
                                <td>
                                    <div class="module-primary-cell">
                                        <strong><?= kh_h($customer['nama']); ?></strong>
                                        <span><?= kh_h($customer['alamat']); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="module-primary-cell">
                                        <strong><?= kh_h($customer['nik']); ?></strong>
                                        <span>SIM: <?= kh_h($customer['no_sim']); ?></span>
                                    </div>
                                </td>
                                <td><?= kh_h($customer['no_hp']); ?></td>
                                <td><?= kh_h($customer['jenis_kelamin']); ?></td>
                                <td>
                                    <div class="module-row-actions">
                                        <a href="pelanggan.php?edit=<?= (int) $customer['id_pelanggan']; ?>#form-pelanggan" class="vehicle-icon-btn vehicle-icon-edit" title="Edit pelanggan" aria-label="Edit pelanggan">
                                            <i class="bi bi-pencil-square"></i>
                                        </a>
                                        <form method="post" data-confirm-customer-delete data-customer-name="<?= kh_h($customer['nama']); ?>">
                                            <input type="hidden" name="csrf_token" value="<?= kh_h($_SESSION['csrf_kendaraan']); ?>">
                                            <input type="hidden" name="action" value="hapus_pelanggan">
                                            <input type="hidden" name="id_pelanggan" value="<?= (int) $customer['id_pelanggan']; ?>">
                                            <button type="submit" class="vehicle-icon-btn vehicle-icon-delete" title="Hapus pelanggan" aria-label="Hapus pelanggan">
                                                <i class="bi bi-trash3"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="5">
                                <div class="vehicle-empty-state">
                                    <span><i class="bi bi-people"></i></span>
                                    <strong>Belum ada pelanggan</strong>
                                    <p>Tambahkan pelanggan menggunakan formulir di sebelah kiri.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php kh_render_end(); ?>
