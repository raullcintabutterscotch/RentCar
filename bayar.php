<?php
session_start();
require_once __DIR__ . '/config/koneksi.php';
require_once __DIR__ . '/config/payment_functions.php';
require_once __DIR__ . '/config/rentcar_automation.php';

if (empty($_SESSION['login']) || $_SESSION['login'] !== true) {
    header('Location: login.php');
    exit;
}
if (in_array($_SESSION['level'] ?? null, ['admin', 'petugas'], true)) {
    header('Location: admin/transaksi.php');
    exit;
}
$userId = (int) ($_SESSION['id_user'] ?? 0);
if ($userId < 1) {
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['csrf_customer_payment'])) {
    $_SESSION['csrf_customer_payment'] = bin2hex(random_bytes(32));
}
if (empty($_SESSION['customer_payment_token'])) {
    $_SESSION['customer_payment_token'] = bin2hex(random_bytes(32));
}

try {
    rc_refresh_automation($koneksi);
} catch (Throwable $exception) {
    error_log('[RentCar payment page refresh] ' . $exception->getMessage());
}

function customer_payment_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
function customer_payment_money($value): string
{
    return 'Rp ' . number_format((float) $value, 0, ',', '.');
}

$transactionId = filter_var($_POST['id_transaksi'] ?? $_GET['id'] ?? null, FILTER_VALIDATE_INT);
$transactionId = $transactionId ? (int) $transactionId : 0;
$paymentKind = (string) ($_POST['jenis_pembayaran'] ?? $_GET['jenis'] ?? 'sewa');
if (!in_array($paymentKind, ['sewa', 'denda'], true)) {
    $paymentKind = 'sewa';
}
$errors = [];
$flash = null;
$proofPath = null;

