<?php
session_start();

require_once __DIR__ . '/config/koneksi.php';
require_once __DIR__ . '/config/payment_functions.php';
require_once __DIR__ . '/config/rentcar_automation.php';

mysqli_set_charset($koneksi, 'utf8mb4');

$loggedIn = isset($_SESSION['login']) && $_SESSION['login'] === true;

if (!$loggedIn) {
    header('Location: login.php');
    exit;
}

$level = $_SESSION['level'] ?? null;

if (in_array($level, ['admin', 'petugas'], true)) {
    header('Location: admin/dashboard.php');
    exit;
}

if ($level !== null && $level !== '' && $level !== 'pelanggan') {
    http_response_code(403);
    exit('Akun ini tidak memiliki akses ke halaman pelanggan.');
}

$userId = (int) ($_SESSION['id_user'] ?? 0);

if ($userId < 1) {
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['csrf_customer_rental'])) {
    $_SESSION['csrf_customer_rental'] = bin2hex(random_bytes(32));
}
if (empty($_SESSION['customer_rental_token'])) {
    $_SESSION['customer_rental_token'] = bin2hex(random_bytes(32));
}

try {
    rc_refresh_automation($koneksi);
} catch (Throwable $automationError) {
    error_log('[RentCar automation] ' . $automationError->getMessage());
}

function customer_page_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function customer_page_money($amount): string
{
    return 'Rp ' . number_format((float) $amount, 0, ',', '.');
}

function customer_page_length(string $value): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);
}

function customer_page_photo($photo): ?string
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

    if (
        preg_match('#(^|/)\.\.(/|$)#', $photo)
        || strpos($photo, "\0") !== false
    ) {
        return null;
    }

    $root = realpath(__DIR__);

    if (!$root) {
        return null;
    }

    $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $path = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $photo));

    if (
        !$path
        || !is_file($path)
        || strncmp($path, $prefix, strlen($prefix)) !== 0
    ) {
        return null;
    }

    $relative = substr($path, strlen($prefix));
    $relative = str_replace('\\', '/', $relative);

    return implode('/', array_map('rawurlencode', explode('/', $relative)));
}

function customer_page_bind(mysqli_stmt $stmt, string $types, array &$params): void
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

function customer_page_parse_date(string $value): ?DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    if (!$date || $date->format('Y-m-d') !== $value) {
        return null;
    }

    return $date;
}

$userStmt = $koneksi->prepare(
    'SELECT id_user, nama, username, level, id_pelanggan
     FROM users
     WHERE id_user = ?
     LIMIT 1'
);
$userStmt->bind_param('i', $userId);
$userStmt->execute();
$user = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

if (!$user) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

$namaPengguna = trim((string) ($user['nama'] ?? $user['username'] ?? 'Pelanggan'));
$customerProfile = null;

if (!empty($user['id_pelanggan'])) {
    $profileStmt = $koneksi->prepare(
        'SELECT id_pelanggan, nama, nik, no_hp, alamat, jenis_kelamin, no_sim
         FROM pelanggan
         WHERE id_pelanggan = ?
         LIMIT 1'
    );

    $profileId = (int) $user['id_pelanggan'];
    $profileStmt->bind_param('i', $profileId);
    $profileStmt->execute();
    $customerProfile = $profileStmt->get_result()->fetch_assoc();
    $profileStmt->close();
}

$errorMessage = '';
$flashMessage = $_SESSION['customer_rental_flash'] ?? null;
unset($_SESSION['customer_rental_flash']);

$selectedVehicleId = filter_input(INPUT_GET, 'sewa', FILTER_VALIDATE_INT);
$selectedVehicleId = $selectedVehicleId ? (int) $selectedVehicleId : 0;

$rentalSettingsResult = $koneksi->query('SELECT maksimal_hari_sewa FROM pengaturan_rentcar WHERE id_pengaturan = 1 LIMIT 1');
$rentalSettings = $rentalSettingsResult->fetch_assoc() ?: [];
$maxRentalDays = max(1, min(365, (int) ($rentalSettings['maksimal_hari_sewa'] ?? 90)));

