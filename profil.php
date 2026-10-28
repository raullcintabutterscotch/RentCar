<?php
session_start();
require_once __DIR__ . '/config/koneksi.php';
require_once __DIR__ . '/config/rentcar_automation.php';
if (empty($_SESSION['csrf_customer_profile'])) {
    $_SESSION['csrf_customer_profile'] = bin2hex(random_bytes(32));
}
if (empty($_SESSION['login']) || $_SESSION['login'] !== true) {
    header('Location: login.php');
    exit;
}
$userId = (int)($_SESSION['id_user'] ?? 0);
try { rc_refresh_automation($koneksi); } catch (Throwable $e) { error_log('[RentCar profile refresh] ' . $e->getMessage()); }

$stmt = $koneksi->prepare(
    'SELECT u.id_user,u.nama,u.username,u.id_pelanggan,p.no_hp,p.alamat,p.nik,p.no_sim,p.jenis_kelamin
     FROM users u LEFT JOIN pelanggan p ON p.id_pelanggan=u.id_pelanggan
     WHERE u.id_user=? LIMIT 1'
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$profile) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_reservation') {
    $token = $_POST['csrf_token'] ?? null;
    $transactionId = filter_var($_POST['id_transaksi'] ?? null, FILTER_VALIDATE_INT);
    if (!is_string($token) || !hash_equals($_SESSION['csrf_customer_profile'], $token)) {
        http_response_code(403);
        exit('Permintaan tidak valid. Muat ulang halaman.');
    }
    if (!$transactionId || $transactionId < 1) {
        $_SESSION['customer_rental_flash'] = ['type' => 'error', 'message' => 'Transaksi tidak valid.'];
        header('Location: profil.php');
        exit;
    }
    try {
        $koneksi->begin_transaction();
        $ownerStmt = $koneksi->prepare(
            'SELECT t.id_transaksi,t.id_kendaraan,t.kode_transaksi,t.status_transaksi,t.uang_bayar
             FROM transaksi t JOIN users u ON u.id_pelanggan=t.id_pelanggan
             WHERE t.id_transaksi=? AND u.id_user=? FOR UPDATE'
        );
        $ownerStmt->bind_param('ii', $transactionId, $userId);
        $ownerStmt->execute();
        $ownedTransaction = $ownerStmt->get_result()->fetch_assoc();
        $ownerStmt->close();
        if (!$ownedTransaction) {
            throw new RuntimeException('Transaksi tidak ditemukan pada akun Anda.');
        }
        if ($ownedTransaction['status_transaksi'] !== 'Dipesan') {
            throw new RuntimeException('Hanya reservasi mendatang yang belum berjalan yang dapat dibatalkan dari profil.');
        }
        $verifiedStmt = $koneksi->prepare("SELECT COUNT(*) AS total FROM pembayaran WHERE id_transaksi=? AND status='Diverifikasi'");
        $verifiedStmt->bind_param('i', $transactionId);
        $verifiedStmt->execute();
        $verifiedCount = (int) ($verifiedStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $verifiedStmt->close();
        if ($verifiedCount > 0 || (float) $ownedTransaction['uang_bayar'] > 0) {
            throw new RuntimeException('Transaksi memiliki pembayaran terverifikasi. Hubungi petugas untuk pemeriksaan dan proses pengembalian dana.');
        }
        $failedPayments = $koneksi->prepare("UPDATE pembayaran SET status='Gagal',catatan_admin='Reservasi dibatalkan oleh pelanggan.' WHERE id_transaksi=? AND status='Menunggu Verifikasi'");
        $failedPayments->bind_param('i', $transactionId);
        $failedPayments->execute();
        $failedPayments->close();
        $cancel = $koneksi->prepare("UPDATE transaksi SET status_transaksi='Dibatalkan',status_pembayaran='Gagal' WHERE id_transaksi=? AND status_transaksi='Dipesan'");
        $cancel->bind_param('i', $transactionId);
        $cancel->execute();
        if ($cancel->affected_rows !== 1) {
            $cancel->close();
            throw new RuntimeException('Reservasi sudah berubah. Muat ulang halaman dan periksa statusnya.');
        }
        $cancel->close();
        rc_update_vehicle_status($koneksi, (int) $ownedTransaction['id_kendaraan']);
        $audit = $koneksi->prepare("INSERT INTO audit_log (id_user,aksi,entitas,id_entitas,detail) VALUES (?,'batalkan_reservasi','transaksi',?,'Dibatalkan pelanggan sebelum kendaraan diserahkan')");
        $audit->bind_param('ii', $userId, $transactionId);
        $audit->execute();
        $audit->close();
        $notify = $koneksi->prepare("INSERT IGNORE INTO notifikasi (id_transaksi,jenis,tanggal_kejadian,untuk_role,judul,pesan) VALUES (?,'Penyewaan Dibatalkan',CURDATE(),'admin','Reservasi dibatalkan',?)");
        $cancelMessage = 'Pelanggan membatalkan reservasi ' . $ownedTransaction['kode_transaksi'] . '.';
        $notify->bind_param('is', $transactionId, $cancelMessage);
        $notify->execute();
        $notify->close();
        $koneksi->commit();
        $_SESSION['customer_rental_flash'] = ['type' => 'success', 'message' => 'Reservasi ' . $ownedTransaction['kode_transaksi'] . ' berhasil dibatalkan.'];
        header('Location: profil.php');
        exit;
    } catch (Throwable $exception) {
        $koneksi->rollback();
        error_log('[RentCar cancel reservation] ' . $exception->getMessage());
        $_SESSION['customer_rental_flash'] = ['type' => 'error', 'message' => $exception instanceof RuntimeException ? $exception->getMessage() : 'Reservasi gagal dibatalkan.'];
        header('Location: profil.php');
        exit;
    }
}

