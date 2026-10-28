<?php

session_start();
require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/kendaraan_helper.php';
require_once __DIR__ . '/../config/rentcar_automation.php';

mysqli_set_charset($koneksi, 'utf8mb4');
try {
    rc_refresh_automation($koneksi);
} catch (Throwable $refreshError) {
    error_log('[RentCar vehicle edit refresh] ' . $refreshError->getMessage());
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$id = (int) ($id ?: ($_POST['id_kendaraan'] ?? 0));

if ($id < 1) {
    kh_flash_set('error', 'ID kendaraan tidak valid.');
    header('Location: kendaraan.php');
    exit;
}

try {
    $findStmt = $koneksi->prepare(
        'SELECT
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
         WHERE id_kendaraan = ?
         LIMIT 1'
    );
    $findStmt->bind_param('i', $id);
    $findStmt->execute();
    $vehicle = $findStmt->get_result()->fetch_assoc();
    $findStmt->close();
} catch (Throwable $exception) {
    $vehicle = null;
}

if (!$vehicle) {
    kh_flash_set('error', 'Data kendaraan tidak ditemukan.');
    header('Location: kendaraan.php');
    exit;
}

$errors = [];
$formData = [
    'kode_kendaraan' => (string) $vehicle['kode_kendaraan'],
    'nama_kendaraan' => (string) $vehicle['nama_kendaraan'],
    'jenis' => (string) $vehicle['jenis'],
    'merk' => (string) $vehicle['merk'],
    'tipe' => (string) $vehicle['tipe'],
    'tahun' => (string) $vehicle['tahun'],
    'nomor_polisi' => (string) ($vehicle['nomor_polisi'] ?? ''),
    'harga_sewa' => (string) $vehicle['harga_sewa'],
    'status' => (string) $vehicle['status'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!kh_csrf_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Permintaan tidak valid. Muat ulang halaman, lalu coba kembali.');
    }

    foreach ($formData as $field => $defaultValue) {
        if (isset($_POST[$field]) && !is_array($_POST[$field])) {
            $formData[$field] = trim((string) $_POST[$field]);
        }
    }

    $formData['kode_kendaraan'] = strtoupper($formData['kode_kendaraan']);
    $tahun = filter_var($formData['tahun'], FILTER_VALIDATE_INT);
    $harga = filter_var($formData['harga_sewa'], FILTER_VALIDATE_FLOAT);

    if ($formData['kode_kendaraan'] === '') {
        $errors[] = 'Kode kendaraan wajib diisi.';
    } elseif (kh_length($formData['kode_kendaraan']) > 20) {
        $errors[] = 'Kode kendaraan maksimal 20 karakter sesuai database.';
    }

    if ($formData['nama_kendaraan'] === '') {
        $errors[] = 'Nama kendaraan wajib diisi.';
    } elseif (kh_length($formData['nama_kendaraan']) > 100) {
        $errors[] = 'Nama kendaraan maksimal 100 karakter.';
    }

    if (!in_array($formData['jenis'], ['Mobil', 'Motor'], true)) {
        $errors[] = 'Jenis kendaraan tidak valid.';
    }

    if ($formData['merk'] === '' || kh_length($formData['merk']) > 50) {
        $errors[] = 'Merek wajib diisi dan maksimal 50 karakter sesuai database.';
    }

    if ($formData['tipe'] === '' || kh_length($formData['tipe']) > 50) {
        $errors[] = 'Tipe kendaraan wajib diisi dan maksimal 50 karakter sesuai database.';
    }

    if ($tahun === false || $tahun < 1901 || $tahun > ((int) date('Y') + 1)) {
        $errors[] = 'Tahun kendaraan tidak valid.';
    }

    if ($formData['nomor_polisi'] === '') {
        $errors[] = 'Nomor polisi wajib diisi.';
    } elseif (kh_length($formData['nomor_polisi']) > 20) {
        $errors[] = 'Nomor polisi maksimal 20 karakter.';
    }

    if ($harga === false || $harga <= 0) {
        $errors[] = 'Harga sewa harus lebih besar dari nol.';
    }

    if (!in_array($formData['status'], ['Tersedia', 'Disewa', 'Perawatan'], true)) {
        $errors[] = 'Status kendaraan tidak valid.';
    }

    if (!$errors) {
        try {
            if (kh_vehicle_code_exists($koneksi, $formData['kode_kendaraan'], $id)) {
                $errors[] = 'Kode kendaraan sudah digunakan. Pilih kode lain.';
            }
        } catch (Throwable $exception) {
            $errors[] = 'Kode kendaraan gagal diperiksa. Periksa struktur tabel kendaraan.';
        }
    }

    if (!$errors) {
        $uploadedPhoto = null;
        $oldPhoto = $vehicle['foto'] ?? null;
        $transactionStarted = false;

        try {
            $koneksi->begin_transaction();
            $transactionStarted = true;

            $lockStmt = $koneksi->prepare(
                'SELECT status FROM kendaraan WHERE id_kendaraan = ? LIMIT 1 FOR UPDATE'
            );
            $lockStmt->bind_param('i', $id);
            $lockStmt->execute();
            $lockedVehicle = $lockStmt->get_result()->fetch_assoc();
            $lockStmt->close();
            if (!$lockedVehicle) {
                throw new RuntimeException('Kendaraan tidak ditemukan. Muat ulang halaman.');
            }

            $activeStmt = $koneksi->prepare(
                "SELECT COUNT(*) AS jumlah FROM transaksi
                 WHERE id_kendaraan = ? AND status_transaksi IN ('Dipesan','Berjalan','Terlambat')"
            );
            $activeStmt->bind_param('i', $id);
            $activeStmt->execute();
            $activeCount = (int) ($activeStmt->get_result()->fetch_assoc()['jumlah'] ?? 0);
            $activeStmt->close();
            $storedStatus = (string) $lockedVehicle['status'];
            $requestedStatus = $formData['status'];

            if ($activeCount > 0 && $requestedStatus === 'Tersedia') {
                throw new RuntimeException('Kendaraan masih memiliki penyewaan aktif. Status Tersedia hanya ditetapkan lewat konfirmasi pengembalian.');
            }
            if ($activeCount > 0 && $requestedStatus === 'Perawatan') {
                throw new RuntimeException('Pengembalian harus dikonfirmasi sebelum kendaraan yang masih disewa masuk perawatan.');
            }
            if ($requestedStatus === 'Tersedia' && $storedStatus === 'Disewa') {
                throw new RuntimeException('Gunakan menu Pengembalian untuk mengubah kendaraan dari Disewa menjadi Tersedia.');
            }
            if ($requestedStatus === 'Disewa' && $storedStatus !== 'Disewa' && $activeCount === 0) {
                throw new RuntimeException('Status Disewa ditetapkan otomatis oleh transaksi penyewaan.');
            }

            $uploadedPhoto = kh_store_photo($_FILES['foto'] ?? []);
            $photoPath = $uploadedPhoto ?? $oldPhoto;

            $stmt = $koneksi->prepare(
                'UPDATE kendaraan
                 SET kode_kendaraan = ?, nama_kendaraan = ?, jenis = ?, merk = ?, tipe = ?,
                     tahun = ?, nomor_polisi = ?, harga_sewa = ?, status = ?, foto = ?
                 WHERE id_kendaraan = ?'
            );
            $tahunValue = (int) $tahun;
            $hargaValue = (float) $harga;
            $nomorPolisi = $formData['nomor_polisi'];
            $stmt->bind_param(
                'sssssisdssi',
                $formData['kode_kendaraan'], $formData['nama_kendaraan'], $formData['jenis'],
                $formData['merk'], $formData['tipe'], $tahunValue, $nomorPolisi,
                $hargaValue, $formData['status'], $photoPath, $id
            );
            $stmt->execute();
            $stmt->close();
            $koneksi->commit();
            $transactionStarted = false;

            if ($uploadedPhoto) {
                kh_remove_photo($oldPhoto);
            }

            kh_flash_set('success', 'Perubahan data kendaraan berhasil disimpan.');
            header('Location: kendaraan.php');
            exit;
        } catch (Throwable $exception) {
            if ($transactionStarted) {
                $koneksi->rollback();
            }
            if ($uploadedPhoto) {
                kh_remove_photo($uploadedPhoto);
            }

            $errors[] = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Perubahan kendaraan gagal disimpan. Periksa data dan struktur tabel.';
        }
    }
}

$currentPhotoUrl = kh_photo_url($vehicle['foto'] ?? '');
kh_render_start('Edit Kendaraan', 'kendaraan', 'edit-kendaraan.css', 'edit-kendaraan.js');
?>

<section class="vehicle-page-heading">
    <div>
        <div class="vehicle-eyebrow">DATA MASTER</div>
        <h1>Edit Kendaraan</h1>
        <p>Perbarui informasi dan status kendaraan yang tersimpan.</p>
    </div>

    <a href="kendaraan.php" class="vehicle-btn vehicle-btn-secondary">
        <i class="bi bi-arrow-left"></i>
        <span>Kembali ke daftar</span>
    </a>
</section>

<?php if ($errors) : ?>
    <div class="vehicle-alert vehicle-alert-error" role="alert">
        <i class="bi bi-exclamation-triangle"></i>
        <div>
            <strong>Periksa kembali data kendaraan.</strong>
            <ul>
                <?php foreach ($errors as $error) : ?>
                    <li><?= kh_h($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>

<section class="vehicle-form-panel">
    <div class="vehicle-form-heading">
        <span class="vehicle-form-icon"><i class="bi bi-pencil-square"></i></span>
        <div>
            <h2>Perbarui Informasi Kendaraan</h2>
            <p>ID kendaraan: <strong>#<?= (int) $vehicle['id_kendaraan']; ?></strong></p>
        </div>
    </div>

    <form
        method="post"
        action="edit_kendaraan.php?id=<?= (int) $id; ?>"
        enctype="multipart/form-data"
        class="vehicle-form"
    >
        <input type="hidden" name="csrf_token" value="<?= kh_h($_SESSION['csrf_kendaraan']); ?>">
        <input type="hidden" name="id_kendaraan" value="<?= (int) $id; ?>">

        <div class="vehicle-form-grid">
            <label class="vehicle-form-field">
                <span>Kode Kendaraan <b>*</b></span>
                <input
                    type="text"
                    name="kode_kendaraan"
                    value="<?= kh_h($formData['kode_kendaraan']); ?>"
                    maxlength="20"
                    required
                >
            </label>

            <label class="vehicle-form-field">
                <span>Nama Kendaraan <b>*</b></span>
                <input
                    type="text"
                    name="nama_kendaraan"
                    value="<?= kh_h($formData['nama_kendaraan']); ?>"
                    maxlength="100"
                    required
                >
            </label>

            <label class="vehicle-form-field">
                <span>Jenis Kendaraan <b>*</b></span>
                <select name="jenis" required>
                    <option value="Mobil" <?= $formData['jenis'] === 'Mobil' ? 'selected' : ''; ?>>Mobil</option>
                    <option value="Motor" <?= $formData['jenis'] === 'Motor' ? 'selected' : ''; ?>>Motor</option>
                </select>
            </label>

            <label class="vehicle-form-field">
                <span>Merek <b>*</b></span>
                <input
                    type="text"
                    name="merk"
                    value="<?= kh_h($formData['merk']); ?>"
                    maxlength="50"
                    required
                >
            </label>

            <label class="vehicle-form-field">
                <span>Tipe <b>*</b></span>
                <input
                    type="text"
                    name="tipe"
                    value="<?= kh_h($formData['tipe']); ?>"
                    maxlength="50"
                    required
                >
            </label>

            <label class="vehicle-form-field">
                <span>Tahun <b>*</b></span>
                <input
                    type="number"
                    name="tahun"
                    value="<?= kh_h($formData['tahun']); ?>"
                    min="1901"
                    max="<?= (int) date('Y') + 1; ?>"
                    required
                >
            </label>

            <label class="vehicle-form-field">
                <span>Nomor Polisi <b>*</b></span>
                <input
                    type="text"
                    name="nomor_polisi"
                    value="<?= kh_h($formData['nomor_polisi']); ?>"
                    maxlength="20"
                    required
                >
            </label>

            <label class="vehicle-form-field">
                <span>Harga Sewa per Hari (Rp) <b>*</b></span>
                <div class="vehicle-input-prefix">
                    <span>Rp</span>
                    <input
                        type="number"
                        name="harga_sewa"
                        value="<?= kh_h($formData['harga_sewa']); ?>"
                        min="0.01"
                        max="9999999999.99"
                        step="0.01"
                        required
                    >
                </div>
            </label>

            <label class="vehicle-form-field">
                <span>Status <b>*</b></span>
                <select name="status" required>
                    <?php foreach (['Tersedia', 'Disewa', 'Perawatan'] as $statusOption) : ?>
                        <option value="<?= kh_h($statusOption); ?>" <?= $formData['status'] === $statusOption ? 'selected' : ''; ?>>
                            <?= kh_h($statusOption); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <div class="vehicle-form-field vehicle-photo-field">
                <span>Foto Kendaraan</span>

                <?php if ($currentPhotoUrl) : ?>
                    <div class="vehicle-existing-photo" data-existing-photo>
                        <img src="<?= kh_h($currentPhotoUrl); ?>" alt="Foto <?= kh_h($vehicle['nama_kendaraan']); ?>">
                        <div>
                            <strong>Foto saat ini</strong>
                            <small>Unggah foto baru untuk mengganti foto lama.</small>
                        </div>
                    </div>
                <?php endif; ?>

                <label class="vehicle-upload-box" for="fotoKendaraan">
                    <input
                        type="file"
                        id="fotoKendaraan"
                        name="foto"
                        accept="image/jpeg,image/png,image/webp,image/gif"
                        data-image-input
                    >
                    <span class="vehicle-upload-icon"><i class="bi bi-cloud-arrow-up"></i></span>
                    <strong>Pilih foto pengganti</strong>
                    <small>JPG, PNG, WEBP, atau GIF. Maksimal 5 MB.</small>
                </label>

                <div class="vehicle-photo-preview" data-image-preview-wrap hidden>
                    <img src="" alt="Pratinjau foto kendaraan" data-image-preview>
                    <button type="button" class="vehicle-remove-preview" data-remove-preview>
                        <i class="bi bi-x-lg"></i>
                        Batalkan foto baru
                    </button>
                </div>
            </div>
        </div>

        <div class="vehicle-form-footer">
            <a href="kendaraan.php" class="vehicle-btn vehicle-btn-quiet">Batal</a>
            <button type="submit" class="vehicle-btn vehicle-btn-primary">
                <i class="bi bi-check2-circle"></i>
                Simpan Perubahan
            </button>
        </div>
    </form>
</section>

<?php kh_render_end(); ?>