$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$defaultStartDateTime = (new DateTimeImmutable('now'))->setTime((int) date('H'), 0)->modify('+1 hour');
$defaultReturnDateTime = $defaultStartDateTime->modify('+1 day');
$startDateInput = $defaultStartDateTime->format('Y-m-d');
$returnDateInput = $defaultReturnDateTime->format('Y-m-d');

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && (($_POST['form_action'] ?? '') === 'submit_rental' || isset($_POST['submit_rental']))
) {
    $selectedVehicleId = filter_var($_POST['id_kendaraan'] ?? null, FILTER_VALIDATE_INT);
    $selectedVehicleId = $selectedVehicleId ? (int) $selectedVehicleId : 0;

    $startDateInput = trim((string) ($_POST['tanggal_sewa'] ?? ''));
    $returnDateInput = trim((string) ($_POST['tanggal_kembali'] ?? ''));
    $startTimeInput = trim((string) ($_POST['waktu_sewa'] ?? '09:00'));
    $returnTimeInput = trim((string) ($_POST['waktu_kembali'] ?? '09:00'));
    $csrfToken = $_POST['csrf_token'] ?? null;
    $requestToken = trim((string) ($_POST['request_token'] ?? ''));
    $proofPath = null;

    if (
        !is_string($csrfToken)
        || !isset($_SESSION['csrf_customer_rental'])
        || !hash_equals($_SESSION['csrf_customer_rental'], $csrfToken)
    ) {
        $errorMessage = 'Sesi formulir tidak valid. Muat ulang halaman dan coba kembali.';
    } elseif ($selectedVehicleId < 1) {
        $errorMessage = 'Pilih kendaraan yang ingin disewa.';
    } elseif (!preg_match('/^[a-f0-9]{64}$/', $requestToken)) {
        $errorMessage = 'Token pesanan tidak valid. Muat ulang halaman dan coba kembali.';
    }

    // Jika browser mengirim ulang POST setelah koneksi lambat, gunakan transaksi yang sudah tercatat.
    if ($errorMessage === '' && $requestToken !== '') {
        $existingStmt = $koneksi->prepare('SELECT kode_transaksi FROM transaksi WHERE request_token = ? LIMIT 1');
        $existingStmt->bind_param('s', $requestToken);
        $existingStmt->execute();
        $existingTransaction = $existingStmt->get_result()->fetch_assoc();
        $existingStmt->close();

        if ($existingTransaction) {
            $_SESSION['customer_rental_flash'] = [
                'type' => 'info',
                'message' => 'Pesanan ' . $existingTransaction['kode_transaksi'] . ' sudah tersimpan. Sistem mencegah pengiriman ganda.',
            ];
            header('Location: profil.php');
            exit;
        }
    }

    $startDate = customer_page_parse_date($startDateInput);
    $returnDate = customer_page_parse_date($returnDateInput);
    $startTimeValid = preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $startTimeInput) === 1;
    $returnTimeValid = preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $returnTimeInput) === 1;
    $todayDate = new DateTimeImmutable(date('Y-m-d'));
    $now = new DateTimeImmutable('now');

    if ($errorMessage === '' && (!$startDate || !$returnDate || !$startTimeValid || !$returnTimeValid)) {
        $errorMessage = 'Tanggal atau waktu sewa tidak valid.';
    }

    $startDateTime = null;
    $returnDateTime = null;

    if ($errorMessage === '' && $startDate && $returnDate && $startTimeValid && $returnTimeValid) {
        $startDateTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $startDateInput . ' ' . $startTimeInput);
        $returnDateTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $returnDateInput . ' ' . $returnTimeInput);

        if (!$startDateTime || $startDateTime <= $now) {
            $errorMessage = 'Waktu mulai sewa harus setelah waktu sekarang.';
        } elseif (!$returnDateTime || $returnDateTime <= $startDateTime) {
            $errorMessage = 'Waktu kembali harus setelah waktu mulai sewa.';
        }
    }

    $rentalDays = 0;
    if ($errorMessage === '' && $startDateTime && $returnDateTime) {
        $rentalSeconds = $returnDateTime->getTimestamp() - $startDateTime->getTimestamp();
        $rentalDays = max(1, (int) ceil($rentalSeconds / 86400));
        if ($rentalDays > $maxRentalDays) {
            $errorMessage = 'Durasi sewa maksimal ' . $maxRentalDays . ' hari.';
        }
    }

    $profileInput = [
        'nama' => trim((string) ($_POST['nama'] ?? '')),
        'nik' => trim((string) ($_POST['nik'] ?? '')),
        'no_hp' => trim((string) ($_POST['no_hp'] ?? '')),
        'alamat' => trim((string) ($_POST['alamat'] ?? '')),
        'jenis_kelamin' => trim((string) ($_POST['jenis_kelamin'] ?? '')),
        'no_sim' => trim((string) ($_POST['no_sim'] ?? '')),
    ];

    if ($errorMessage === '' && !$customerProfile) {
        foreach (['nama', 'nik', 'no_hp', 'alamat', 'jenis_kelamin', 'no_sim'] as $field) {
            if ($profileInput[$field] === '') {
                $errorMessage = 'Lengkapi seluruh data pelanggan sebelum menyewa.';
                break;
            }
        }

        $limits = ['nama' => 100, 'nik' => 30, 'no_hp' => 20, 'alamat' => 4000, 'no_sim' => 50];
        foreach ($limits as $field => $limit) {
            if ($errorMessage === '' && customer_page_length($profileInput[$field]) > $limit) {
                $errorMessage = 'Kolom ' . $field . ' melebihi batas karakter database.';
            }
        }
        if ($errorMessage === '' && !in_array($profileInput['jenis_kelamin'], ['Laki-laki', 'Perempuan'], true)) {
            $errorMessage = 'Pilih jenis kelamin yang valid.';
        }
        if ($errorMessage === '' && !preg_match('/^[0-9]{16}$/', $profileInput['nik'])) {
            $errorMessage = 'NIK harus terdiri dari 16 digit angka.';
        }
    }

    $paymentChoice = (string) ($_POST['jenis_pembayaran'] ?? 'dp');
    $paymentMethod = (string) ($_POST['metode_pembayaran'] ?? 'Transfer Bank');
    $nomorReferensi = trim((string) ($_POST['nomor_referensi'] ?? ''));
    $paymentAmount = pay_parse_money($_POST['nominal_pembayaran'] ?? '');

    if ($errorMessage === '' && !in_array($paymentChoice, ['dp', 'sebagian', 'penuh'], true)) {
        $errorMessage = 'Pilih jenis pembayaran yang valid.';
    }
    if ($errorMessage === '' && !in_array($paymentMethod, ['Transfer Bank', 'QRIS', 'Tunai di Kantor'], true)) {
        $errorMessage = 'Pilih metode pembayaran yang valid.';
    }
    if ($errorMessage === '' && customer_page_length($nomorReferensi) > 100) {
        $errorMessage = 'Nomor referensi maksimal 100 karakter.';
    }

    if ($errorMessage === '') {
        $transactionStarted = false;
        try {
            $koneksi->begin_transaction();
            $transactionStarted = true;

            // Lock row kendaraan: semua pesanan kendaraan yang sama diproses berurutan.
            $vehicleStmt = $koneksi->prepare(
                'SELECT id_kendaraan, kode_kendaraan, nama_kendaraan, jenis, merk, tipe, tahun, harga_sewa, status, foto
                 FROM kendaraan WHERE id_kendaraan = ? LIMIT 1 FOR UPDATE'
            );
            $vehicleStmt->bind_param('i', $selectedVehicleId);
            $vehicleStmt->execute();
            $vehicle = $vehicleStmt->get_result()->fetch_assoc();
            $vehicleStmt->close();

            if (!$vehicle || $vehicle['status'] === 'Perawatan') {
                throw new RuntimeException('Kendaraan sedang dalam perawatan atau tidak ditemukan.');
            }

            $startSql = $startDateTime->format('Y-m-d H:i:s');
            $endSql = $returnDateTime->format('Y-m-d H:i:s');
            $overlapStmt = $koneksi->prepare(
                "SELECT id_transaksi FROM transaksi
                 WHERE id_kendaraan = ?
                   AND status_transaksi IN ('Dipesan','Berjalan','Terlambat')
                   AND TIMESTAMP(tanggal_sewa,waktu_sewa) < ?
                   AND TIMESTAMP(tanggal_kembali,waktu_kembali) > ?
                 LIMIT 1 FOR UPDATE"
            );
            $overlapStmt->bind_param('iss', $selectedVehicleId, $endSql, $startSql);
            $overlapStmt->execute();
            $hasOverlap = $overlapStmt->get_result()->num_rows > 0;
            $overlapStmt->close();
            if ($hasOverlap) {
                throw new RuntimeException('Jadwal kendaraan bertabrakan dengan pesanan lain. Pilih waktu berbeda.');
            }

            $userLockStmt = $koneksi->prepare('SELECT id_pelanggan, aktif FROM users WHERE id_user = ? LIMIT 1 FOR UPDATE');
            $userLockStmt->bind_param('i', $userId);
            $userLockStmt->execute();
            $lockedUser = $userLockStmt->get_result()->fetch_assoc();
            $userLockStmt->close();
            if (!$lockedUser || (int) $lockedUser['aktif'] !== 1) {
                throw new RuntimeException('Akun tidak aktif. Hubungi administrator.');
            }

            $linkedCustomerId = (int) ($lockedUser['id_pelanggan'] ?? 0);
            if ($linkedCustomerId > 0) {
                $checkProfileStmt = $koneksi->prepare('SELECT id_pelanggan FROM pelanggan WHERE id_pelanggan = ? LIMIT 1');
                $checkProfileStmt->bind_param('i', $linkedCustomerId);
                $checkProfileStmt->execute();
                $hasProfile = $checkProfileStmt->get_result()->fetch_assoc();
                $checkProfileStmt->close();
                if (!$hasProfile) {
                    throw new RuntimeException('Data profil pelanggan tidak ditemukan. Perbarui profil Anda.');
                }
                $customerId = $linkedCustomerId;
            } else {
                $insertProfileStmt = $koneksi->prepare(
                    'INSERT INTO pelanggan (nama, nik, no_hp, alamat, jenis_kelamin, no_sim)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $insertProfileStmt->bind_param('ssssss', $profileInput['nama'], $profileInput['nik'], $profileInput['no_hp'], $profileInput['alamat'], $profileInput['jenis_kelamin'], $profileInput['no_sim']);
                $insertProfileStmt->execute();
                $customerId = (int) $koneksi->insert_id;
                $insertProfileStmt->close();

                $linkStmt = $koneksi->prepare('UPDATE users SET id_pelanggan = ? WHERE id_user = ? AND id_pelanggan IS NULL');
                $linkStmt->bind_param('ii', $customerId, $userId);
                $linkStmt->execute();
                if ($linkStmt->affected_rows !== 1) {
                    $linkStmt->close();
                    throw new RuntimeException('Profil akun berubah saat proses sewa. Muat ulang halaman.');
                }
                $linkStmt->close();
            }

            $dailyPrice = (float) $vehicle['harga_sewa'];
            $totalPrice = round($dailyPrice * $rentalDays, 2);
            if ($totalPrice <= 0 || $totalPrice > 9999999999.99) {
                throw new RuntimeException('Total biaya berada di luar batas nominal yang didukung.');
            }
            $settingsResult = $koneksi->query('SELECT persentase_dp FROM pengaturan_rentcar WHERE id_pengaturan = 1 LIMIT 1');
            $settings = $settingsResult->fetch_assoc() ?: [];
            $dpPercent = max(1, min(100, (float) ($settings['persentase_dp'] ?? 30)));
            $minimumDp = ceil($totalPrice * $dpPercent / 100);

            if ($paymentChoice === 'penuh') {
                $paymentAmount = $totalPrice;
                $paymentKind = 'Pembayaran Penuh';
            } elseif ($paymentChoice === 'dp') {
                $paymentKind = 'DP';
            } else {
                $paymentKind = $paymentAmount !== null && $paymentAmount >= $totalPrice ? 'Pembayaran Penuh' : 'Pembayaran Sebagian';
            }

            if ($paymentAmount === null || $paymentAmount <= 0 || $paymentAmount > $totalPrice) {
                throw new RuntimeException('Nominal pembayaran harus lebih dari nol dan tidak melebihi total tagihan.');
            }
            if ($paymentChoice === 'dp' && $paymentAmount < $minimumDp) {
                throw new RuntimeException('Nominal DP minimal ' . customer_page_money($minimumDp) . '.');
            }
            if ($paymentChoice === 'penuh' && $paymentAmount !== $totalPrice) {
                throw new RuntimeException('Nominal pembayaran penuh harus sama dengan total tagihan.');
            }

            $proofPath = pay_store_proof($_FILES['bukti_pembayaran'] ?? [], $paymentMethod !== 'Tunai di Kantor');
            if ($paymentMethod === 'Tunai di Kantor' && $proofPath === null && $nomorReferensi === '') {
                $nomorReferensi = null;
            }

            $startDateDb = $startDateTime->format('Y-m-d');
            $startTimeDb = $startDateTime->format('H:i:s');
            $returnDateDb = $returnDateTime->format('Y-m-d');
            $returnTimeDb = $returnDateTime->format('H:i:s');
            $transactionStatus = $startDateTime > $now ? 'Dipesan' : 'Berjalan';
            $transactionCode = 'RC-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
            $methodValue = $paymentMethod;

            $insertTransaction = $koneksi->prepare(
                "INSERT INTO transaksi (
                    kode_transaksi,id_pelanggan,id_kendaraan,tanggal_sewa,waktu_sewa,
                    tanggal_kembali,waktu_kembali,lama_sewa,harga_sewa,total_harga,
                    uang_bayar,metode_pembayaran,kembalian,status_pembayaran,status_transaksi,
                    created_by_user,request_token
                 ) VALUES (?,?,?,?,?,?,?,?,?,?,0,?,0,'Menunggu Verifikasi',?,?,?)"
            );
            $insertTransaction->bind_param(
                'siissssiddssis',
                $transactionCode, $customerId, $selectedVehicleId, $startDateDb, $startTimeDb,
                $returnDateDb, $returnTimeDb, $rentalDays, $dailyPrice, $totalPrice,
                $methodValue, $transactionStatus, $userId, $requestToken
            );
            $insertTransaction->execute();
            $newTransactionId = (int) $koneksi->insert_id;
            $insertTransaction->close();

            $paymentRequestToken = hash('sha256', $requestToken . ':payment');
            $refValue = $nomorReferensi !== '' ? $nomorReferensi : null;
            $paymentInsert = $koneksi->prepare(
                "INSERT INTO pembayaran (
                    id_transaksi,nominal,metode_pembayaran,jenis,nomor_referensi,
                    bukti_pembayaran,status,request_token
                 ) VALUES (?,?,?,?,?,?,'Menunggu Verifikasi',?)"
            );
            $paymentInsert->bind_param('idsssss', $newTransactionId, $paymentAmount, $methodValue, $paymentKind, $refValue, $proofPath, $paymentRequestToken);
            $paymentInsert->execute();
            $paymentInsert->close();

            // Every active reservation marks its selected vehicle as Disewa.
            $updateVehicleStmt = $koneksi->prepare(
                "UPDATE kendaraan SET status = 'Disewa'
                 WHERE id_kendaraan = ? AND status <> 'Perawatan'"
            );
            $updateVehicleStmt->bind_param('i', $selectedVehicleId);
            $updateVehicleStmt->execute();
            $updateVehicleStmt->close();
            rc_update_vehicle_status($koneksi, $selectedVehicleId);

            $notification = $koneksi->prepare(
                "INSERT IGNORE INTO notifikasi (id_transaksi,jenis,tanggal_kejadian,untuk_role,judul,pesan)
                 SELECT ?, 'Penyewaan Baru', CURDATE(), roles.role, 'Penyewaan baru', ?
                 FROM (SELECT 'admin' AS role UNION ALL SELECT 'petugas') roles"
            );
            $notificationMessage = 'Pesanan ' . $transactionCode . ' menunggu verifikasi pembayaran.';
            $notification->bind_param('is', $newTransactionId, $notificationMessage);
            $notification->execute();
            $notification->close();

            rc_recalculate_transaction($koneksi, $newTransactionId);
            rc_update_vehicle_status($koneksi, $selectedVehicleId);
            $koneksi->commit();
            $transactionStarted = false;

            $_SESSION['customer_rental_token'] = bin2hex(random_bytes(32));
            $_SESSION['customer_rental_flash'] = [
                'type' => 'success',
                'message' => 'Pesanan ' . $transactionCode . ' tersimpan. Pembayaran menunggu verifikasi petugas setelah bukti diterima.',
            ];
            header('Location: profil.php');
            exit;
        } catch (Throwable $exception) {
            if ($transactionStarted) {
                $koneksi->rollback();
            }
            if ($proofPath) {
                pay_remove_proof($proofPath);
            }

            // Unique request_token also protects simultaneous duplicate POST requests.
            if ($requestToken !== '' && preg_match('/^[a-f0-9]{64}$/', $requestToken)) {
                try {
                    $duplicateStmt = $koneksi->prepare(
                        'SELECT kode_transaksi FROM transaksi WHERE request_token = ? LIMIT 1'
                    );
                    $duplicateStmt->bind_param('s', $requestToken);
                    $duplicateStmt->execute();
                    $duplicateTransaction = $duplicateStmt->get_result()->fetch_assoc();
                    $duplicateStmt->close();
                    if ($duplicateTransaction) {
                        $_SESSION['customer_rental_flash'] = [
                            'type' => 'info',
                            'message' => 'Pesanan ' . $duplicateTransaction['kode_transaksi'] . ' sudah tersimpan. Sistem mencegah pengiriman ganda.',
                        ];
                        header('Location: profil.php');
                        exit;
                    }
                } catch (Throwable $duplicateCheckError) {
                    error_log('[RentCar duplicate rental check] ' . $duplicateCheckError->getMessage());
                }
            }

            error_log('[RentCar rental] ' . $exception->getMessage());
            $errorMessage = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Penyewaan gagal disimpan. Periksa data yang diisi, lalu coba kembali.';
        }
    }
}