$transactions = [];
if (!empty($profile['id_pelanggan'])) {
    $customerId = (int)$profile['id_pelanggan'];
    $stmt = $koneksi->prepare(
        "SELECT t.id_transaksi,t.kode_transaksi,t.tanggal_sewa,t.waktu_sewa,t.tanggal_kembali,t.waktu_kembali,
                t.lama_sewa,t.harga_sewa,t.total_harga,COALESCE(ps.dibayar_sewa,0) AS uang_bayar,
                t.status_pembayaran,t.status_transaksi,t.metode_pembayaran,
                k.id_kendaraan,k.nama_kendaraan,k.foto,
                COALESCE(d.total_denda,0) AS total_denda,COALESCE(ps.dibayar_denda,0) AS denda_dibayar,
                COALESCE(ps.pending_sewa,0) AS pending_sewa,
                COALESCE(ps.pending_denda,0) AS pending_denda,
                GREATEST(0,t.total_harga-COALESCE(ps.dibayar_sewa,0)) AS sisa_sewa,
                GREATEST(0,COALESCE(d.total_denda,0)-COALESCE(ps.dibayar_denda,0)) AS sisa_denda,
                (GREATEST(0,t.total_harga-COALESCE(ps.dibayar_sewa,0))
                 + GREATEST(0,COALESCE(d.total_denda,0)-COALESCE(ps.dibayar_denda,0))) AS sisa_tagihan,
                COALESCE(ps.jumlah_pembayaran_terverifikasi,0) AS jumlah_pembayaran_terverifikasi,
                r.tanggal_pengembalian,r.waktu_pengembalian
         FROM transaksi t JOIN kendaraan k ON k.id_kendaraan=t.id_kendaraan
         LEFT JOIN denda d ON d.id_transaksi=t.id_transaksi
         LEFT JOIN (
            SELECT p.id_transaksi,
                   SUM(CASE WHEN p.status='Diverifikasi' AND p.jenis <> 'Denda' THEN p.nominal ELSE 0 END) AS dibayar_sewa,
                   SUM(CASE WHEN p.status='Diverifikasi' AND p.jenis = 'Denda' THEN p.nominal ELSE 0 END) AS dibayar_denda,
                   SUM(CASE WHEN p.status='Menunggu Verifikasi' AND p.jenis <> 'Denda' THEN p.nominal ELSE 0 END) AS pending_sewa,
                   SUM(CASE WHEN p.status='Menunggu Verifikasi' AND p.jenis = 'Denda' THEN p.nominal ELSE 0 END) AS pending_denda,
                   SUM(CASE WHEN p.status='Diverifikasi' THEN 1 ELSE 0 END) AS jumlah_pembayaran_terverifikasi
            FROM pembayaran p GROUP BY p.id_transaksi
         ) ps ON ps.id_transaksi=t.id_transaksi
         LEFT JOIN pengembalian r ON r.id_transaksi=t.id_transaksi
         WHERE t.id_pelanggan=? ORDER BY t.created_at DESC,t.id_transaksi DESC"
    );
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    $transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$flash = $_SESSION['customer_rental_flash'] ?? null;
unset($_SESSION['customer_rental_flash']);
function ph($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function pm($value): string { return 'Rp ' . number_format((float)$value, 0, ',', '.'); }
function profile_photo($photo): ?string {
    $photo = trim((string)$photo);
    if ($photo === '') return null;
    if (preg_match('#^https?://#i', $photo) && filter_var($photo, FILTER_VALIDATE_URL)) return $photo;
    $photo = ltrim(str_replace('\\', '/', $photo), '/');
    if (preg_match('#(^|/)\.\.(/|$)#', $photo)) return null;
    $root = realpath(__DIR__);
    $full = $root ? realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $photo)) : false;
    if (!$full || !is_file($full) || strpos($full, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0) return null;
    return implode('/', array_map('rawurlencode', explode('/', str_replace('\\', '/', substr($full, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1)))));
}
?><!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Profil | RentCar</title>
    <link rel="stylesheet" href="assets/css/index.css">
    <link rel="stylesheet" href="assets/css/profil.css">
</head>
<body class="rentcar-profile-page">
<main class="profile-shell">
    <header class="profile-header">
        <a href="kendaraan.php" class="profile-back">← Kembali ke kendaraan</a>
        <h1>Profil Pengguna</h1>
        <p>Informasi akun, status penyewaan, pembayaran, dan riwayat pengembalian.</p>
    </header>
    <?php if ($flash) : ?><div class="profile-notice <?= ph($flash['type'] ?? 'info'); ?>" role="status"><?= ph($flash['message'] ?? ''); ?></div><?php endif; ?>
    <section class="profile-card">
        <div><span class="profile-label">Nama pengguna</span><strong><?= ph($profile['nama'] ?? $_SESSION['nama'] ?? 'Pengguna'); ?></strong></div>
        <div><span class="profile-label">Username</span><strong><?= ph($profile['username'] ?? ''); ?></strong></div>
        <div><span class="profile-label">Nomor telepon</span><strong><?= ph($profile['no_hp'] ?? 'Belum diisi'); ?></strong></div>
        <div><span class="profile-label">Alamat</span><strong><?= ph($profile['alamat'] ?? 'Belum diisi'); ?></strong></div>
        <a class="profile-action" href="pengaturan.php">Pengaturan profil</a>
    </section>
    <section class="profile-history">
        <h2>Riwayat penyewaan</h2>
        <?php if (!$transactions) : ?>
            <p class="profile-empty">Belum ada riwayat penyewaan pada akun ini.</p>
        <?php else : ?>
            <div class="profile-transactions">
                <?php foreach ($transactions as $t) :
                    $photo = profile_photo($t['foto'] ?? '');
                    $paymentStmt = $koneksi->prepare('SELECT id_pembayaran,nominal,metode_pembayaran,jenis,status,created_at,nomor_referensi,bukti_pembayaran FROM pembayaran WHERE id_transaksi=? ORDER BY created_at DESC,id_pembayaran DESC');
                    $paymentStmt->bind_param('i', $t['id_transaksi']);
                    $paymentStmt->execute();
                    $payments = $paymentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $paymentStmt->close();
                ?>
                    <article class="profile-rental">
                        <div class="profile-rental-main">
                            <?php if ($photo) : ?><img class="profile-rental-image" src="<?= ph($photo); ?>" alt="<?= ph($t['nama_kendaraan']); ?>" loading="lazy"><?php endif; ?>
                            <div>
                                <span class="profile-code"><?= ph($t['kode_transaksi']); ?></span>
                                <h3><?= ph($t['nama_kendaraan']); ?></h3>
                                <p><?= ph($t['tanggal_sewa']); ?> <?= ph(substr((string)$t['waktu_sewa'],0,5)); ?> sampai <?= ph($t['tanggal_kembali']); ?> <?= ph(substr((string)$t['waktu_kembali'],0,5)); ?> · <?= (int)$t['lama_sewa']; ?> hari</p>
                            </div>
                            <div class="profile-rental-total"><strong><?= pm($t['total_harga']); ?></strong><span>Total sewa</span></div>
                        </div>
                        <div class="profile-payment-summary">
                            <span>Dibayar terverifikasi: <?= pm($t['uang_bayar']); ?></span>
                            <span>Denda: <?= pm($t['total_denda']); ?></span>
                            <span>Sisa tagihan: <?= pm($t['sisa_tagihan']); ?></span>
                            <?php if ((float) $t['pending_sewa'] + (float) $t['pending_denda'] > 0) : ?><span>Menunggu verifikasi: <?= pm((float) $t['pending_sewa'] + (float) $t['pending_denda']); ?></span><?php endif; ?>
                            <span>Pembayaran: <?= ph($t['status_pembayaran']); ?></span>
                            <span>Penyewaan: <?= ph($t['status_transaksi']); ?></span>
                            <?php if (!empty($t['tanggal_pengembalian'])) : ?><span>Dikembalikan: <?= ph($t['tanggal_pengembalian']); ?> <?= ph(substr((string)$t['waktu_pengembalian'],0,5)); ?></span><?php endif; ?>
                        </div>
                        <?php if ($t['status_transaksi'] !== 'Dibatalkan' && (float) $t['sisa_sewa'] > (float) $t['pending_sewa']) : ?>
                            <a class="profile-action profile-pay-action" href="bayar.php?id=<?= (int) $t['id_transaksi']; ?>">Tambah pembayaran sewa</a>
                        <?php endif; ?>
                        <?php if ($t['status_transaksi'] !== 'Dibatalkan' && (float) $t['sisa_denda'] > (float) $t['pending_denda']) : ?>
                            <a class="profile-action profile-pay-action" href="bayar.php?id=<?= (int) $t['id_transaksi']; ?>&amp;jenis=denda">Bayar denda</a>
                        <?php endif; ?>
                        <details class="profile-payment-history"><summary>Riwayat pembayaran (<?= count($payments); ?>)</summary>
                            <?php if (!$payments) : ?><p>Belum ada pembayaran.</p><?php else : ?><div class="profile-payment-items">
                                <?php foreach ($payments as $payment) : ?><div class="profile-payment-item"><span><?= ph($payment['created_at']); ?> · <?= ph($payment['jenis']); ?> · <?= ph($payment['metode_pembayaran']); ?></span><strong><?= pm($payment['nominal']); ?></strong><small><?= ph($payment['status']); ?><?= !empty($payment['nomor_referensi']) ? ' · ' . ph($payment['nomor_referensi']) : ''; ?><?= !empty($payment['bukti_pembayaran']) ? ' · <a href="bukti_pembayaran.php?id=' . (int)$payment['id_pembayaran'] . '">Lihat bukti</a>' : ''; ?></small></div><?php endforeach; ?>
                            </div><?php endif; ?>
                        </details>
                        <?php if ($t['status_transaksi'] === 'Dipesan' && (int) $t['jumlah_pembayaran_terverifikasi'] === 0 && (float) $t['uang_bayar'] <= 0) : ?>
                            <form method="post" class="profile-cancel-form" onsubmit="return confirm('Batalkan reservasi ini?');">
                                <input type="hidden" name="csrf_token" value="<?= ph($_SESSION['csrf_customer_profile']); ?>">
                                <input type="hidden" name="action" value="cancel_reservation">
                                <input type="hidden" name="id_transaksi" value="<?= (int) $t['id_transaksi']; ?>">
                                <button type="submit" class="profile-cancel-button">Batalkan reservasi</button>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
