<?php
session_start();
require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/kendaraan_helper.php';
require_once __DIR__ . '/../config/payment_functions.php';
require_once __DIR__ . '/../config/rentcar_automation.php';

mysqli_set_charset($koneksi, 'utf8mb4');
$errors = [];
$flash = kh_flash_get();
$pageError = '';
$staffId = (int) ($_SESSION['id_user'] ?? 0);
$staffRole = (string) ($_SESSION['level'] ?? '');

try {
    rc_refresh_automation($koneksi);
} catch (Throwable $e) {
    error_log('[RentCar transaction refresh] ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!kh_csrf_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Sesi formulir tidak valid. Muat ulang halaman.');
    }

    $action = (string) ($_POST['action'] ?? '');
    $paymentId = filter_var($_POST['id_pembayaran'] ?? null, FILTER_VALIDATE_INT);

    if (in_array($action, ['verifikasi_pembayaran', 'tolak_pembayaran'], true)) {
        if (!$paymentId || $paymentId < 1) {
            $errors[] = 'Data pembayaran tidak valid.';
        } else {
            try {
                $koneksi->begin_transaction();
                $stmt = $koneksi->prepare(
                    'SELECT p.id_pembayaran,p.id_transaksi,p.nominal,p.status,p.jenis,t.kode_transaksi,t.id_kendaraan,t.status_transaksi
                     FROM pembayaran p JOIN transaksi t ON t.id_transaksi=p.id_transaksi
                     WHERE p.id_pembayaran=? FOR UPDATE'
                );
                $stmt->bind_param('i', $paymentId);
                $stmt->execute();
                $payment = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$payment) {
                    throw new RuntimeException('Pembayaran tidak ditemukan.');
                }
                if ($payment['status'] !== 'Menunggu Verifikasi') {
                    throw new RuntimeException('Pembayaran ini sudah diproses sebelumnya.');
                }
                $transactionId = (int) $payment['id_transaksi'];
                $note = trim((string) ($_POST['catatan_admin'] ?? ''));
                if ((function_exists('mb_strlen') ? mb_strlen($note, 'UTF-8') : strlen($note)) > 255) {
                    throw new RuntimeException('Catatan maksimal 255 karakter.');
                }

                if ($action === 'verifikasi_pembayaran') {
                    $update = $koneksi->prepare(
                        "UPDATE pembayaran SET status='Diverifikasi',catatan_admin=?,verified_at=NOW(),verified_by=?
                         WHERE id_pembayaran=? AND status='Menunggu Verifikasi'"
                    );
                    $update->bind_param('sii', $note, $staffId, $paymentId);
                    $update->execute();
                    if ($update->affected_rows !== 1) {
                        $update->close();
                        throw new RuntimeException('Pembayaran sudah diproses oleh pengguna lain. Muat ulang halaman.');
                    }
                    $update->close();
                    $successMessage = 'Pembayaran ' . $payment['kode_transaksi'] . ' berhasil diverifikasi.';
                    $notifType = 'Pembayaran Masuk';
                    $notifTitle = 'Pembayaran diverifikasi';
                } else {
                    if ($note === '') {
                        throw new RuntimeException('Isi alasan penolakan pembayaran.');
                    }
                    $update = $koneksi->prepare(
                        "UPDATE pembayaran SET status='Ditolak',catatan_admin=?,verified_at=NOW(),verified_by=?
                         WHERE id_pembayaran=? AND status='Menunggu Verifikasi'"
                    );
                    $update->bind_param('sii', $note, $staffId, $paymentId);
                    $update->execute();
                    $update->close();
                    $successMessage = 'Pembayaran ditolak. Pengguna perlu mengirim pembayaran yang valid.';
                    $notifType = 'Pembayaran Ditolak';
                    $notifTitle = 'Pembayaran ditolak';
                }

                if ($action === 'tolak_pembayaran' && $payment['status_transaksi'] === 'Dipesan') {
                    $balanceCheck = $koneksi->prepare(
                        "SELECT
                            COALESCE(SUM(status='Diverifikasi' AND jenis<>'Denda'),0) AS verified_count,
                            COALESCE(SUM(status='Menunggu Verifikasi' AND jenis<>'Denda'),0) AS pending_count
                         FROM pembayaran WHERE id_transaksi=?"
                    );
                    $balanceCheck->bind_param('i', $transactionId);
                    $balanceCheck->execute();
                    $paymentCounts = $balanceCheck->get_result()->fetch_assoc() ?: [];
                    $balanceCheck->close();

                    if ((int) ($paymentCounts['verified_count'] ?? 0) === 0 && (int) ($paymentCounts['pending_count'] ?? 0) === 0) {
                        $cancelReservation = $koneksi->prepare("UPDATE transaksi SET status_transaksi='Dibatalkan' WHERE id_transaksi=? AND status_transaksi='Dipesan'");
                        $cancelReservation->bind_param('i', $transactionId);
                        $cancelReservation->execute();
                        $cancelReservation->close();
                        $successMessage .= ' Reservasi mendatang dibatalkan karena tidak ada pembayaran terverifikasi yang tersisa.';
                    }
                }

                rc_recalculate_transaction($koneksi, $transactionId);
                if ($action === 'tolak_pembayaran') {
                    $vehicleRefresh = $koneksi->prepare('SELECT id_kendaraan FROM transaksi WHERE id_transaksi=? LIMIT 1');
                    $vehicleRefresh->bind_param('i', $transactionId);
                    $vehicleRefresh->execute();
                    $vehicleData = $vehicleRefresh->get_result()->fetch_assoc();
                    $vehicleRefresh->close();
                    if ($vehicleData) {
                        rc_update_vehicle_status($koneksi, (int) $vehicleData['id_kendaraan']);
                    }
                }
                $customerStmt = $koneksi->prepare('SELECT u.id_user FROM users u JOIN transaksi t ON t.id_pelanggan=u.id_pelanggan WHERE t.id_transaksi=? LIMIT 1');
                $customerStmt->bind_param('i', $transactionId);
                $customerStmt->execute();
                $customerRow = $customerStmt->get_result()->fetch_assoc();
                $customerStmt->close();
                if ($customerRow) {
                    $notification = $koneksi->prepare(
                        'INSERT IGNORE INTO notifikasi (id_transaksi,jenis,tanggal_kejadian,untuk_role,id_user_tujuan,judul,pesan)
                         VALUES (?, ?, CURDATE(), \'pelanggan\', ?, ?, ?)'
                    );
                    $notificationMessage = $successMessage;
                    $notification->bind_param('isiss', $transactionId, $notifType, $customerRow['id_user'], $notifTitle, $notificationMessage);
                    $notification->execute();
                    $notification->close();
                }
                $audit = $koneksi->prepare('INSERT INTO audit_log (id_user,aksi,entitas,id_entitas,detail) VALUES (?,?,?,?,?)');
                $auditAction = $action === 'verifikasi_pembayaran' ? 'verifikasi_pembayaran' : 'tolak_pembayaran';
                $entity = 'pembayaran';
                $detail = $successMessage . ($note !== '' ? ' Catatan: ' . $note : '');
                $audit->bind_param('issis', $staffId, $auditAction, $entity, $paymentId, $detail);
                $audit->execute();
                $audit->close();
                $koneksi->commit();
                kh_flash_set('success', $successMessage);
                header('Location: transaksi.php');
                exit;
            } catch (Throwable $e) {
                $koneksi->rollback();
                error_log('[RentCar verify payment] ' . $e->getMessage());
                $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'Pembayaran gagal diproses. Periksa log server.';
            }
        }
    } elseif ($action === 'catat_tunai') {
        $transactionId = filter_var($_POST['id_transaksi'] ?? null, FILTER_VALIDATE_INT);
        $amount = pay_parse_money($_POST['nominal_pembayaran'] ?? '');
        $kind = (string) ($_POST['jenis_penerimaan'] ?? 'Pelunasan');
        if (!$transactionId || $transactionId < 1) $errors[] = 'Transaksi tidak valid.';
        if ($amount === null || $amount <= 0) $errors[] = 'Nominal pembayaran harus lebih besar dari nol.';
        if (!in_array($kind, ['Pembayaran Sebagian','Pelunasan','Denda'], true)) $errors[] = 'Jenis pembayaran tidak valid.';

        if (!$errors) {
            try {
                $koneksi->begin_transaction();
                $stmt = $koneksi->prepare('SELECT id_transaksi,total_harga,uang_bayar,status_transaksi,kode_transaksi FROM transaksi WHERE id_transaksi=? FOR UPDATE');
                $stmt->bind_param('i', $transactionId);
                $stmt->execute();
                $transaction = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$transaction || $transaction['status_transaksi'] === 'Dibatalkan') throw new RuntimeException('Transaksi tidak tersedia untuk pembayaran.');

                if ($kind === 'Denda') {
                    $dueStmt = $koneksi->prepare("SELECT d.total_denda,d.nominal_dibayar,COALESCE((SELECT SUM(p.nominal) FROM pembayaran p WHERE p.id_transaksi=d.id_transaksi AND p.jenis='Denda' AND p.status='Menunggu Verifikasi'),0) AS pending FROM denda d WHERE d.id_transaksi=? FOR UPDATE");
                    $dueStmt->bind_param('i', $transactionId);
                    $dueStmt->execute();
                    $fineRow = $dueStmt->get_result()->fetch_assoc();
                    $dueStmt->close();
                    if (!$fineRow) throw new RuntimeException('Tidak ada tagihan denda untuk transaksi ini.');
                    $remaining = max(0, (float)$fineRow['total_denda'] - (float)$fineRow['nominal_dibayar'] - (float)$fineRow['pending']);
                } else {
                    $paidRentStmt = $koneksi->prepare("SELECT COALESCE(SUM(CASE WHEN status='Diverifikasi' THEN nominal ELSE 0 END),0) AS paid,COALESCE(SUM(CASE WHEN status='Menunggu Verifikasi' THEN nominal ELSE 0 END),0) AS pending FROM pembayaran WHERE id_transaksi=? AND jenis <> 'Denda'");
                    $paidRentStmt->bind_param('i', $transactionId);
                    $paidRentStmt->execute();
                    $paidRentData = $paidRentStmt->get_result()->fetch_assoc() ?: [];
                    $paidRent = (float)($paidRentData['paid'] ?? 0);
                    $pendingRent = (float)($paidRentData['pending'] ?? 0);
                    $paidRentStmt->close();
                    $remaining = max(0, (float)$transaction['total_harga'] - $paidRent - $pendingRent);
                }
                if ($amount > $remaining) throw new RuntimeException('Nominal melebihi sisa tagihan ' . kh_money($remaining) . '.');
                if ($kind === 'Pelunasan' && abs($amount - $remaining) > 0.009) throw new RuntimeException('Nominal pelunasan harus sama dengan sisa tagihan ' . kh_money($remaining) . '.');
                $reference = 'CASH-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
                $note = 'Pembayaran tunai diterima dan dicatat oleh ' . ($_SESSION['nama'] ?? 'petugas');
                $status = 'Diverifikasi';
                $method = 'Tunai di Kantor';
                $requestToken = bin2hex(random_bytes(32));
                $insert = $koneksi->prepare(
                    'INSERT INTO pembayaran (id_transaksi,nominal,metode_pembayaran,jenis,nomor_referensi,status,catatan_admin,request_token,verified_at,verified_by)
                     VALUES (?,?,?,?,?,?,?,?,NOW(),?)'
                );
                $insert->bind_param('idssssssi', $transactionId, $amount, $method, $kind, $reference, $status, $note, $requestToken, $staffId);
                $insert->execute();
                $insert->close();
                rc_recalculate_transaction($koneksi, $transactionId);
                if ($kind === 'Denda') {
                    $refresh = $koneksi->prepare("UPDATE denda SET nominal_dibayar=(SELECT COALESCE(SUM(p.nominal),0) FROM pembayaran p WHERE p.id_transaksi=? AND p.jenis='Denda' AND p.status='Diverifikasi'), status=CASE WHEN nominal_dibayar>=total_denda THEN 'Lunas' WHEN nominal_dibayar>0 THEN 'Sebagian' ELSE 'Belum Dibayar' END WHERE id_transaksi=?");
                    $refresh->bind_param('ii', $transactionId, $transactionId);
                    $refresh->execute();
                    $refresh->close();
                }
                $audit = $koneksi->prepare('INSERT INTO audit_log (id_user,aksi,entitas,id_entitas,detail) VALUES (?,\'catat_tunai\',\'transaksi\',?,?)');
                $detail = 'Nominal: ' . number_format($amount,0,',','.');
                $audit->bind_param('iis', $staffId, $transactionId, $detail);
                $audit->execute();
                $audit->close();
                $koneksi->commit();
                kh_flash_set('success', 'Penerimaan tunai berhasil dicatat untuk ' . $transaction['kode_transaksi'] . '.');
                header('Location: transaksi.php');
                exit;
            } catch (Throwable $e) {
                $koneksi->rollback();
                error_log('[RentCar cash payment] ' . $e->getMessage());
                $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'Pembayaran tunai gagal disimpan.';
            }
        }
    }
}