$search = trim((string) ($_GET['q'] ?? ''));
$search = function_exists('mb_substr')
    ? mb_substr($search, 0, 80, 'UTF-8')
    : substr($search, 0, 80);

$jenisFilter = (string) ($_GET['jenis'] ?? '');

if (!in_array($jenisFilter, ['Mobil', 'Motor'], true)) {
    $jenisFilter = '';
}

$whereSql = "WHERE status <> 'Perawatan'";
$types = '';
$params = [];

if ($jenisFilter !== '') {
    $whereSql .= ' AND jenis = ?';
    $types .= 's';
    $params[] = $jenisFilter;
}

if ($search !== '') {
    $whereSql .= ' AND (nama_kendaraan LIKE ? OR merk LIKE ? OR tipe LIKE ? OR kode_kendaraan LIKE ?)';
    $keyword = '%' . $search . '%';
    $types .= 'ssss';
    $params[] = $keyword;
    $params[] = $keyword;
    $params[] = $keyword;
    $params[] = $keyword;
}

$vehicles = [];
$catalogError = '';
$totalAvailable = 0;
$totalCars = 0;
$totalMotorcycles = 0;

try {
    $statsResult = $koneksi->query(
        "SELECT
            COUNT(*) AS total,
            COALESCE(SUM(jenis = 'Mobil'), 0) AS mobil,
            COALESCE(SUM(jenis = 'Motor'), 0) AS motor
         FROM kendaraan
         WHERE status = 'Tersedia'"
    );
    $stats = $statsResult ? $statsResult->fetch_assoc() : [];
    $totalAvailable = (int) ($stats['total'] ?? 0);
    $totalCars = (int) ($stats['mobil'] ?? 0);
    $totalMotorcycles = (int) ($stats['motor'] ?? 0);

    $listStmt = $koneksi->prepare(
        "SELECT
            id_kendaraan,
            kode_kendaraan,
            nama_kendaraan,
            jenis,
            merk,
            tipe,
            tahun,
            harga_sewa,
            foto
         FROM kendaraan
         $whereSql
         ORDER BY id_kendaraan DESC
         LIMIT 100"
    );
    customer_page_bind($listStmt, $types, $params);
    $listStmt->execute();
    $result = $listStmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $row['foto_url'] = customer_page_photo($row['foto'] ?? '');
        $vehicles[] = $row;
    }

    $listStmt->close();
} catch (Throwable $exception) {
    $catalogError = 'Katalog gagal dimuat. Periksa koneksi dan struktur tabel kendaraan.';
}

