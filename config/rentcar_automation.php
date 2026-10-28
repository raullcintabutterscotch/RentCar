<?php
/** Operasi idempoten untuk status jadwal, keterlambatan, denda, dan notifikasi. */

function rc_datetime(string $date, string $time = '09:00:00'): ?DateTimeImmutable
{
    $value = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date . ' ' . $time);
    return $value && $value->format('Y-m-d H:i:s') === $date . ' ' . $time ? $value : null;
}

function rc_update_vehicle_status(mysqli $db, int $vehicleId): void
{
    $stmt = $db->prepare(
        "UPDATE kendaraan k
         SET k.status = CASE
            WHEN k.status = 'Perawatan' THEN 'Perawatan'
            WHEN EXISTS (
                SELECT 1 FROM transaksi t
                WHERE t.id_kendaraan = k.id_kendaraan
                  AND t.status_transaksi IN ('Dipesan','Berjalan','Terlambat')
            ) THEN 'Disewa'
            ELSE 'Tersedia'
         END
         WHERE k.id_kendaraan = ?"
    );
    $stmt->bind_param('i', $vehicleId);
    $stmt->execute();
    $stmt->close();
}

function rc_refresh_automation(mysqli $db): void
{
    $settingsResult = $db->query(
        'SELECT persentase_dp, denda_per_hari, pengingat_sebelum_jam
         FROM pengaturan_rentcar WHERE id_pengaturan = 1 LIMIT 1'
    );
    $settings = $settingsResult->fetch_assoc() ?: [];
    $fineRate = max(0.0, (float) ($settings['denda_per_hari'] ?? 100000));
    $reminderHours = max(1, min(168, (int) ($settings['pengingat_sebelum_jam'] ?? 24)));

    // Status transaksi berubah sesuai jam sistem, tidak berdasarkan kunjungan halaman.
    $db->query(
        "UPDATE transaksi
         SET status_transaksi = 'Berjalan'
         WHERE status_transaksi = 'Dipesan'
           AND CONCAT(tanggal_sewa, ' ', waktu_sewa) <= NOW()
           AND CONCAT(tanggal_kembali, ' ', waktu_kembali) >= NOW()"
    );
    $db->query(
        "UPDATE transaksi t
         SET t.status_transaksi = 'Terlambat'
         WHERE t.status_transaksi IN ('Berjalan','Dipesan')
           AND CONCAT(t.tanggal_kembali, ' ', t.waktu_kembali) < NOW()
           AND NOT EXISTS (SELECT 1 FROM pengembalian r WHERE r.id_transaksi = t.id_transaksi)"
    );

    // Satu record denda per transaksi. Pembaruan hanya mengganti hitungan saat ini, tidak menambah denda berulang.
    $fineStmt = $db->prepare(
        "INSERT INTO denda (id_transaksi, hari_terlambat, tarif_per_hari, total_denda, status, dihitung_pada)
         SELECT t.id_transaksi,
                GREATEST(1, CEIL(TIMESTAMPDIFF(SECOND, CONCAT(t.tanggal_kembali, ' ', t.waktu_kembali), NOW()) / 86400)),
                ?,
                GREATEST(1, CEIL(TIMESTAMPDIFF(SECOND, CONCAT(t.tanggal_kembali, ' ', t.waktu_kembali), NOW()) / 86400)) * ?,
                'Belum Dibayar', NOW()
         FROM transaksi t
         WHERE t.status_transaksi = 'Terlambat'
           AND NOT EXISTS (SELECT 1 FROM pengembalian r WHERE r.id_transaksi = t.id_transaksi)
         ON DUPLICATE KEY UPDATE
            hari_terlambat = VALUES(hari_terlambat),
            tarif_per_hari = VALUES(tarif_per_hari),
            total_denda = VALUES(total_denda),
            dihitung_pada = NOW()"
    );
    $fineStmt->bind_param('dd', $fineRate, $fineRate);
    $fineStmt->execute();
    $fineStmt->close();

    $db->query(
        "UPDATE denda d
         SET d.nominal_dibayar = COALESCE((
                SELECT SUM(p.nominal) FROM pembayaran p
                WHERE p.id_transaksi = d.id_transaksi
                  AND p.jenis = 'Denda'
                  AND p.status = 'Diverifikasi'
             ), 0),
             d.status = CASE
                WHEN COALESCE((SELECT SUM(p.nominal) FROM pembayaran p WHERE p.id_transaksi = d.id_transaksi AND p.jenis = 'Denda' AND p.status = 'Diverifikasi'),0) >= d.total_denda THEN 'Lunas'
                WHEN COALESCE((SELECT SUM(p.nominal) FROM pembayaran p WHERE p.id_transaksi = d.id_transaksi AND p.jenis = 'Denda' AND p.status = 'Diverifikasi'),0) > 0 THEN 'Sebagian'
                ELSE 'Belum Dibayar'
             END"
    );

    // Ringkasan uang_bayar hanya cache dari pembayaran yang sudah diverifikasi.
    $dpPercent = max(1.0, min(100.0, (float) ($settings['persentase_dp'] ?? 30)));
    $db->query(
        "UPDATE transaksi t
         LEFT JOIN (
            SELECT id_transaksi, SUM(nominal) AS total_verified
            FROM pembayaran WHERE status = 'Diverifikasi' AND jenis <> 'Denda' GROUP BY id_transaksi
         ) p ON p.id_transaksi = t.id_transaksi
         LEFT JOIN (
            SELECT id_transaksi,
                   SUM(status = 'Menunggu Verifikasi' AND jenis <> 'Denda') AS pending_count,
                   SUM(status = 'Ditolak' AND jenis <> 'Denda') AS rejected_count
            FROM pembayaran GROUP BY id_transaksi
         ) ps ON ps.id_transaksi = t.id_transaksi
         SET t.uang_bayar = COALESCE(p.total_verified, 0),
             t.status_pembayaran = CASE
               WHEN COALESCE(p.total_verified,0) >= t.total_harga THEN 'Lunas'
               WHEN COALESCE(ps.pending_count,0) > 0 THEN 'Menunggu Verifikasi'
               WHEN COALESCE(p.total_verified,0) >= t.total_harga * " . $dpPercent . " / 100 THEN 'DP Dibayar'
               WHEN COALESCE(p.total_verified,0) > 0 THEN 'Belum Lunas'
               WHEN COALESCE(ps.rejected_count,0) > 0 THEN 'Gagal'
               ELSE 'Belum Dibayar'
             END,
             t.kembalian = 0"
    );

    // Notifikasi memakai kombinasi transaksi, jenis, tanggal, dan peran unik.
    $notification = $db->prepare(
        "INSERT IGNORE INTO notifikasi (id_transaksi, jenis, tanggal_kejadian, untuk_role, judul, pesan)
         SELECT t.id_transaksi, 'Pengembalian Mendekat', DATE(t.tanggal_kembali), roles.role,
                'Pengembalian mendekat', CONCAT('Transaksi ', t.kode_transaksi, ' mendekati waktu pengembalian.')
         FROM transaksi t
         CROSS JOIN (SELECT 'admin' AS role UNION ALL SELECT 'petugas') roles
         WHERE t.status_transaksi IN ('Dipesan','Berjalan')
           AND TIMESTAMP(t.tanggal_kembali,t.waktu_kembali) > NOW()
           AND TIMESTAMP(t.tanggal_kembali,t.waktu_kembali) <= DATE_ADD(NOW(), INTERVAL ? HOUR)"
    );
    $notification->bind_param('i', $reminderHours);
    $notification->execute();
    $notification->close();

    $db->query(
        "INSERT IGNORE INTO notifikasi (id_transaksi, jenis, tanggal_kejadian, untuk_role, judul, pesan)
         SELECT t.id_transaksi, 'Terlambat', CURDATE(), roles.role, 'Kendaraan terlambat',
                CONCAT('Transaksi ', t.kode_transaksi, ' melewati batas pengembalian.')
         FROM transaksi t
         CROSS JOIN (SELECT 'admin' AS role UNION ALL SELECT 'petugas') roles
         WHERE t.status_transaksi = 'Terlambat'"
    );
    $db->query(
        "INSERT IGNORE INTO notifikasi (id_transaksi, jenis, tanggal_kejadian, untuk_role, judul, pesan)
         SELECT t.id_transaksi, 'Denda Bertambah', CURDATE(), roles.role, 'Denda diperbarui',
                CONCAT('Denda transaksi ', t.kode_transaksi, ' sudah dihitung ulang.')
         FROM transaksi t JOIN denda d ON d.id_transaksi = t.id_transaksi
         CROSS JOIN (SELECT 'admin' AS role UNION ALL SELECT 'petugas') roles
         WHERE t.status_transaksi = 'Terlambat'"
    );

    // Status kendaraan adalah ringkasan kondisi saat ini; validasi jadwal tetap dilakukan terhadap tabel transaksi.
    $db->query(
        "UPDATE kendaraan k
         SET k.status = CASE
            WHEN k.status = 'Perawatan' THEN 'Perawatan'
            WHEN EXISTS (SELECT 1 FROM transaksi t WHERE t.id_kendaraan=k.id_kendaraan AND t.status_transaksi IN ('Dipesan','Berjalan','Terlambat')) THEN 'Disewa'
            ELSE 'Tersedia'
         END"
    );
}