try {
    $statsResult = $koneksi->query(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(status_transaksi IN ('Dipesan','Berjalan','Terlambat')),0) AS aktif,
                COALESCE(SUM(status_pembayaran='Lunas'),0) AS lunas,
                COALESCE(SUM(status_pembayaran NOT IN ('Lunas','Pengembalian Dana')),0) AS belum_lunas
         FROM transaksi"
    );
    $transactionStats = $statsResult->fetch_assoc() ?: ['total'=>0,'aktif'=>0,'lunas'=>0,'belum_lunas'=>0];

    $pendingResult = $koneksi->query(
        "SELECT p.id_pembayaran,p.id_transaksi,p.nominal,p.metode_pembayaran,p.jenis,p.nomor_referensi,
                p.bukti_pembayaran,p.created_at,t.kode_transaksi,t.total_harga,
                pl.nama AS nama_pelanggan,k.nama_kendaraan
         FROM pembayaran p JOIN transaksi t ON t.id_transaksi=p.id_transaksi
         JOIN pelanggan pl ON pl.id_pelanggan=t.id_pelanggan
         JOIN kendaraan k ON k.id_kendaraan=t.id_kendaraan
         WHERE p.status='Menunggu Verifikasi'
         ORDER BY p.created_at ASC,p.id_pembayaran ASC LIMIT 100"
    );
    $pendingPayments = $pendingResult->fetch_all(MYSQLI_ASSOC);

    $search = trim((string)($_GET['q'] ?? ''));
    $search = function_exists('mb_substr') ? mb_substr($search, 0, 80, 'UTF-8') : substr($search, 0, 80);
    $statusFilter = (string)($_GET['status'] ?? '');
    $paymentFilter = (string)($_GET['pembayaran'] ?? '');
    $validStatuses = ['', 'Dipesan','Berjalan','Terlambat','Selesai','Dibatalkan'];
    $validPaymentStatuses = ['', 'Belum Dibayar','Menunggu Verifikasi','Belum Lunas','DP Dibayar','Lunas','Gagal'];
    if (!in_array($statusFilter,$validStatuses,true)) $statusFilter='';
    if (!in_array($paymentFilter,$validPaymentStatuses,true)) $paymentFilter='';

    $whereParts = ['1=1']; $types=''; $params=[];
    if ($statusFilter !== '') { $whereParts[]='t.status_transaksi=?'; $types.='s'; $params[]=$statusFilter; }
    if ($paymentFilter !== '') { $whereParts[]='t.status_pembayaran=?'; $types.='s'; $params[]=$paymentFilter; }
    if ($search !== '') {
        $whereParts[]='(t.kode_transaksi LIKE ? OR pl.nama LIKE ? OR k.nama_kendaraan LIKE ? OR pl.no_hp LIKE ?)';
        $keyword='%'.$search.'%'; $types.='ssss'; array_push($params,$keyword,$keyword,$keyword,$keyword);
    }
    $whereSql='WHERE '.implode(' AND ',$whereParts);
    $listStmt=$koneksi->prepare(
        "SELECT t.id_transaksi,t.kode_transaksi,t.tanggal_sewa,t.waktu_sewa,t.tanggal_kembali,t.waktu_kembali,
                t.lama_sewa,t.harga_sewa,t.total_harga,t.uang_bayar,t.status_pembayaran,t.metode_pembayaran,
                t.status_transaksi,t.created_at,pl.nama AS nama_pelanggan,pl.no_hp,
                k.nama_kendaraan,k.kode_kendaraan,COALESCE(d.total_denda,0) AS denda,
                GREATEST(0,t.total_harga-t.uang_bayar) + GREATEST(0,COALESCE(d.total_denda,0)-COALESCE(d.nominal_dibayar,0)) AS sisa_tagihan,
                COALESCE(pp.pending_count,0) AS pending_count
         FROM transaksi t
         LEFT JOIN pelanggan pl ON pl.id_pelanggan=t.id_pelanggan
         LEFT JOIN kendaraan k ON k.id_kendaraan=t.id_kendaraan
         LEFT JOIN denda d ON d.id_transaksi=t.id_transaksi
         LEFT JOIN (SELECT id_transaksi,COUNT(*) AS pending_count FROM pembayaran WHERE status='Menunggu Verifikasi' GROUP BY id_transaksi) pp ON pp.id_transaksi=t.id_transaksi
         $whereSql ORDER BY t.created_at DESC,t.id_transaksi DESC LIMIT 200"
    );
    kh_bind($listStmt,$types,$params);
    $listStmt->execute();
    $transactions=$listStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $listStmt->close();
} catch (Throwable $e) {
    error_log('[RentCar transaction page] ' . $e->getMessage());
    $pageError='Data transaksi gagal dimuat. Pastikan database RentCar sudah diimpor dengan skema terbaru.';
    $transactionStats=['total'=>0,'aktif'=>0,'lunas'=>0,'belum_lunas'=>0];
    $pendingPayments=[];
    $transactions=[];
    $search=$search ?? '';
    $statusFilter=$statusFilter ?? '';
    $paymentFilter=$paymentFilter ?? '';
}

