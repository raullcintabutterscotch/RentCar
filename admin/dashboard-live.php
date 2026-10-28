<?php

session_start();

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/rentcar_automation.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

if (!isset($_SESSION['login']) || $_SESSION['login'] !== true) {
    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Sesi login berakhir.',
    ]);

    exit;
}

if (!in_array($_SESSION['level'] ?? '', ['admin', 'petugas'], true)) {
    http_response_code(403);

    echo json_encode([
        'success' => false,
        'message' => 'Akses ditolak.',
    ]);

    exit;
}

mysqli_set_charset($koneksi, 'utf8mb4');

try {
    rc_refresh_automation($koneksi);
} catch (Throwable $automationError) {
    error_log('[RentCar dashboard-live refresh] ' . $automationError->getMessage());
}

function dashboard_live_photo($photo): ?string
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

    $photo = str_replace('\\', '/', ltrim($photo, '/'));

    if (
        preg_match('#(^|/)\.\.(/|$)#', $photo)
        || strpos($photo, "\0") !== false
    ) {
        return null;
    }

    return '../' . implode('/', array_map('rawurlencode', explode('/', $photo)));
}

function dashboard_live_date($date): string
{
    $timestamp = strtotime((string) $date);

    if ($timestamp === false) {
        return '-';
    }

    $months = [
        1 => 'Jan',
        2 => 'Feb',
        3 => 'Mar',
        4 => 'Apr',
        5 => 'Mei',
        6 => 'Jun',
        7 => 'Jul',
        8 => 'Agu',
        9 => 'Sep',
        10 => 'Okt',
        11 => 'Nov',
        12 => 'Des',
    ];

    return date('d', $timestamp)
        . ' ' . $months[(int) date('n', $timestamp)]
        . ' ' . date('Y', $timestamp);
}

try {
    $statsResult = $koneksi->query(
        "SELECT
            (SELECT COUNT(*) FROM kendaraan) AS total_kendaraan,
            (SELECT COUNT(*) FROM kendaraan WHERE status = 'Tersedia') AS tersedia,
            (SELECT COUNT(*) FROM transaksi) AS total_transaksi,
            (SELECT COUNT(*) FROM transaksi WHERE status_transaksi IN ('Dipesan','Berjalan','Terlambat')) AS berjalan,
            (SELECT COUNT(*) FROM transaksi WHERE status_transaksi = 'Dipesan') AS dipesan,
            (SELECT COUNT(*) FROM transaksi WHERE status_transaksi = 'Terlambat') AS terlambat,
            (SELECT COUNT(*) FROM pembayaran WHERE status = 'Menunggu Verifikasi') AS pembayaran_pending,
            (SELECT COUNT(*) FROM transaksi WHERE status_pembayaran IN ('Belum Dibayar','Belum Lunas','DP Dibayar')) AS sisa_tagihan,
            (SELECT COUNT(*) FROM pelanggan) AS total_pelanggan,
            (SELECT COALESCE(SUM(nominal), 0) FROM pembayaran WHERE status = 'Diverifikasi') AS total_lunas"
    );

    $stats = $statsResult ? $statsResult->fetch_assoc() : [];

    $latestResult = $koneksi->query(
        "SELECT
            t.id_transaksi,
            t.kode_transaksi,
            t.created_at,
            t.status_transaksi,
            t.status_pembayaran,
            p.nama AS nama_pelanggan,
            k.nama_kendaraan,
            k.foto AS foto_mobil
         FROM transaksi t
         LEFT JOIN pelanggan p ON p.id_pelanggan = t.id_pelanggan
         LEFT JOIN kendaraan k ON k.id_kendaraan = t.id_kendaraan
         ORDER BY t.created_at DESC, t.id_transaksi DESC
         LIMIT 6"
    );

    $latest = [];

    if ($latestResult) {
        while ($row = $latestResult->fetch_assoc()) {
            $latest[] = [
                'id' => (int) $row['id_transaksi'],
                'code' => (string) $row['kode_transaksi'],
                'customer' => (string) ($row['nama_pelanggan'] ?: 'Pelanggan'),
                'vehicle' => (string) ($row['nama_kendaraan'] ?: 'Kendaraan'),
                'date' => dashboard_live_date($row['created_at']),
                'paymentStatus' => (string) $row['status_pembayaran'],
                'transactionStatus' => (string) $row['status_transaksi'],
                'photo' => dashboard_live_photo($row['foto_mobil']),
            ];
        }
    }

    $availableResult = $koneksi->query(
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
            foto
         FROM kendaraan
         WHERE status = 'Tersedia'
         ORDER BY id_kendaraan DESC
         LIMIT 8"
    );

    $available = [];

    if ($availableResult) {
        while ($row = $availableResult->fetch_assoc()) {
            $available[] = [
                'id' => (int) $row['id_kendaraan'],
                'code' => (string) $row['kode_kendaraan'],
                'name' => (string) $row['nama_kendaraan'],
                'type' => (string) ($row['tipe'] ?: $row['jenis']),
                'brand' => (string) $row['merk'],
                'kind' => (string) $row['jenis'],
                'year' => (int) $row['tahun'],
                'plate' => (string) $row['nomor_polisi'],
                'price' => (float) $row['harga_sewa'],
                'photo' => dashboard_live_photo($row['foto']),
            ];
        }
    }

    echo json_encode(
        [
            'success' => true,
            'stats' => [
                'vehicles' => (int) ($stats['total_kendaraan'] ?? 0),
                'available' => (int) ($stats['tersedia'] ?? 0),
                'transactions' => (int) ($stats['total_transaksi'] ?? 0),
                'running' => (int) ($stats['berjalan'] ?? 0),
                'reserved' => (int) ($stats['dipesan'] ?? 0),
                'overdue' => (int) ($stats['terlambat'] ?? 0),
                'pendingPayments' => (int) ($stats['pembayaran_pending'] ?? 0),
                'outstandingTransactions' => (int) ($stats['sisa_tagihan'] ?? 0),
                'customers' => (int) ($stats['total_pelanggan'] ?? 0),
                'paidTotal' => (float) ($stats['total_lunas'] ?? 0),
            ],
            'latest' => $latest,
            'availableVehicles' => $available,
            'updatedAt' => date(DATE_ATOM),
        ],
        JSON_UNESCAPED_UNICODE
            | JSON_HEX_TAG
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
            | JSON_HEX_AMP
    );
} catch (Throwable $exception) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Data dashboard gagal diperbarui.',
    ]);
}
