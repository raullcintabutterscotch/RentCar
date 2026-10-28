<?php
/* Satu sumber konfigurasi database untuk seluruh aplikasi RentCar. */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Makassar');

$host = 'localhost';
$user = 'root';
$pass = '';
$db   = 'db_rental_kendaraan';

try {
    $koneksi = new mysqli($host, $user, $pass, $db);
    $koneksi->set_charset('utf8mb4');
    $koneksi->query("SET time_zone = '+08:00'");
} catch (Throwable $exception) {
    error_log('[RentCar DB] ' . $exception->getMessage());
    http_response_code(500);
    exit('Koneksi database RentCar gagal. Pastikan MariaDB aktif dan database db_rental_kendaraan sudah diimpor.');
}
