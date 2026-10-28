
<?php

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Makassar');

$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASSWORD') ?: '';
$db   = getenv('DB_NAME') ?: 'db_rental_kendaraan';
$port = (int) (getenv('DB_PORT') ?: 3306);

try {
    $koneksi = new mysqli(
        $host,
        $user,
        $pass,
        $db,
        $port
    );

    $koneksi->set_charset('utf8mb4');
    $koneksi->query("SET time_zone = '+08:00'");

} catch (Throwable $exception) {
    error_log('[RentCar DB] ' . $exception->getMessage());
    http_response_code(500);
    exit('Koneksi database RentCar gagal.');
}