kh_render_start('Transaksi','transaksi','transaksi.css','transaksi.js');
?>
<section class="vehicle-page-heading">
    <div>
        <div class="vehicle-eyebrow">OPERASIONAL</div>
        <h1>Transaksi dan Pembayaran</h1>
        <p>Pesanan pelanggan tercatat otomatis. Periksa pembayaran masuk dan catat penerimaan tunai bila uang sudah diterima.</p>
    </div>
</section>

<?php if ($flash) : ?>
    <div class="vehicle-alert vehicle-alert-<?= kh_h($flash['type'] ?? 'info'); ?>" role="status"><i class="bi bi-info-circle"></i><span><?= kh_h($flash['message'] ?? ''); ?></span></div>
<?php endif; ?>
<?php foreach ($errors as $error) : ?>
    <div class="vehicle-alert vehicle-alert-error" role="alert"><i class="bi bi-exclamation-circle"></i><span><?= kh_h($error); ?></span></div>
<?php endforeach; ?>
<?php if ($pageError !== '') : ?><div class="vehicle-alert vehicle-alert-error" role="alert"><?= kh_h($pageError); ?></div><?php endif; ?>

<div class="module-stats-grid">
    <article class="module-stat-card"><span>Total transaksi</span><strong><?= number_format((int)$transactionStats['total'],0,',','.'); ?></strong></article>
    <article class="module-stat-card"><span>Penyewaan aktif</span><strong><?= number_format((int)$transactionStats['aktif'],0,',','.'); ?></strong></article>
    <article class="module-stat-card"><span>Pembayaran lunas</span><strong><?= number_format((int)$transactionStats['lunas'],0,',','.'); ?></strong></article>
    <article class="module-stat-card"><span>Belum lunas</span><strong><?= number_format((int)$transactionStats['belum_lunas'],0,',','.'); ?></strong></article>