$selectedVehicle = null;

if ($selectedVehicleId > 0) {
    $selectedStmt = $koneksi->prepare(
        "SELECT
            id_kendaraan,
            kode_kendaraan,
            nama_kendaraan,
            jenis,
            merk,
            tipe,
            tahun,
            harga_sewa,
            foto
         FROM kendaraan
         WHERE id_kendaraan = ? AND status <> 'Perawatan'
         LIMIT 1"
    );
    $selectedStmt->bind_param('i', $selectedVehicleId);
    $selectedStmt->execute();
    $selectedVehicle = $selectedStmt->get_result()->fetch_assoc();
    $selectedStmt->close();

    if ($selectedVehicle) {
        $selectedVehicle['foto_url'] = customer_page_photo($selectedVehicle['foto'] ?? '');
    } elseif ($errorMessage === '') {
        $errorMessage = 'Kendaraan pilihan tidak tersedia lagi. Silakan pilih kendaraan lain.';
    }
}

$dpDisplayResult = $koneksi->query('SELECT persentase_dp FROM pengaturan_rentcar WHERE id_pengaturan = 1 LIMIT 1');
$dpDisplayRow = $dpDisplayResult->fetch_assoc() ?: [];
$customerDpPercent = max(1, min(100, (float) ($dpDisplayRow['persentase_dp'] ?? 30)));
$displayName = $customerProfile['nama'] ?? $namaPengguna;
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1c1e21">
    <meta name="description" content="Pilih kendaraan RentCar yang tersedia dan ajukan penyewaan kendaraan.">
    <title>Sewa Kendaraan | RentCar</title>

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
    <link rel="stylesheet" href="assets/css/customer-kendaraan.css?v=1">
    <script src="assets/js/customer-kendaraan.js?v=1" defer></script>
