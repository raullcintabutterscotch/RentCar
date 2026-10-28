<?php

session_start();

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/kendaraan_helper.php';
require_once __DIR__ . '/../config/rentcar_automation.php';

mysqli_set_charset($koneksi, 'utf8mb4');
try { rc_refresh_automation($koneksi); } catch (Throwable $e) { error_log('[RentCar return refresh] ' . $e->getMessage()); }

$errors = [];
$flash = kh_flash_get();
$pageError = '';
$fineSettingsDisplay = $koneksi->query('SELECT denda_per_hari FROM pengaturan_rentcar WHERE id_pengaturan=1')->fetch_assoc() ?: [];
$fineRateDisplay = max(0, (float)($fineSettingsDisplay['denda_per_hari'] ?? 100000));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!kh_csrf_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Permintaan tidak valid. Muat ulang halaman, lalu coba kembali.');
    }

    if ((string) ($_POST['action'] ?? '') === 'proses_pengembalian') {
        $transactionId = filter_var($_POST['id_transaksi'] ?? null, FILTER_VALIDATE_INT);
        $returnDate = trim((string) ($_POST['tanggal_pengembalian'] ?? date('Y-m-d')));
        $returnTime = trim((string) ($_POST['waktu_pengembalian'] ?? date('H:i')));
        $condition = (string) ($_POST['status_kondisi'] ?? 'Baik');
        $note = trim((string) ($_POST['keterangan'] ?? ''));
        $parsedReturnDate = DateTimeImmutable::createFromFormat('!Y-m-d', $returnDate);
        $validReturnDate = $parsedReturnDate && $parsedReturnDate->format('Y-m-d') === $returnDate;
        $validReturnTime = preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $returnTime) === 1;

        if (!$transactionId || $transactionId < 1) $errors[] = 'Pilih transaksi yang akan dikembalikan.';
        if (!$validReturnDate || !$validReturnTime) $errors[] = 'Tanggal atau waktu pengembalian tidak valid.';
        if (!in_array($condition, ['Baik','Perlu Pemeriksaan','Perawatan'], true)) $errors[] = 'Kondisi kendaraan tidak valid.';
        if (kh_length($note) > 255) $errors[] = 'Keterangan maksimal 255 karakter.';

        if (!$errors) {
            try {
                $koneksi->begin_transaction();
                $stmt = $koneksi->prepare(
                    "SELECT t.id_transaksi,t.kode_transaksi,t.id_kendaraan,t.tanggal_sewa,t.waktu_sewa,
                            t.tanggal_kembali,t.waktu_kembali,t.total_harga,t.uang_bayar,t.status_transaksi,
                            k.nama_kendaraan
                     FROM transaksi t JOIN kendaraan k ON k.id_kendaraan=t.id_kendaraan
                     WHERE t.id_transaksi=? FOR UPDATE"
                );
                $stmt->bind_param('i', $transactionId);
                $stmt->execute();
                $transaction = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$transaction) throw new RuntimeException('Transaksi tidak ditemukan.');
                if (!in_array($transaction['status_transaksi'], ['Berjalan','Terlambat'], true)) {
                    throw new RuntimeException('Transaksi ini sudah selesai atau sudah dikembalikan.');
                }
                $actualReturn = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $returnDate . ' ' . $returnTime);
                $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $transaction['tanggal_sewa'] . ' ' . $transaction['waktu_sewa']);
                $deadline = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $transaction['tanggal_kembali'] . ' ' . $transaction['waktu_kembali']);
                if (!$actualReturn || !$start || !$deadline || $actualReturn < $start) throw new RuntimeException('Waktu pengembalian tidak boleh sebelum waktu sewa.');
                if ($actualReturn > new DateTimeImmutable('now')) throw new RuntimeException('Waktu pengembalian tidak boleh berada di masa depan.');

                $lateSeconds = max(0, $actualReturn->getTimestamp() - $deadline->getTimestamp());
                $lateDays = $lateSeconds > 0 ? (int) ceil($lateSeconds / 86400) : 0;
                $settings = $koneksi->query('SELECT denda_per_hari FROM pengaturan_rentcar WHERE id_pengaturan=1')->fetch_assoc() ?: [];
                $fineRate = max(0, (float) ($settings['denda_per_hari'] ?? 100000));
                $fine = $lateDays * $fineRate;
                $returnDateTime = $actualReturn->format('Y-m-d H:i:s');
                $confirmedBy = (int)($_SESSION['id_user'] ?? 0);
                $noteValue = $note !== '' ? $note : null;

                if ($lateDays > 0) {
                    $fineStmt = $koneksi->prepare(
                        "INSERT INTO denda (id_transaksi,hari_terlambat,tarif_per_hari,total_denda,status,dihitung_pada)
                         VALUES (?,?,?,?, 'Belum Dibayar',NOW())
                         ON DUPLICATE KEY UPDATE hari_terlambat=VALUES(hari_terlambat),tarif_per_hari=VALUES(tarif_per_hari),total_denda=VALUES(total_denda),dihitung_pada=NOW()"
                    );
                    $fineStmt->bind_param('iidd', $transactionId, $lateDays, $fineRate, $fine);
                    $fineStmt->execute();
                    $fineStmt->close();
                } else {
                    $fine = 0.0;
                }

                $finePaid = 0.0;
                if ($lateDays > 0) {
                    $paidStmt = $koneksi->prepare("SELECT COALESCE(SUM(nominal),0) AS paid FROM pembayaran WHERE id_transaksi=? AND jenis='Denda' AND status='Diverifikasi'");
                    $paidStmt->bind_param('i', $transactionId);
                    $paidStmt->execute();
                    $finePaid = (float)($paidStmt->get_result()->fetch_assoc()['paid'] ?? 0);
                    $paidStmt->close();
                    $fineStatus = $finePaid >= $fine ? 'Lunas' : ($finePaid > 0 ? 'Sebagian' : 'Belum Dibayar');
                    $fineUpdate = $koneksi->prepare('UPDATE denda SET nominal_dibayar=?,status=? WHERE id_transaksi=?');
                    $fineUpdate->bind_param('dsi', $finePaid, $fineStatus, $transactionId);
                    $fineUpdate->execute();
                    $fineUpdate->close();
                }

                $totalDue = (float)$transaction['total_harga'] + $fine;
                // bind signature: i, s, s, i, d, d, d, s, s, i, s.
                $returnInsert = $koneksi->prepare(
                    'INSERT INTO pengembalian (id_transaksi,tanggal_pengembalian,waktu_pengembalian,terlambat,denda,total_bayar,jumlah_denda_dibayar,keterangan,status_kondisi,dikonfirmasi_oleh,dikonfirmasi_pada)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                );
                $returnInsert->bind_param('issidddssis', $transactionId, $returnDate, $returnTime, $lateDays, $fine, $totalDue, $finePaid, $noteValue, $condition, $confirmedBy, $returnDateTime);
                $returnInsert->execute();
                $returnInsert->close();

                $updateTransaction = $koneksi->prepare("UPDATE transaksi SET status_transaksi='Selesai' WHERE id_transaksi=?");
                $updateTransaction->bind_param('i', $transactionId);
                $updateTransaction->execute();
                $updateTransaction->close();
                rc_recalculate_transaction($koneksi, $transactionId);
                $vehicleId = (int)$transaction['id_kendaraan'];
                if ($condition === 'Perawatan') {
                    $vehicleUpdate = $koneksi->prepare("UPDATE kendaraan SET status='Perawatan' WHERE id_kendaraan=?");
                    $vehicleUpdate->bind_param('i',$vehicleId); $vehicleUpdate->execute(); $vehicleUpdate->close();
                } else {
                    rc_update_vehicle_status($koneksi, $vehicleId);
                }
                $audit = $koneksi->prepare('INSERT INTO audit_log (id_user,aksi,entitas,id_entitas,detail) VALUES (?,\'konfirmasi_pengembalian\',\'transaksi\',?,?)');
                $auditDetail = 'Kondisi: ' . $condition . '; terlambat: ' . $lateDays . ' hari; denda: ' . $fine;
                $audit->bind_param('iis', $confirmedBy, $transactionId, $auditDetail);
                $audit->execute(); $audit->close();

                $koneksi->commit();
                kh_flash_set('success', 'Pengembalian ' . $transaction['kode_transaksi'] . ' berhasil dikonfirmasi. Denda: ' . kh_money($fine) . '.');
                header('Location: pengembalian.php');
                exit;
            } catch (Throwable $exception) {
                $koneksi->rollback();
                error_log('[RentCar return confirm] ' . $exception->getMessage());
                $errors[] = $exception instanceof RuntimeException ? $exception->getMessage() : 'Pengembalian gagal diproses. Periksa database dan log server.';
            }
        }
    }
}

