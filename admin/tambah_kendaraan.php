<?php

session_start();
require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/kendaraan_helper.php';

mysqli_set_charset($koneksi, 'utf8mb4');

$errors = [];
$formData = [
    'kode_kendaraan' => '',
    'nama_kendaraan' => '',
    'jenis' => 'Mobil',
    'merk' => '',
    'tipe' => '',
    'tahun' => (string) date('Y'),
    'nomor_polisi' => '',
    'harga_sewa' => '',
    'status' => 'Tersedia',
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

    if (!in_array($formData['status'], ['Tersedia', 'Perawatan'], true)) {
        $errors[] = 'Status kendaraan tidak valid.';
    }

    if (!$errors) {
        try {
            if (kh_vehicle_code_exists($koneksi, $formData['kode_kendaraan'])) {
                $errors[] = 'Kode kendaraan sudah digunakan. Pilih kode lain.';
            }
        } catch (Throwable $exception) {
            $errors[] = 'Kode kendaraan gagal diperiksa. Periksa struktur tabel kendaraan.';
        }
    }

    if (!$errors) {
        $fotoPath = null;

        try {
            $fotoPath = kh_store_photo($_FILES['foto'] ?? []);

            $stmt = $koneksi->prepare(
                'INSERT INTO kendaraan (
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
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            $tahunValue = (int) $tahun;
            $hargaValue = (float) $harga;
            $nomorPolisi = $formData['nomor_polisi'];

            $stmt->bind_param(
                'sssssisdss',
                $formData['kode_kendaraan'],
                $formData['nama_kendaraan'],
                $formData['jenis'],
                $formData['merk'],
                $formData['tipe'],
                $tahunValue,
                $nomorPolisi,
                $hargaValue,
                $formData['status'],
                $fotoPath
            );

            $stmt->execute();
            $stmt->close();

            kh_flash_set('success', 'Kendaraan baru berhasil ditambahkan.');
            header('Location: kendaraan.php');
            exit;
        } catch (Throwable $exception) {
            if ($fotoPath) {
                kh_remove_photo($fotoPath);
            }

            $errors[] = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Kendaraan gagal disimpan. Periksa data dan struktur tabel.';
        }
    }
}

kh_render_start('Tambah Kendaraan', 'kendaraan', 'tambah-kendaraan.css', 'tambah-kendaraan.js');
?>

<section class="vehicle-page-heading">
    <div>
        <div class="vehicle-eyebrow">DATA MASTER</div>
        <h1>Tambah Kendaraan</h1>
        <p>Masukkan detail kendaraan yang akan ditampilkan di katalog RentCar.</p>
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
        <span class="vehicle-form-icon"><i class="bi bi-car-front"></i></span>
        <div>
            <h2>Informasi Kendaraan</h2>
            <p>Kolom bertanda <span class="required-mark">*</span> wajib diisi.</p>
        </div>
    </div>

    <form method="post" action="tambah_kendaraan.php" enctype="multipart/form-data" class="vehicle-form">
        <input type="hidden" name="csrf_token" value="<?= kh_h($_SESSION['csrf_kendaraan']); ?>">

        <div class="vehicle-form-grid">
            <label class="vehicle-form-field">
                <span>Kode Kendaraan <b>*</b></span>
                <input
                    type="text"
                    name="kode_kendaraan"
                    value="<?= kh_h($formData['kode_kendaraan']); ?>"
                    placeholder="Contoh: MOB-001"
                    maxlength="20"
                    required
                >
                <small>Kode harus unik untuk setiap kendaraan.</small>
            </label>

            <label class="vehicle-form-field">
                <span>Nama Kendaraan <b>*</b></span>
                <input
                    type="text"
                    name="nama_kendaraan"
                    value="<?= kh_h($formData['nama_kendaraan']); ?>"
                    placeholder="Contoh: Toyota Avanza"
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
                    placeholder="Contoh: Toyota"
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
                    placeholder="Contoh: MPV, SUV, Matic"
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
                    placeholder="2025"
                    required
                >
            </label>

            <label class="vehicle-form-field">
                <span>Nomor Polisi <b>*</b></span>
                <input
                    type="text"
                    name="nomor_polisi"
                    value="<?= kh_h($formData['nomor_polisi']); ?>"
                    placeholder="Contoh: KU 1234 AB"
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
                        placeholder="350000"
                        required
                    >
                </div>
            </label>

            <label class="vehicle-form-field">
                <span>Status <b>*</b></span>
                <select name="status" required>
                    <?php foreach (['Tersedia', 'Perawatan'] as $statusOption) : ?>
                        <option value="<?= kh_h($statusOption); ?>" <?= $formData['status'] === $statusOption ? 'selected' : ''; ?>>
                            <?= kh_h($statusOption); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <div class="vehicle-form-field vehicle-photo-field">
                <span>Foto Kendaraan</span>
                <label class="vehicle-upload-box" for="fotoKendaraan">
                    <input
                        type="file"
                        id="fotoKendaraan"
                        name="foto"
                        accept="image/jpeg,image/png,image/webp,image/gif"
                        data-image-input
                    >
                    <span class="vehicle-upload-icon"><i class="bi bi-cloud-arrow-up"></i></span>
                    <strong>Pilih foto kendaraan</strong>
                    <small>JPG, PNG, WEBP, atau GIF. Maksimal 5 MB.</small>
                </label>
                <div class="vehicle-photo-preview" data-image-preview-wrap hidden>
                    <img src="" alt="Pratinjau foto kendaraan" data-image-preview>
                    <button type="button" class="vehicle-remove-preview" data-remove-preview>
                        <i class="bi bi-x-lg"></i>
                        Hapus pratinjau
                    </button>
                </div>
            </div>
        </div>

        <div class="vehicle-form-footer">
            <a href="kendaraan.php" class="vehicle-btn vehicle-btn-quiet">Batal</a>
            <button type="submit" class="vehicle-btn vehicle-btn-primary">
                <i class="bi bi-check2-circle"></i>
                Simpan Kendaraan
            </button>
        </div>
    </form>
</section>

<?php kh_render_end(); ?>