</head>

<body class="customer-rental-page">
    <header class="customer-header">
        <div class="customer-header-inner">
            <a href="index.php" class="customer-brand" aria-label="RentCar beranda">
                <img src="assets/image/logo.svg" alt="" class="customer-brand-logo">
                <span>Rent<span>Car</span></span>
            </a>

            <nav class="customer-navigation" aria-label="Navigasi pelanggan">
                <a href="index.php">Beranda</a>
                <a href="kendaraan.php" class="active" aria-current="page">Sewa Kendaraan</a>
            </nav>

            <div class="customer-account">
                <span class="customer-account-name">
                    <i class="bi bi-person-circle"></i>
                    <?= customer_page_h($displayName); ?>
                </span>
                <a href="logout.php" class="customer-logout">Keluar</a>
            </div>
        </div>
    </header>

    <main class="customer-main">
        <section class="customer-hero">
            <div class="customer-hero-copy">
                <span class="customer-eyebrow">KATALOG RENTCAR</span>
                <h1>Pilih kendaraan untuk perjalanan Anda.</h1>
                <p>Lihat kendaraan yang tersedia, tentukan tanggal sewa, lalu kirim permintaan penyewaan melalui akun Anda.</p>
                <div class="customer-hero-meta">
                    <span><i class="bi bi-shield-check"></i> Akun pelanggan</span>
                    <span><i class="bi bi-calendar-check"></i> Tarif per hari</span>
                </div>
            </div>
            <div class="customer-hero-card">
                <span class="customer-hero-card-label">KENDARAAN TERSEDIA</span>
                <strong><?= number_format($totalAvailable, 0, ',', '.'); ?></strong>
                <span>unit siap dipilih</span>
            </div>
        </section>

        <?php if (is_array($flashMessage) && !empty($flashMessage['message'])) : ?>
            <div class="customer-alert customer-alert-success" role="status">
                <i class="bi bi-check-circle"></i>
                <span><?= customer_page_h($flashMessage['message']); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($errorMessage !== '') : ?>
            <div class="customer-alert customer-alert-error" role="alert">
                <i class="bi bi-exclamation-triangle"></i>
                <span><?= customer_page_h($errorMessage); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($catalogError !== '') : ?>
            <div class="customer-alert customer-alert-error" role="alert">
                <i class="bi bi-database-exclamation"></i>
                <span><?= customer_page_h($catalogError); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($selectedVehicle) : ?>
            <section class="booking-panel" id="form-sewa">
                <div class="booking-panel-heading">
                    <div>
                        <span class="customer-eyebrow">FORM PENYEWAAN</span>
                        <h2>Ajukan sewa kendaraan</h2>
                        <p>Isi tanggal sewa dan pastikan data pelanggan sudah benar.</p>
                    </div>
                    <a href="kendaraan.php" class="booking-close" aria-label="Tutup formulir sewa">
                        <i class="bi bi-x-lg"></i>
                    </a>
                </div>

                <div class="booking-selected-vehicle">
                    <div class="booking-selected-image">
                        <?php if (!empty($selectedVehicle['foto_url'])) : ?>
                            <img
                                src="<?= customer_page_h($selectedVehicle['foto_url']); ?>"
                                alt="<?= customer_page_h($selectedVehicle['nama_kendaraan']); ?>"
                            >
                        <?php else : ?>
                            <i class="bi bi-car-front"></i>
                        <?php endif; ?>
                    </div>
                    <div class="booking-selected-copy">
                        <span><?= customer_page_h($selectedVehicle['kode_kendaraan']); ?> · <?= customer_page_h($selectedVehicle['jenis']); ?></span>
                        <h3><?= customer_page_h($selectedVehicle['nama_kendaraan']); ?></h3>
                        <p><?= customer_page_h($selectedVehicle['merk']); ?> · <?= customer_page_h($selectedVehicle['tipe']); ?> · <?= customer_page_h($selectedVehicle['tahun']); ?></p>
                    </div>
                    <div class="booking-selected-price">
                        <strong><?= customer_page_h(customer_page_money($selectedVehicle['harga_sewa'])); ?></strong>
                        <span>per hari</span>
                    </div>
                </div>

                <form
                    method="post"
                    action="kendaraan.php?sewa=<?= (int) $selectedVehicle['id_kendaraan']; ?>#form-sewa"
                    class="customer-rental-form"
                    enctype="multipart/form-data"
                    id="customerRentalForm"
                    data-daily-price="<?= customer_page_h($selectedVehicle['harga_sewa']); ?>"
                    data-dp-percent="<?= customer_page_h($customerDpPercent ?? 30); ?>"
                    data-max-days="<?= (int) $maxRentalDays; ?>"
                >
                    <input type="hidden" name="form_action" value="submit_rental">
                    <input type="hidden" name="csrf_token" value="<?= customer_page_h($_SESSION['csrf_customer_rental']); ?>">
                    <input type="hidden" name="request_token" value="<?= customer_page_h($_SESSION['customer_rental_token']); ?>">
                    <input type="hidden" name="id_kendaraan" value="<?= (int) $selectedVehicle['id_kendaraan']; ?>">

                    <div class="booking-fields">
                        <label class="customer-field">
                            <span>Tanggal sewa</span>
                            <input
                                type="date"
                                name="tanggal_sewa"
                                id="tanggalSewa"
                                min="<?= customer_page_h($today); ?>"
                                value="<?= customer_page_h($startDateInput ?: $today); ?>"
                                required
                            >
                        </label>

                        <label class="customer-field">
                            <span>Tanggal kembali</span>
                            <input
                                type="date"
                                name="tanggal_kembali"
                                id="tanggalKembali"
                                min="<?= customer_page_h($tomorrow); ?>"
                                value="<?= customer_page_h($returnDateInput ?: $tomorrow); ?>"
                                required
                            >
                        </label>

                        <label class="customer-field">
                            <span>Waktu mulai</span>
                            <input type="time" name="waktu_sewa" id="waktuSewa" value="<?= customer_page_h($_POST['waktu_sewa'] ?? $defaultStartDateTime->format('H:i')); ?>" required>
                        </label>

                        <label class="customer-field">
                            <span>Waktu kembali</span>
                            <input type="time" name="waktu_kembali" id="waktuKembali" value="<?= customer_page_h($_POST['waktu_kembali'] ?? $defaultReturnDateTime->format('H:i')); ?>" required>
                        </label>

                        <div class="rental-estimate">
                            <span>Perkiraan biaya sewa</span>
                            <strong id="rentalEstimateTotal">
                                <?= customer_page_h(customer_page_money((float) $selectedVehicle['harga_sewa'])); ?>
                            </strong>
                            <small id="rentalEstimateDays">1 hari, belum termasuk pembayaran</small>
                        </div>
                    </div>

                    <?php if (!$customerProfile) : ?>
                        <div class="customer-profile-heading">
                            <h3>Data pelanggan</h3>
                            <p>Data ini disimpan ke database dan dihubungkan dengan akun Anda.</p>
                        </div>

                        <div class="booking-fields customer-profile-fields">
                            <label class="customer-field">
                                <span>Nama lengkap</span>
                                <input
                                    type="text"
                                    name="nama"
                                    maxlength="100"
                                    value="<?= customer_page_h($_POST['nama'] ?? $namaPengguna); ?>"
                                    autocomplete="name"
                                    required
                                >
                            </label>

                            <label class="customer-field">
                                <span>NIK</span>
                                <input
                                    type="text"
                                    name="nik"
                                    maxlength="30"
                                    value="<?= customer_page_h($_POST['nik'] ?? ''); ?>"
                                    autocomplete="off"
                                    required
                                >
                            </label>

                            <label class="customer-field">
                                <span>Nomor HP</span>
                                <input
                                    type="tel"
                                    name="no_hp"
                                    maxlength="20"
                                    value="<?= customer_page_h($_POST['no_hp'] ?? ''); ?>"
                                    autocomplete="tel"
                                    required
                                >
                            </label>

                            <label class="customer-field">
                                <span>Jenis kelamin</span>
                                <select name="jenis_kelamin" required>
                                    <option value="">Pilih jenis kelamin</option>
                                    <option value="Laki-laki" <?= ($_POST['jenis_kelamin'] ?? '') === 'Laki-laki' ? 'selected' : ''; ?>>Laki-laki</option>
                                    <option value="Perempuan" <?= ($_POST['jenis_kelamin'] ?? '') === 'Perempuan' ? 'selected' : ''; ?>>Perempuan</option>
                                </select>
                            </label>

                            <label class="customer-field">
                                <span>Nomor SIM</span>
                                <input
                                    type="text"
                                    name="no_sim"
                                    maxlength="50"
                                    value="<?= customer_page_h($_POST['no_sim'] ?? ''); ?>"
                                    required
                                >
                            </label>

                            <label class="customer-field customer-field-full">
                                <span>Alamat</span>
                                <textarea name="alamat" rows="3" required><?= customer_page_h($_POST['alamat'] ?? ''); ?></textarea>
                            </label>
                        </div>
                    <?php else : ?>
                        <div class="customer-profile-summary">
                            <span class="customer-profile-summary-icon"><i class="bi bi-person-check"></i></span>
                            <div>
                                <strong>Data pelanggan tersimpan</strong>
                                <p><?= customer_page_h($customerProfile['nama']); ?> · <?= customer_page_h($customerProfile['no_hp']); ?> · SIM <?= customer_page_h($customerProfile['no_sim']); ?></p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="booking-fields payment-choice-fields">
                        <label class="customer-field">
                            <span>Jenis pembayaran</span>
                            <select name="jenis_pembayaran" id="jenisPembayaran" required>
                                <option value="dp" <?= ($_POST['jenis_pembayaran'] ?? 'dp') === 'dp' ? 'selected' : ''; ?>>DP minimum</option>
                                <option value="sebagian" <?= ($_POST['jenis_pembayaran'] ?? '') === 'sebagian' ? 'selected' : ''; ?>>Pembayaran sebagian</option>
                                <option value="penuh" <?= ($_POST['jenis_pembayaran'] ?? '') === 'penuh' ? 'selected' : ''; ?>>Pembayaran penuh</option>
                            </select>
                        </label>
                        <label class="customer-field">
                            <span>Nominal pembayaran</span>
                            <input type="text" name="nominal_pembayaran" id="nominalPembayaran" inputmode="numeric" autocomplete="off" value="<?= customer_page_h($_POST['nominal_pembayaran'] ?? ''); ?>" required>
                            <small id="minimumPembayaranNote">Nominal DP dihitung berdasarkan pengaturan.</small>
                        </label>
                        <label class="customer-field">
                            <span>Metode pembayaran</span>
                            <select name="metode_pembayaran" id="metodePembayaran" required>
                                <option value="Transfer Bank" <?= ($_POST['metode_pembayaran'] ?? 'Transfer Bank') === 'Transfer Bank' ? 'selected' : ''; ?>>Transfer Bank</option>
                                <option value="QRIS" <?= ($_POST['metode_pembayaran'] ?? '') === 'QRIS' ? 'selected' : ''; ?>>QRIS</option>
                                <option value="Tunai di Kantor" <?= ($_POST['metode_pembayaran'] ?? '') === 'Tunai di Kantor' ? 'selected' : ''; ?>>Tunai di Kantor</option>
                            </select>
                        </label>
                        <label class="customer-field">
                            <span>Nomor referensi (opsional)</span>
                            <input type="text" name="nomor_referensi" maxlength="100" value="<?= customer_page_h($_POST['nomor_referensi'] ?? ''); ?>" placeholder="Nomor referensi transaksi">
                        </label>
                        <label class="customer-field customer-field-full" id="paymentProofField">
                            <span>Bukti pembayaran <em>(wajib untuk Transfer Bank/QRIS)</em></span>
                            <input type="file" name="bukti_pembayaran" id="buktiPembayaran" accept="image/jpeg,image/png,image/webp,application/pdf">
                            <small>Format JPG, PNG, WEBP, atau PDF. Maksimal 5 MB.</small>
                        </label>
                    </div>
                    <div class="rental-estimate">
                        <span>Jumlah yang diajukan</span>
                        <strong id="rentalPaymentEstimate">-</strong>
                        <small>Tagihan awal: <b id="rentalTotalDue"><?= customer_page_h(customer_page_money((float) $selectedVehicle['harga_sewa'])); ?></b>. Pembayaran tercatat setelah diverifikasi petugas.</small>
                    </div>

                    <div class="customer-booking-note">
                        <i class="bi bi-info-circle"></i>
                        <p>Pesanan langsung tercatat dan jadwal kendaraan diblokir. Status pembayaran menjadi Menunggu Verifikasi sampai petugas memeriksa pembayaran.</p>
                    </div>

                    <div class="customer-form-actions">
                        <a href="kendaraan.php" class="customer-button customer-button-secondary">Batal</a>
                        <button type="submit" name="submit_rental" value="1" class="customer-button customer-button-primary">
                            Sewa Sekarang
                            <i class="bi bi-arrow-right"></i>
                        </button>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <section class="customer-catalog-section" id="daftar-kendaraan">
            <div class="customer-section-heading">
                <div>
                    <span class="customer-eyebrow">PILIHAN ARMADA</span>
                    <h2>Kendaraan tersedia</h2>
                    <p>Harga berlaku per hari. Jadwal akan divalidasi saat Anda mengajukan penyewaan.</p>
                </div>
                <div class="customer-catalog-count">
                    <strong><?= number_format($totalAvailable, 0, ',', '.'); ?></strong>
                    <span>unit tersedia</span>
                </div>
            </div>

            <form class="customer-filters" action="kendaraan.php" method="get" role="search">
                <label class="customer-search-field">
                    <i class="bi bi-search"></i>
                    <input
                        type="search"
                        name="q"
                        value="<?= customer_page_h($search); ?>"
                        maxlength="80"
                        placeholder="Cari nama, kode, merek, atau tipe kendaraan..."
                        aria-label="Cari kendaraan"
                    >
                </label>

                <label class="customer-filter-select">
                    <span>Jenis</span>
                    <select name="jenis">
                        <option value="">Semua jenis</option>
                        <option value="Mobil" <?= $jenisFilter === 'Mobil' ? 'selected' : ''; ?>>Mobil (<?= number_format($totalCars, 0, ',', '.'); ?>)</option>
                        <option value="Motor" <?= $jenisFilter === 'Motor' ? 'selected' : ''; ?>>Motor (<?= number_format($totalMotorcycles, 0, ',', '.'); ?>)</option>
                    </select>
                </label>

                <button type="submit" class="customer-button customer-button-primary">
                    Terapkan filter
                </button>
                <?php if ($search !== '' || $jenisFilter !== '') : ?>
                    <a href="kendaraan.php" class="customer-reset-filter">Reset</a>
                <?php endif; ?>
            </form>

            <?php if ($vehicles) : ?>
                <div class="customer-vehicle-grid">
                    <?php foreach ($vehicles as $vehicleItem) : ?>
                        <article class="customer-vehicle-card">
                            <div class="customer-vehicle-photo">
                                <?php if (!empty($vehicleItem['foto_url'])) : ?>
                                    <img
                                        src="<?= customer_page_h($vehicleItem['foto_url']); ?>"
                                        alt="<?= customer_page_h($vehicleItem['nama_kendaraan']); ?>"
                                        loading="lazy"
                                        decoding="async"
                                    >
                                <?php else : ?>
                                    <div class="customer-photo-placeholder">
                                        <i class="bi bi-car-front"></i>
                                        <span>Foto belum tersedia</span>
                                    </div>
                                <?php endif; ?>
                                <span class="customer-vehicle-type"><?= customer_page_h($vehicleItem['jenis']); ?></span>
                            </div>

                            <div class="customer-vehicle-body">
                                <div class="customer-vehicle-code"><?= customer_page_h($vehicleItem['kode_kendaraan']); ?> · <?= customer_page_h($vehicleItem['tahun']); ?></div>
                                <h3><?= customer_page_h($vehicleItem['nama_kendaraan']); ?></h3>
                                <p class="customer-vehicle-description"><?= customer_page_h($vehicleItem['merk']); ?> · <?= customer_page_h($vehicleItem['tipe']); ?></p>

                                <div class="customer-vehicle-footer">
                                    <div class="customer-vehicle-price">
                                        <strong><?= customer_page_h(customer_page_money($vehicleItem['harga_sewa'])); ?></strong>
                                        <span>/ hari</span>
                                    </div>
                                    <a
                                        class="customer-rent-link"
                                        href="kendaraan.php?sewa=<?= (int) $vehicleItem['id_kendaraan']; ?>#form-sewa"
                                    >
                                        Pilih
                                        <i class="bi bi-arrow-up-right"></i>
                                    </a>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <div class="customer-empty-state">
                    <span><i class="bi bi-car-front"></i></span>
                    <h3>Tidak ada kendaraan tersedia</h3>
                    <p>Ubah kata pencarian atau pilih jenis kendaraan lain. Ketersediaan pada tanggal pilihan akan diperiksa sebelum pesanan disimpan.</p>
                    <?php if ($search !== '' || $jenisFilter !== '') : ?>
                        <a href="kendaraan.php" class="customer-button customer-button-secondary">Reset filter</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <footer class="customer-footer">
        <span>&copy; <?= date('Y'); ?> RentCar. Semua hak dilindungi.</span>
        <a href="index.php"><i class="bi bi-arrow-left"></i> Kembali ke beranda</a>
    </footer>
</body>

</html>