/* TRANSAKSI YANG MASIH BERJALAN */
$pendingReturns = [];
$returnRecords = [];
$returnStats = [
    'total' => 0,
    'terlambat' => 0,
    'denda' => 0,
];

try {
    $pendingResult = $koneksi->query(
        "SELECT
            t.id_transaksi,
            t.kode_transaksi,
            t.tanggal_kembali,
            t.waktu_kembali,
            t.total_harga,
            t.uang_bayar,
            t.lama_sewa,
            p.nama AS nama_pelanggan,
            k.nama_kendaraan
         FROM transaksi t
         INNER JOIN pelanggan p ON p.id_pelanggan = t.id_pelanggan
         INNER JOIN kendaraan k ON k.id_kendaraan = t.id_kendaraan
         WHERE t.status_transaksi IN ('Berjalan','Terlambat')
         ORDER BY t.tanggal_kembali ASC, t.id_transaksi ASC"
    );
    $pendingReturns = $pendingResult ? $pendingResult->fetch_all(MYSQLI_ASSOC) : [];

    $historyResult = $koneksi->query(
        'SELECT
            r.id_pengembalian,
            r.tanggal_pengembalian,
            r.terlambat,
            r.denda,
            r.total_bayar,
            r.keterangan,
            t.kode_transaksi,
            p.nama AS nama_pelanggan,
            k.nama_kendaraan
         FROM pengembalian r
         INNER JOIN transaksi t ON t.id_transaksi = r.id_transaksi
         LEFT JOIN pelanggan p ON p.id_pelanggan = t.id_pelanggan
         LEFT JOIN kendaraan k ON k.id_kendaraan = t.id_kendaraan
         ORDER BY r.tanggal_pengembalian DESC, r.id_pengembalian DESC
         LIMIT 100'
    );
    $returnRecords = $historyResult ? $historyResult->fetch_all(MYSQLI_ASSOC) : [];

    $statsResult = $koneksi->query(
        'SELECT
            COUNT(*) AS total,
            COALESCE(SUM(terlambat > 0), 0) AS terlambat,
            COALESCE(SUM(denda), 0) AS denda
         FROM pengembalian'
    );
    if ($statsResult) {
        $returnStats = $statsResult->fetch_assoc() ?: $returnStats;
    }
} catch (Throwable $exception) {
    $pageError = 'Data pengembalian gagal dimuat. Periksa struktur tabel transaksi dan pengembalian.';
}

