<?php
session_start();
require_once __DIR__ . '/config/koneksi.php';

if (empty($_SESSION['login']) || $_SESSION['login'] !== true) {
    http_response_code(401);
    exit('Silakan masuk untuk melihat bukti pembayaran.');
}

$paymentId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$paymentId || $paymentId < 1) {
    http_response_code(404);
    exit('Bukti pembayaran tidak ditemukan.');
}

$stmt = $koneksi->prepare(
    'SELECT p.bukti_pembayaran,t.id_pelanggan,u.id_user AS pemilik_user
     FROM pembayaran p
     JOIN transaksi t ON t.id_transaksi=p.id_transaksi
     LEFT JOIN users u ON u.id_pelanggan=t.id_pelanggan
     WHERE p.id_pembayaran=? LIMIT 1'
);
$stmt->bind_param('i', $paymentId);
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$payment || empty($payment['bukti_pembayaran'])) {
    http_response_code(404);
    exit('Bukti pembayaran tidak tersedia.');
}

$isStaff = in_array($_SESSION['level'] ?? '', ['admin','petugas'], true);
$isOwner = (int) ($payment['pemilik_user'] ?? 0) === (int) ($_SESSION['id_user'] ?? 0);
if (!$isStaff && !$isOwner) {
    http_response_code(403);
    exit('Anda tidak memiliki akses ke bukti pembayaran ini.');
}

$relative = str_replace('\\', '/', (string) $payment['bukti_pembayaran']);
if (!preg_match('#^uploads/pembayaran/[A-Za-z0-9_-]+\.(jpg|jpeg|png|webp|pdf)$#i', $relative)) {
    http_response_code(404);
    exit('Path bukti pembayaran tidak valid.');
}
$root = realpath(__DIR__);
$uploadRoot = realpath(__DIR__ . '/uploads/pembayaran');
$path = $root ? realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative)) : false;
if (!$root || !$uploadRoot || !$path || !is_file($path) || strpos($path, rtrim($uploadRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(404);
    exit('Bukti pembayaran tidak ditemukan di server.');
}
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = $finfo ? finfo_file($finfo, $path) : 'application/octet-stream';
if ($finfo) finfo_close($finfo);
$allowed = ['image/jpeg','image/png','image/webp','application/pdf'];
if (!in_array($mime, $allowed, true)) {
    http_response_code(415);
    exit('Format berkas tidak didukung.');
}
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="bukti-pembayaran-' . (int)$paymentId . '.' . strtolower(pathinfo($path, PATHINFO_EXTENSION)) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
readfile($path);
exit;
