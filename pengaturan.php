<?php
session_start();
require_once __DIR__ . '/config/koneksi.php';
if (empty($_SESSION['login']) || $_SESSION['login'] !== true) { header('Location: login.php'); exit; }
$userId = (int) ($_SESSION['id_user'] ?? 0);
if (empty($_SESSION['csrf_profile'])) $_SESSION['csrf_profile'] = bin2hex(random_bytes(32));
$message = ''; $error = '';
$stmt = $koneksi->prepare('SELECT u.nama, u.id_pelanggan FROM users u WHERE u.id_user = ? LIMIT 1'); $stmt->bind_param('i', $userId); $stmt->execute(); $user = $stmt->get_result()->fetch_assoc(); $stmt->close();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_profile'], (string)($_POST['csrf_token'] ?? ''))) $error = 'Sesi formulir tidak valid. Muat ulang halaman.';
    else {
        $nama = trim((string)($_POST['nama'] ?? '')); $noHp = trim((string)($_POST['no_hp'] ?? '')); $alamat = trim((string)($_POST['alamat'] ?? ''));
        if ($nama === '' || mb_strlen($nama) > 100 || mb_strlen($noHp) > 20 || mb_strlen($alamat) > 1000) $error = 'Periksa nama, nomor telepon, dan alamat.';
        else {
            try { $koneksi->begin_transaction(); $stmt = $koneksi->prepare('UPDATE users SET nama = ? WHERE id_user = ?'); $stmt->bind_param('si', $nama, $userId); $stmt->execute(); $stmt->close();
                if (!empty($user['id_pelanggan'])) { $cid=(int)$user['id_pelanggan']; $stmt=$koneksi->prepare('UPDATE pelanggan SET nama = ?, no_hp = ?, alamat = ? WHERE id_pelanggan = ?'); $stmt->bind_param('sssi',$nama,$noHp,$alamat,$cid); $stmt->execute(); $stmt->close(); }
                $koneksi->commit(); $_SESSION['nama']=$nama; $message='Profil berhasil diperbarui.'; $user['nama']=$nama;
            } catch (Throwable $e) { $koneksi->rollback(); $error='Profil gagal disimpan. Periksa koneksi database.'; }
        }
    }
}
$phone=''; $address=''; if (!empty($user['id_pelanggan'])) { $cid=(int)$user['id_pelanggan']; $stmt=$koneksi->prepare('SELECT no_hp, alamat FROM pelanggan WHERE id_pelanggan = ?'); $stmt->bind_param('i',$cid); $stmt->execute(); $detail=$stmt->get_result()->fetch_assoc() ?: []; $stmt->close(); $phone=$detail['no_hp']??''; $address=$detail['alamat']??''; }
function sh($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?><!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Pengaturan Profil | RentCar</title><link rel="stylesheet" href="assets/css/pengaturan-pengguna.css"></head><body><main class="settings-shell"><a href="profil.php">← Kembali ke profil</a><h1>Pengaturan Profil</h1><p>Perbarui informasi akun Anda.</p><?php if($message): ?><div class="notice success"><?=sh($message)?></div><?php endif; ?><?php if($error): ?><div class="notice error"><?=sh($error)?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf_token" value="<?=sh($_SESSION['csrf_profile'])?>"><label>Nama lengkap<input name="nama" maxlength="100" required value="<?=sh($_POST['nama']??$user['nama']??'')?>"></label><label>Nomor telepon<input name="no_hp" maxlength="20" value="<?=sh($_POST['no_hp']??$phone)?>"></label><label>Alamat<textarea name="alamat" maxlength="1000" rows="4"><?=sh($_POST['alamat']??$address)?></textarea></label><button type="submit">Simpan perubahan</button></form></main></body></html>
