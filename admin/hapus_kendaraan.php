<?php

session_start();
require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/kendaraan_helper.php';

mysqli_set_charset($koneksi, 'utf8mb4');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: kendaraan.php');
    exit;
}

if (!kh_csrf_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Permintaan tidak valid. Muat ulang halaman, lalu coba kembali.');
}

$id = filter_var($_POST['id_kendaraan'] ?? null, FILTER_VALIDATE_INT);
$id = (int) ($id ?: 0);

if ($id < 1) {
    kh_flash_set('error', 'ID kendaraan tidak valid.');
    header('Location: kendaraan.php');
    exit;
}

try {
    $findStmt = $koneksi->prepare(
        'SELECT id_kendaraan, nama_kendaraan, foto, status
         FROM kendaraan
         WHERE id_kendaraan = ?
         LIMIT 1'
    );
    $findStmt->bind_param('i', $id);
    $findStmt->execute();
    $vehicle = $findStmt->get_result()->fetch_assoc();
    $findStmt->close();

    if (!$vehicle) {
        kh_flash_set('error', 'Kendaraan tidak ditemukan atau sudah dihapus.');
        header('Location: kendaraan.php');
        exit;
    }

    if (($vehicle['status'] ?? '') === 'Disewa') {
        kh_flash_set('error', 'Kendaraan yang sedang disewa tidak boleh dihapus.');
        header('Location: kendaraan.php');
        exit;
    }

    $transactionStmt = $koneksi->prepare(
        'SELECT COUNT(*) AS jumlah
         FROM transaksi
         WHERE id_kendaraan = ?'
    );
    $transactionStmt->bind_param('i', $id);
    $transactionStmt->execute();
    $transactionData = $transactionStmt->get_result()->fetch_assoc();
    $transactionStmt->close();

    if ((int) ($transactionData['jumlah'] ?? 0) > 0) {
        kh_flash_set(
            'error',
            'Kendaraan memiliki riwayat transaksi. Data tidak dihapus agar riwayat sewa tetap aman.'
        );
        header('Location: kendaraan.php');
        exit;
    }

    $deleteStmt = $koneksi->prepare(
        'DELETE FROM kendaraan WHERE id_kendaraan = ? LIMIT 1'
    );
    $deleteStmt->bind_param('i', $id);
    $deleteStmt->execute();
    $deleted = $deleteStmt->affected_rows > 0;
    $deleteStmt->close();

    if (!$deleted) {
        kh_flash_set('error', 'Kendaraan gagal dihapus.');
        header('Location: kendaraan.php');
        exit;
    }

    kh_remove_photo($vehicle['foto'] ?? null);
    kh_flash_set('success', 'Kendaraan berhasil dihapus.');
} catch (Throwable $exception) {
    kh_flash_set(
        'error',
        'Kendaraan gagal dihapus. Periksa apakah data masih digunakan oleh transaksi lain.'
    );
}

header('Location: kendaraan.php');
exit;