kh_render_start('Pengembalian', 'pengembalian', 'pengembalian.css', 'pengembalian.js');
?>

<section class="vehicle-page-heading">
    <div>
        <div class="vehicle-eyebrow">OPERASIONAL</div>
        <h1>Pengembalian Kendaraan</h1>
        <p>Proses pengembalian transaksi berjalan, hitung keterlambatan, dan tentukan status kendaraan.</p>
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
            <strong>Pengembalian belum diproses.</strong>
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

<section class="module-stats">
    <article class="module-stat-card">
        <span class="module-stat-icon"><i class="bi bi-arrow-return-left"></i></span>
        <div><span>Total pengembalian</span><strong><?= number_format((int) $returnStats['total'], 0, ',', '.'); ?></strong></div>
    </article>
    <article class="module-stat-card">
        <span class="module-stat-icon is-amber"><i class="bi bi-clock-history"></i></span>
        <div><span>Terlambat</span><strong><?= number_format((int) $returnStats['terlambat'], 0, ',', '.'); ?></strong></div>
    </article>
    <article class="module-stat-card">
        <span class="module-stat-icon is-red"><i class="bi bi-cash-coin"></i></span>
        <div><span>Total denda</span><strong><?= kh_h(kh_money($returnStats['denda'])); ?></strong></div>
    </article>
</section>

