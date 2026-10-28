<?php
/**
 * Penjadwal otomatis status sewa, denda keterlambatan, dan pengingat.
 * Jalankan melalui PHP CLI setiap 5 menit. Jangan panggil dari web publik.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Akses ditolak. Skrip ini hanya untuk PHP CLI.\n");
}

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/rentcar_automation.php';

try {
    rc_refresh_automation($koneksi);
    echo '[' . date('Y-m-d H:i:s') . '] RentCar automation selesai.\n';
} catch (Throwable $exception) {
    error_log('[RentCar scheduled automation] ' . $exception->getMessage());
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] Pembaruan otomatis gagal. Periksa log PHP.\n');
    exit(1);
} finally {
    if (isset($koneksi) && $koneksi instanceof mysqli) {
        $koneksi->close();
    }
}