</div>

<section class="module-panel">
    <div class="vehicle-panel-heading"><div><h2>Pembayaran menunggu verifikasi</h2><p>Bukti dari pengguna diperiksa sebelum nominal dianggap diterima.</p></div><span class="module-status is-amber"><?= count($pendingPayments); ?> menunggu</span></div>
    <?php if (!$pendingPayments) : ?>
        <div class="vehicle-empty-state"><span><i class="bi bi-check-circle"></i></span><strong>Tidak ada pembayaran tertunda</strong><p>Pembayaran baru akan muncul di sini secara otomatis.</p></div>
    <?php else : ?>
        <div class="module-table-wrap"><table class="vehicle-table module-table"><thead><tr><th>Transaksi</th><th>Pelanggan</th><th>Kendaraan</th><th>Nominal</th><th>Metode</th><th>Bukti</th><th>Tindakan</th></tr></thead><tbody>
        <?php foreach ($pendingPayments as $payment) : $proofUrl = !empty($payment['bukti_pembayaran']) ? '../bukti_pembayaran.php?id=' . (int) $payment['id_pembayaran'] : null; ?>
            <tr><td><strong><?= kh_h($payment['kode_transaksi']); ?></strong><small><?= kh_h($payment['created_at']); ?></small></td>
                <td><?= kh_h($payment['nama_pelanggan']); ?></td><td><?= kh_h($payment['nama_kendaraan']); ?></td>
                <td><strong><?= kh_h(kh_money($payment['nominal'])); ?></strong><small><?= kh_h($payment['jenis']); ?></small></td>
                <td><?= kh_h($payment['metode_pembayaran']); ?><?php if (!empty($payment['nomor_referensi'])) : ?><small><?= kh_h($payment['nomor_referensi']); ?></small><?php endif; ?></td>
                <td><?php if ($proofUrl) : ?><a href="<?= kh_h($proofUrl); ?>" target="_blank" rel="noopener">Lihat bukti</a><?php else : ?><span>Tanpa lampiran</span><?php endif; ?></td>
                <td><details class="payment-inline-details"><summary>Periksa</summary>
                    <form method="post" class="payment-inline-form"><input type="hidden" name="csrf_token" value="<?= kh_h($_SESSION['csrf_kendaraan']); ?>"><input type="hidden" name="id_pembayaran" value="<?= (int)$payment['id_pembayaran']; ?>"><label>Catatan<input type="text" name="catatan_admin" maxlength="255" placeholder="Opsional untuk verifikasi, wajib jika ditolak"></label><div class="payment-action-row"><button type="submit" name="action" value="verifikasi_pembayaran" class="vehicle-btn vehicle-btn-primary">Verifikasi</button><button type="submit" name="action" value="tolak_pembayaran" class="vehicle-btn vehicle-btn-danger" formnovalidate onclick="return confirm('Tolak pembayaran ini? Isi alasan pada catatan terlebih dahulu.')">Tolak</button></div></form>
                </details></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>