if ($transactionId < 1) {
    http_response_code(404);
    exit('Transaksi tidak ditemukan.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_payment'])) {
    $csrf = $_POST['csrf_token'] ?? null;
    $requestToken = trim((string) ($_POST['request_token'] ?? ''));
    $amount = pay_parse_money($_POST['nominal_pembayaran'] ?? '');
    $method = (string) ($_POST['metode_pembayaran'] ?? 'Transfer Bank');
    $reference = trim((string) ($_POST['nomor_referensi'] ?? ''));
    $kind = (string) ($_POST['jenis_pembayaran'] ?? 'sewa');
    $paymentType = (string) ($_POST['tipe_pembayaran'] ?? 'Pembayaran Sebagian');

    if (!is_string($csrf) || !hash_equals($_SESSION['csrf_customer_payment'], $csrf)) {
        $errors[] = 'Sesi formulir tidak valid. Muat ulang halaman dan coba kembali.';
    }
    if (!preg_match('/^[a-f0-9]{64}$/', $requestToken)) {
        $errors[] = 'Token pembayaran tidak valid. Muat ulang halaman.';
    }
    if (!in_array($kind, ['sewa', 'denda'], true)) {
        $errors[] = 'Jenis tagihan tidak valid.';
    }
    if (!in_array($method, ['Transfer Bank', 'QRIS', 'Tunai di Kantor'], true)) {
        $errors[] = 'Metode pembayaran tidak valid.';
    }
    if ($kind === 'sewa' && !in_array($paymentType, ['Pembayaran Sebagian', 'Pelunasan'], true)) {
        $errors[] = 'Jenis pembayaran sewa tidak valid.';
    }
    if ($amount === null || $amount <= 0) {
        $errors[] = 'Nominal pembayaran harus lebih besar dari nol.';
    }
    if ((function_exists('mb_strlen') ? mb_strlen($reference, 'UTF-8') : strlen($reference)) > 100) {
        $errors[] = 'Nomor referensi maksimal 100 karakter.';
    }

    if (!$errors) {
        $paymentRequestToken = hash('sha256', $userId . ':' . $transactionId . ':' . $requestToken);
        $duplicateStmt = $koneksi->prepare('SELECT id_pembayaran FROM pembayaran WHERE request_token = ? LIMIT 1');
        $duplicateStmt->bind_param('s', $paymentRequestToken);
        $duplicateStmt->execute();
        $duplicate = $duplicateStmt->get_result()->fetch_assoc();
        $duplicateStmt->close();
        if ($duplicate) {
            $_SESSION['customer_rental_flash'] = [
                'type' => 'info',
                'message' => 'Pengajuan pembayaran sudah tersimpan. Sistem mencegah pengiriman ganda.',
            ];
            header('Location: profil.php');
            exit;
        }

        $transactionStarted = false;
        try {
            $koneksi->begin_transaction();
            $transactionStarted = true;

            $ownerStmt = $koneksi->prepare(
                'SELECT t.id_transaksi,t.kode_transaksi,t.id_pelanggan,t.id_kendaraan,
                        t.total_harga,t.status_transaksi,t.status_pembayaran,k.nama_kendaraan
                 FROM transaksi t
                 JOIN users u ON u.id_pelanggan=t.id_pelanggan
                 JOIN kendaraan k ON k.id_kendaraan=t.id_kendaraan
                 WHERE t.id_transaksi=? AND u.id_user=?
                 LIMIT 1 FOR UPDATE'
            );
            $ownerStmt->bind_param('ii', $transactionId, $userId);
            $ownerStmt->execute();
            $transaction = $ownerStmt->get_result()->fetch_assoc();
            $ownerStmt->close();
            if (!$transaction) {
                throw new RuntimeException('Transaksi tidak ditemukan pada akun Anda.');
            }
            if ($transaction['status_transaksi'] === 'Dibatalkan') {
                throw new RuntimeException('Transaksi yang dibatalkan tidak menerima pembayaran.');
            }

            if ($kind === 'denda') {
                $balanceStmt = $koneksi->prepare(
                    "SELECT d.total_denda,
                            COALESCE((SELECT SUM(p.nominal) FROM pembayaran p WHERE p.id_transaksi=d.id_transaksi AND p.jenis='Denda' AND p.status='Diverifikasi'),0) AS paid,
                            COALESCE((SELECT SUM(p.nominal) FROM pembayaran p WHERE p.id_transaksi=d.id_transaksi AND p.jenis='Denda' AND p.status='Menunggu Verifikasi'),0) AS pending
                     FROM denda d WHERE d.id_transaksi=? LIMIT 1 FOR UPDATE"
                );
                $balanceStmt->bind_param('i', $transactionId);
                $balanceStmt->execute();
                $balance = $balanceStmt->get_result()->fetch_assoc();
                $balanceStmt->close();
                if (!$balance) {
                    throw new RuntimeException('Belum ada tagihan denda untuk transaksi ini.');
                }
                $remaining = max(0, (float) $balance['total_denda'] - (float) $balance['paid'] - (float) $balance['pending']);
                $paymentType = 'Denda';
            } else {
                $balanceStmt = $koneksi->prepare(
                    "SELECT COALESCE(SUM(CASE WHEN status='Diverifikasi' AND jenis<>'Denda' THEN nominal ELSE 0 END),0) AS paid,
                            COALESCE(SUM(CASE WHEN status='Menunggu Verifikasi' AND jenis<>'Denda' THEN nominal ELSE 0 END),0) AS pending
                     FROM pembayaran WHERE id_transaksi=?"
                );
                $balanceStmt->bind_param('i', $transactionId);
                $balanceStmt->execute();
                $balance = $balanceStmt->get_result()->fetch_assoc() ?: ['paid' => 0, 'pending' => 0];
                $balanceStmt->close();
                $remaining = max(0, (float) $transaction['total_harga'] - (float) $balance['paid'] - (float) $balance['pending']);
            }

            if ($remaining <= 0) {
                throw new RuntimeException('Tidak ada sisa tagihan yang perlu diajukan. Periksa pembayaran yang masih menunggu verifikasi.');
            }
            if ($amount > $remaining + 0.009) {
                throw new RuntimeException('Nominal melebihi sisa pembayaran yang masih dapat diajukan, yaitu ' . customer_payment_money($remaining) . '.');
            }
            if ($kind === 'sewa' && $paymentType === 'Pelunasan' && abs($amount - $remaining) > 0.009) {
                throw new RuntimeException('Untuk pelunasan, masukkan seluruh sisa pembayaran ' . customer_payment_money($remaining) . '.');
            }

            $proofPath = pay_store_proof($_FILES['bukti_pembayaran'] ?? [], $method !== 'Tunai di Kantor');
            $referenceValue = $reference !== '' ? $reference : null;
            $pendingStatus = 'Menunggu Verifikasi';
            $insert = $koneksi->prepare(
                'INSERT INTO pembayaran
                    (id_transaksi,nominal,metode_pembayaran,jenis,nomor_referensi,bukti_pembayaran,status,request_token)
                 VALUES (?,?,?,?,?,?,?,?)'
            );
            $insert->bind_param(
                'idssssss',
                $transactionId,
                $amount,
                $method,
                $paymentType,
                $referenceValue,
                $proofPath,
                $pendingStatus,
                $paymentRequestToken
            );
            $insert->execute();
            $paymentId = (int) $koneksi->insert_id;
            $insert->close();
            rc_recalculate_transaction($koneksi, $transactionId);

            $notify = $koneksi->prepare(
                "INSERT IGNORE INTO notifikasi (id_transaksi,jenis,tanggal_kejadian,untuk_role,judul,pesan)
                 SELECT ?, 'Pembayaran Masuk', CURDATE(), roles.role, 'Pengajuan pembayaran baru', ?
                 FROM (SELECT 'admin' AS role UNION ALL SELECT 'petugas') roles"
            );
            $noticeMessage = 'Pembayaran ' . customer_payment_money($amount) . ' untuk transaksi '
                . $transaction['kode_transaksi'] . ' menunggu verifikasi.';
            $notify->bind_param('is', $transactionId, $noticeMessage);
            $notify->execute();
            $notify->close();

            $koneksi->commit();
            $transactionStarted = false;
            $_SESSION['customer_payment_token'] = bin2hex(random_bytes(32));
            $_SESSION['customer_rental_flash'] = [
                'type' => 'success',
                'message' => 'Pengajuan pembayaran untuk ' . $transaction['kode_transaksi'] . ' tersimpan dan menunggu verifikasi.',
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
            error_log('[RentCar customer payment] ' . $exception->getMessage());
            $errors[] = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Pembayaran gagal disimpan. Periksa data dan coba kembali.';
        }
    }
    $paymentKind = $kind;
}

$ownerStmt = $koneksi->prepare(
    'SELECT t.id_transaksi,t.kode_transaksi,t.total_harga,t.uang_bayar,t.status_transaksi,t.status_pembayaran,
            t.tanggal_sewa,t.waktu_sewa,t.tanggal_kembali,t.waktu_kembali,k.nama_kendaraan,k.foto,
            COALESCE(d.total_denda,0) AS total_denda,COALESCE(d.nominal_dibayar,0) AS denda_dibayar
     FROM transaksi t
     JOIN users u ON u.id_pelanggan=t.id_pelanggan
     JOIN kendaraan k ON k.id_kendaraan=t.id_kendaraan
     LEFT JOIN denda d ON d.id_transaksi=t.id_transaksi
     WHERE t.id_transaksi=? AND u.id_user=? LIMIT 1'
);
$ownerStmt->bind_param('ii', $transactionId, $userId);
$ownerStmt->execute();
$transaction = $ownerStmt->get_result()->fetch_assoc();
$ownerStmt->close();
if (!$transaction) {
    http_response_code(404);
    exit('Transaksi tidak ditemukan pada akun Anda.');
}

$amountStmt = $koneksi->prepare(
    "SELECT
        COALESCE(SUM(CASE WHEN jenis<>'Denda' AND status='Diverifikasi' THEN nominal ELSE 0 END),0) AS paid_rent,
        COALESCE(SUM(CASE WHEN jenis<>'Denda' AND status='Menunggu Verifikasi' THEN nominal ELSE 0 END),0) AS pending_rent,
        COALESCE(SUM(CASE WHEN jenis='Denda' AND status='Diverifikasi' THEN nominal ELSE 0 END),0) AS paid_fine,
        COALESCE(SUM(CASE WHEN jenis='Denda' AND status='Menunggu Verifikasi' THEN nominal ELSE 0 END),0) AS pending_fine
     FROM pembayaran WHERE id_transaksi=?"
);
$amountStmt->bind_param('i', $transactionId);
$amountStmt->execute();
$amountData = $amountStmt->get_result()->fetch_assoc() ?: [];
$amountStmt->close();
$remainingRent = max(0, (float) $transaction['total_harga'] - (float) ($amountData['paid_rent'] ?? 0) - (float) ($amountData['pending_rent'] ?? 0));
$remainingFine = max(0, (float) $transaction['total_denda'] - (float) ($amountData['paid_fine'] ?? 0) - (float) ($amountData['pending_fine'] ?? 0));
$selectedBalance = $paymentKind === 'denda' ? $remainingFine : $remainingRent;
$photo = trim((string) ($transaction['foto'] ?? ''));
if ($photo !== '' && !preg_match('#^https?://#i', $photo)) {
    $photo = ltrim(str_replace('\\', '/', $photo), '/');
    if (preg_match('#(^|/)\.\.(/|$)#', $photo)) {
        $photo = '';
    }
}
$flash = $_SESSION['customer_rental_flash'] ?? null;
unset($_SESSION['customer_rental_flash']);
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pembayaran | RentCar</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@75..125,400..700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/customer-payment.css">
    <script src="assets/js/customer-payment.js" defer></script>
</head>
<body class="customer-payment-page">
<main class="payment-shell">
    <a class="payment-back" href="profil.php"><i class="bi bi-arrow-left"></i> Kembali ke profil</a>
    <header class="payment-header">
        <span class="payment-kicker">RENTCAR · PEMBAYARAN</span>
        <h1>Pembayaran tagihan</h1>
        <p>Masukkan nominal yang akan diajukan. Pembayaran berstatus menunggu verifikasi sampai petugas memeriksa penerimaannya.</p>
    </header>
    <?php if ($flash) : ?><div class="payment-alert" role="status"><?= customer_payment_h($flash['message'] ?? ''); ?></div><?php endif; ?>
    <?php if ($errors) : ?><div class="payment-alert payment-alert-error" role="alert"><ul><?php foreach ($errors as $error) : ?><li><?= customer_payment_h($error); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <section class="payment-summary">
        <div class="payment-vehicle">
            <?php if ($photo !== '') : ?><img src="<?= customer_payment_h($photo); ?>" alt="<?= customer_payment_h($transaction['nama_kendaraan']); ?>"><?php endif; ?>
            <div><span class="payment-code"><?= customer_payment_h($transaction['kode_transaksi']); ?></span><h2><?= customer_payment_h($transaction['nama_kendaraan']); ?></h2><p><?= customer_payment_h($transaction['tanggal_sewa']); ?> <?= customer_payment_h(substr($transaction['waktu_sewa'],0,5)); ?> sampai <?= customer_payment_h($transaction['tanggal_kembali']); ?> <?= customer_payment_h(substr($transaction['waktu_kembali'],0,5)); ?></p></div>
        </div>
        <div class="payment-balance-grid">
            <div><span>Total sewa</span><strong><?= customer_payment_h(customer_payment_money($transaction['total_harga'])); ?></strong></div>
            <div><span>Pembayaran terverifikasi</span><strong><?= customer_payment_h(customer_payment_money($amountData['paid_rent'] ?? 0)); ?></strong></div>
            <div><span>Menunggu verifikasi</span><strong><?= customer_payment_h(customer_payment_money($amountData['pending_rent'] ?? 0)); ?></strong></div>
            <div><span>Sisa sewa yang dapat diajukan</span><strong><?= customer_payment_h(customer_payment_money($remainingRent)); ?></strong></div>
            <div><span>Total denda</span><strong><?= customer_payment_h(customer_payment_money($transaction['total_denda'])); ?></strong></div>
            <div><span>Sisa denda yang dapat diajukan</span><strong><?= customer_payment_h(customer_payment_money($remainingFine)); ?></strong></div>
        </div>
    </section>
    <section class="payment-form-panel">
        <?php if ($selectedBalance <= 0 || $transaction['status_transaksi'] === 'Dibatalkan') : ?>
            <div class="payment-empty"><i class="bi bi-check-circle"></i><h2>Tidak ada tagihan terbuka</h2><p>Sisa tagihan mungkin sudah dibayar atau sedang menunggu verifikasi. Periksa riwayat pada profil.</p></div>
        <?php else : ?>
            <form method="post" enctype="multipart/form-data" id="customerPaymentForm" data-remaining="<?= customer_payment_h($selectedBalance); ?>" data-kind="<?= customer_payment_h($paymentKind); ?>">
                <input type="hidden" name="csrf_token" value="<?= customer_payment_h($_SESSION['csrf_customer_payment']); ?>">
                <input type="hidden" name="request_token" value="<?= customer_payment_h($_SESSION['customer_payment_token']); ?>">
                <input type="hidden" name="id_transaksi" value="<?= (int) $transactionId; ?>">
                <input type="hidden" name="jenis_pembayaran" value="<?= customer_payment_h($paymentKind); ?>">
                <?php if ($paymentKind === 'denda') : ?>
                    <div class="payment-field"><label for="paymentType">Jenis tagihan</label><input id="paymentType" type="text" value="Denda keterlambatan" readonly></div>
                <?php else : ?>
                    <div class="payment-field"><label for="paymentType">Jenis pembayaran</label><select id="paymentType" name="tipe_pembayaran"><option value="Pembayaran Sebagian">Pembayaran sebagian</option><option value="Pelunasan">Pelunasan sisa tagihan</option></select></div>
                <?php endif; ?>
                <div class="payment-field"><label for="paymentAmount">Nominal pembayaran</label><input type="text" id="paymentAmount" name="nominal_pembayaran" inputmode="numeric" autocomplete="off" placeholder="Rp0" required><small>Sisa yang dapat diajukan: <?= customer_payment_h(customer_payment_money($selectedBalance)); ?></small></div>
                <div class="payment-field"><label for="paymentMethod">Metode pembayaran</label><select id="paymentMethod" name="metode_pembayaran" required><option value="Transfer Bank">Transfer Bank</option><option value="QRIS">QRIS</option><option value="Tunai di Kantor">Tunai di Kantor</option></select></div>
                <div class="payment-field"><label for="paymentReference">Nomor referensi (opsional)</label><input type="text" id="paymentReference" name="nomor_referensi" maxlength="100" placeholder="Nomor referensi pembayaran"></div>
                <div class="payment-field" id="proofField"><label for="paymentProof">Bukti pembayaran</label><input type="file" id="paymentProof" name="bukti_pembayaran" accept=".jpg,.jpeg,.png,.webp,.pdf"><small>Transfer Bank dan QRIS memerlukan bukti JPG, PNG, WEBP, atau PDF maksimal 5 MB.</small></div>
                <div class="payment-submit-row"><div><span>Nominal diajukan</span><strong id="paymentAmountPreview">Rp0</strong></div><button type="submit" name="submit_payment" value="1">Kirim pembayaran</button></div>
            </form>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
