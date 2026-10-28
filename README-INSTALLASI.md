# RentCar - instalasi lokal

## Kebutuhan

- XAMPP dengan Apache, PHP 8.1+ dan MariaDB/MySQL.
- Ekstensi PHP `mysqli`, `fileinfo` dan `mbstring` (aplikasi tetap punya fallback untuk beberapa operasi teks bila `mbstring` tidak tersedia).
- Browser modern.

## Instalasi database baru

**Penting:** file SQL menjatuhkan database `db_rental_kendaraan` jika database dengan nama tersebut sudah ada. Proses ini menghapus isi database sebelumnya. Ekspor/backup database lama sebelum melanjutkan.

1. Buka phpMyAdmin.
2. Pilih menu Import dan pilih `database/db_rental_kendaraan.sql`.
3. Jalankan impor sampai selesai. File tersebut membuat database `db_rental_kendaraan`, tabel, indeks, foreign key, konfigurasi awal, dua kendaraan awal, dan akun uji.
4. Buka `config/koneksi.php`. Konfigurasi bawaan memakai host `localhost`, user `root`, password kosong, dan database `db_rental_kendaraan`. Ubah host/user/password di satu file ini hanya jika konfigurasi lokal berbeda.
5. Letakkan folder proyek di `C:\xampp\htdocs\rentcar`.
6. Buka `http://localhost/rentcar/`.

## Akun uji

- Admin: `admin` / `AdminRentCar2026!`
- Petugas: `petugas` / `PetugasRentCar2026!`
- Pelanggan: `pelanggan` / `PelangganRentCar2026!`

Ganti kata sandi akun awal sebelum aplikasi digunakan di lingkungan yang dapat diakses orang lain. Akun pelanggan demo terhubung ke satu profil pelanggan contoh.

## Folder unggahan

Pastikan Apache memiliki izin tulis pada `uploads/kendaraan` dan `uploads/pembayaran`. File `.htaccess` di kedua folder membatasi eksekusi PHP. Bukti pembayaran diakses melalui `bukti_pembayaran.php`, yang memeriksa pemilik transaksi dan role staf.

## Alur penyewaan dan pembayaran

1. Pelanggan memilih kendaraan dan tanggal/waktu mulai serta kembali.
2. Server memeriksa durasi, tarif dari database, benturan waktu dan status perawatan. Perhitungan total dilakukan di server.
3. Sistem membuat satu transaksi dan pembayaran awal dalam transaksi database yang sama. Transfer Bank dan QRIS meminta bukti pembayaran; Tunai di Kantor tidak meminta unggahan.
4. Transfer/QRIS dan uang tunai yang belum diperiksa berstatus `Menunggu Verifikasi`. Status ini tidak dihitung sebagai uang diterima dan tidak dianggap Lunas.
5. Admin/petugas memeriksa lalu memverifikasi atau menolak pembayaran melalui `admin/transaksi.php`. Sesudah verifikasi, total diterima dan status pembayaran dihitung ulang dari riwayat pembayaran.
6. Pembayaran tambahan dan pembayaran denda dilakukan dari tautan bayar di profil pelanggan. Semua pembayaran disimpan sebagai baris tersendiri.
7. Pengembalian tetap memerlukan pemeriksaan admin/petugas. Denda dihitung berdasarkan waktu aktual dan tarif konfigurasi database. Kendaraan tidak dibebaskan sebelum pengembalian dikonfirmasi.

Tidak ada gateway pembayaran bank/QRIS yang dikonfigurasi dalam proyek ini. Bukti transfer/QRIS perlu diperiksa sebelum ditandai diterima. Fitur ini bukan konfirmasi dana otomatis dari bank.

## Penjadwalan otomatis denda dan pengingat

Aplikasi menghitung ulang status dan denda saat halaman terkait dibuka. Agar denda serta notifikasi juga diperbarui ketika tidak ada halaman yang dibuka, jalankan `cron/refresh_rentcar.php` setiap 5 menit melalui PHP CLI.

Contoh Task Scheduler Windows:

- Program: `C:\xampp\php\php.exe`
- Arguments: `"C:\xampp\htdocs\rentcar\cron\refresh_rentcar.php"`
- Trigger: berulang setiap 5 menit.

Contoh cron Linux:

```cron
*/5 * * * * /usr/bin/php /var/www/html/rentcar/cron/refresh_rentcar.php >> /var/log/rentcar-cron.log 2>&1
```

Sesuaikan jalur PHP dan folder proyek di komputer/server sendiri. Skrip cron hanya dapat berjalan melalui PHP CLI dan menolak permintaan web.

## Pengujian dasar

1. Login sebagai pelanggan, pesan kendaraan pada periode valid, lalu periksa `transaksi`, `pembayaran`, `kendaraan`, dan dashboard.
2. Coba jadwal tumpang tindih untuk kendaraan dan pastikan ditolak.
3. Ajukan DP/parsial/penuh. Pastikan pembayaran transfer/QRIS masuk sebagai `Menunggu Verifikasi` sampai staf memeriksanya.
4. Login admin/petugas, verifikasi pembayaran, lalu periksa ringkasan transaksi dan riwayat pelanggan.
5. Jalankan cron dan periksa status transaksi, denda dan notifikasi. Jangan mengubah waktu komputer produksi hanya untuk memicu keterlambatan.
6. Konfirmasikan pengembalian melalui halaman pengembalian dan pastikan status kendaraan diperbarui sesuai kondisi.
7. Login sebagai petugas dan coba URL `admin/laporan.php`; server harus menolak akses.

## Catatan pengujian

Sintaks PHP dan JavaScript dapat diperiksa secara lokal. Pengujian database sesungguhnya harus dijalankan pada MariaDB/XAMPP yang sudah mengimpor SQL ini. Jangan menganggap pembayaran sudah diterima hanya karena pelanggan mengirim nominal atau bukti.