<details class="module-disclosure" id="returnFormDisclosure" <?= $errors ? 'open' : ''; ?>>
    <summary><i class="bi bi-clipboard-check"></i> Proses pengembalian</summary>

    <?php if (!$pendingReturns) : ?>
        <div class="module-inline-message">Tidak ada penyewaan aktif atau terlambat yang menunggu pengembalian.</div>
    <?php else : ?>
        <form method="post" class="module-form return-form" id="returnForm">
            <input type="hidden" name="csrf_token" value="<?= kh_h($_SESSION['csrf_kendaraan']); ?>">
            <input type="hidden" name="action" value="proses_pengembalian">

            <label class="module-field module-field-full">
                <span>Transaksi berjalan</span>
                <select name="id_transaksi" id="returnTransactionSelect" required>
                    <option value="">Pilih transaksi</option>
                    <?php foreach ($pendingReturns as $pending) : ?>
                        <option
                            value="<?= (int) $pending['id_transaksi']; ?>"
                            data-due-date="<?= kh_h($pending['tanggal_kembali']); ?>"
                            data-due-time="<?= kh_h($pending['waktu_kembali']); ?>"
                            data-rate="<?= kh_h($fineRateDisplay); ?>"
                        >
                            <?= kh_h($pending['kode_transaksi'] . ' · ' . $pending['nama_pelanggan'] . ' · ' . $pending['nama_kendaraan']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="module-field">
                <span>Tanggal pengembalian aktual</span>
                <input type="date" name="tanggal_pengembalian" id="returnDate" value="<?= date('Y-m-d'); ?>" required>
            </label>
            <label class="module-field">
                <span>Waktu pengembalian aktual</span>
                <input type="time" name="waktu_pengembalian" id="returnTime" value="<?= date('H:i'); ?>" required>
            </label>
            <div class="module-field">
                <span>Tarif denda per hari</span>
                <strong><?= kh_h(kh_money($fineRateDisplay)); ?></strong>
                <small>Tarif dikunci sesuai pengaturan sistem. Petugas tidak perlu menghitung atau memasukkan nominal denda.</small>
                <input type="hidden" id="returnFinePerDay" value="<?= kh_h($fineRateDisplay); ?>">
            </div>

            <label class="module-field">
                <span>Status kendaraan setelah kembali</span>
                <select name="status_kondisi" required>
                    <option value="Baik">Baik, kendaraan dapat digunakan</option>
                    <option value="Perawatan">Perawatan</option>
                </select>
            </label>

            <label class="module-field module-field-full">
                <span>Keterangan (opsional)</span>
                <input type="text" name="keterangan" maxlength="255" placeholder="Contoh: kondisi kendaraan normal">
            </label>

            <div class="return-estimate module-field-full" aria-live="polite">
                <span>Estimasi keterlambatan</span>
                <strong id="returnLateEstimate">Pilih transaksi</strong>
                <small id="returnFineEstimate">Denda dihitung dari jumlah hari keterlambatan dikali denda per hari.</small>
            </div>

            <div class="module-form-actions module-form-actions-wide">
                <button type="submit" class="vehicle-btn vehicle-btn-primary" data-confirm-return>
                    <i class="bi bi-check2-circle"></i> Simpan pengembalian
                </button>
            </div>
        </form>
    <?php endif; ?>
</details>

<section class="vehicle-panel module-list-panel">
    <div class="vehicle-panel-heading">
        <div>
            <h2>Riwayat Pengembalian</h2>
            <p>Maksimal 100 pengembalian terbaru.</p>
        </div>
    </div>

    <div class="module-table-wrap">
        <table class="vehicle-table module-table">
            <thead>
                <tr>
                    <th>Tanggal</th>
                    <th>Transaksi</th>
                    <th>Pelanggan</th>
                    <th>Kendaraan</th>
                    <th>Terlambat</th>
                    <th>Denda</th>
                    <th>Total tagihan</th>
                    <th>Keterangan</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($returnRecords) : ?>
                    <?php foreach ($returnRecords as $record) : ?>
                        <tr>
                            <td><?= kh_h($record['tanggal_pengembalian']); ?></td>
                            <td><strong><?= kh_h($record['kode_transaksi']); ?></strong></td>
                            <td><?= kh_h($record['nama_pelanggan'] ?? '-'); ?></td>
                            <td><?= kh_h($record['nama_kendaraan'] ?? '-'); ?></td>
                            <td>
                                <span class="module-status <?= (int) $record['terlambat'] > 0 ? 'is-amber' : 'is-green'; ?>">
                                    <?= (int) $record['terlambat']; ?> hari
                                </span>
                            </td>
                            <td><?= kh_h(kh_money($record['denda'])); ?></td>
                            <td><strong><?= kh_h(kh_money($record['total_bayar'])); ?></strong></td>
                            <td><?= kh_h($record['keterangan'] ?: '-'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="8">
                            <div class="vehicle-empty-state">
                                <span><i class="bi bi-arrow-return-left"></i></span>
                                <strong>Belum ada pengembalian</strong>
                                <p>Riwayat pengembalian akan muncul setelah transaksi diproses.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php kh_render_end(); ?>