<section class="module-panel">
    <div class="vehicle-panel-heading"><div><h2>Daftar transaksi</h2><p>Data langsung berasal dari database. Total tagihan dan pembayaran ditampilkan terpisah.</p></div></div>
    <form method="get" action="transaksi.php" class="module-filters" role="search">
        <label class="module-search-field"><i class="bi bi-search"></i><input type="search" name="q" value="<?= kh_h($search); ?>" placeholder="Cari kode, pelanggan, kendaraan, atau nomor HP" maxlength="80"></label>
        <label class="module-field module-filter-field"><span>Status sewa</span><select name="status"><option value="">Semua status</option><?php foreach (['Dipesan','Berjalan','Terlambat','Selesai','Dibatalkan'] as $statusOption) : ?><option value="<?= kh_h($statusOption); ?>" <?= $statusFilter===$statusOption?'selected':''; ?>><?= kh_h($statusOption); ?></option><?php endforeach; ?></select></label>
        <label class="module-field module-filter-field"><span>Status pembayaran</span><select name="pembayaran"><option value="">Semua pembayaran</option><?php foreach (['Belum Dibayar','Menunggu Verifikasi','Belum Lunas','DP Dibayar','Lunas','Gagal'] as $statusOption) : ?><option value="<?= kh_h($statusOption); ?>" <?= $paymentFilter===$statusOption?'selected':''; ?>><?= kh_h($statusOption); ?></option><?php endforeach; ?></select></label>
        <button type="submit" class="vehicle-btn vehicle-btn-secondary"><i class="bi bi-funnel"></i> Filter</button><a href="transaksi.php" class="vehicle-btn vehicle-btn-quiet">Reset</a>
    </form>
    <div class="module-table-wrap"><table class="vehicle-table module-table"><thead><tr><th>Kode / Jadwal</th><th>Pelanggan</th><th>Kendaraan</th><th>Total + denda</th><th>Terbayar</th><th>Sisa tagihan</th><th>Status</th><th>Penerimaan tunai</th></tr></thead><tbody>
    <?php if ($transactions) : foreach ($transactions as $transaction) : ?>
        <tr><td><div class="module-primary-cell"><strong><?= kh_h($transaction['kode_transaksi']); ?></strong><span><?= kh_h($transaction['tanggal_sewa']); ?> <?= kh_h(substr((string)$transaction['waktu_sewa'],0,5)); ?> s.d. <?= kh_h($transaction['tanggal_kembali']); ?> <?= kh_h(substr((string)$transaction['waktu_kembali'],0,5)); ?></span><small><?= (int)$transaction['lama_sewa']; ?> hari · <?= kh_h($transaction['created_at']); ?></small></div></td>
        <td><div class="module-primary-cell"><strong><?= kh_h($transaction['nama_pelanggan'] ?? 'Pelanggan'); ?></strong><span><?= kh_h($transaction['no_hp'] ?? '-'); ?></span></div></td>
        <td><div class="module-primary-cell"><strong><?= kh_h($transaction['nama_kendaraan'] ?? 'Kendaraan'); ?></strong><span><?= kh_h($transaction['kode_kendaraan'] ?? '-'); ?></span></div></td>
        <td><strong><?= kh_h(kh_money((float)$transaction['total_harga']+(float)$transaction['denda'])); ?></strong><small>Sewa <?= kh_h(kh_money($transaction['total_harga'])); ?> · Denda <?= kh_h(kh_money($transaction['denda'])); ?></small></td>
        <td><?= kh_h(kh_money($transaction['uang_bayar'])); ?></td><td><strong><?= kh_h(kh_money($transaction['sisa_tagihan'])); ?></strong></td>
        <td><span class="module-status <?= $transaction['status_pembayaran']==='Lunas'?'is-green':'is-amber'; ?>"><?= kh_h($transaction['status_pembayaran']); ?></span><small><?= kh_h($transaction['status_transaksi']); ?></small><small><?= (int)$transaction['pending_count']; ?> menunggu</small></td>
        <td><details class="payment-inline-details"><summary>Catat tunai</summary><form method="post" class="payment-inline-form" data-cash-payment-form><input type="hidden" name="csrf_token" value="<?= kh_h($_SESSION['csrf_kendaraan']); ?>"><input type="hidden" name="action" value="catat_tunai"><input type="hidden" name="id_transaksi" value="<?= (int)$transaction['id_transaksi']; ?>"><label>Jenis penerimaan<select name="jenis_penerimaan"><option value="Pembayaran Sebagian">Pembayaran sebagian</option><option value="Pelunasan">Pelunasan sewa</option><option value="Denda">Pembayaran denda</option></select></label><label>Nominal diterima<input type="text" name="nominal_pembayaran" data-money-input inputmode="decimal" autocomplete="off" placeholder="0" required></label><button type="submit" class="vehicle-btn vehicle-btn-primary">Simpan penerimaan</button></form></details></td></tr>
    <?php endforeach; else : ?><tr><td colspan="8"><div class="vehicle-empty-state"><span><i class="bi bi-receipt"></i></span><strong>Belum ada transaksi</strong><p>Pesanan pelanggan akan muncul di sini setelah disimpan.</p></div></td></tr><?php endif; ?>
    </tbody></table></div>
</section>
<script src="../assets/js/money-input.js" defer></script>
<?php kh_render_end(); ?>