function rc_recalculate_transaction(mysqli $db, int $transactionId): void
{
    $settings = $db->query('SELECT persentase_dp FROM pengaturan_rentcar WHERE id_pengaturan=1')->fetch_assoc() ?: [];
    $dpPercent = max(1.0, min(100.0, (float) ($settings['persentase_dp'] ?? 30)));
    $stmt = $db->prepare(
        "UPDATE transaksi t
         LEFT JOIN (SELECT id_transaksi,SUM(nominal) AS paid FROM pembayaran WHERE status='Diverifikasi' AND jenis <> 'Denda' GROUP BY id_transaksi) p ON p.id_transaksi=t.id_transaksi
         LEFT JOIN (SELECT id_transaksi,SUM(status='Menunggu Verifikasi' AND jenis <> 'Denda') AS pending,SUM(status='Ditolak' AND jenis <> 'Denda') AS rejected FROM pembayaran GROUP BY id_transaksi) x ON x.id_transaksi=t.id_transaksi
         SET t.uang_bayar=COALESCE(p.paid,0),
             t.status_pembayaran=CASE
               WHEN COALESCE(p.paid,0)>=t.total_harga THEN 'Lunas'
               WHEN COALESCE(x.pending,0)>0 THEN 'Menunggu Verifikasi'
               WHEN COALESCE(p.paid,0)>=t.total_harga*? / 100 THEN 'DP Dibayar'
               WHEN COALESCE(p.paid,0)>0 THEN 'Belum Lunas'
               WHEN COALESCE(x.rejected,0)>0 THEN 'Gagal'
               ELSE 'Belum Dibayar' END
         WHERE t.id_transaksi=?"
    );
    $stmt->bind_param('di', $dpPercent, $transactionId);
    $stmt->execute();
    $stmt->close();
}
