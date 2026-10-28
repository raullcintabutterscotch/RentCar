-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 28, 2026 at 03:12 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `db_rental_kendaraan`
--

-- --------------------------------------------------------

--
-- Table structure for table `audit_log`
--

CREATE TABLE `audit_log` (
  `id_log` bigint(20) UNSIGNED NOT NULL,
  `id_user` int(10) UNSIGNED DEFAULT NULL,
  `aksi` varchar(80) NOT NULL,
  `entitas` varchar(80) NOT NULL,
  `id_entitas` bigint(20) UNSIGNED DEFAULT NULL,
  `detail` varchar(1000) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `audit_log`
--

INSERT INTO `audit_log` (`id_log`, `id_user`, `aksi`, `entitas`, `id_entitas`, `detail`, `ip_address`, `created_at`) VALUES
(1, 1, 'verifikasi_pembayaran', 'pembayaran', 1, 'Pembayaran RC-20261028-6F586240 berhasil diverifikasi.', NULL, '2026-10-28 00:51:13'),
(2, 1, 'catat_tunai', 'transaksi', 1, 'Nominal: 1.441.000', NULL, '2026-10-28 00:52:43');

-- --------------------------------------------------------

--
-- Table structure for table `denda`
--

CREATE TABLE `denda` (
  `id_denda` int(10) UNSIGNED NOT NULL,
  `id_transaksi` int(10) UNSIGNED NOT NULL,
  `hari_terlambat` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `tarif_per_hari` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_denda` decimal(12,2) NOT NULL DEFAULT 0.00,
  `nominal_dibayar` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('Belum Dibayar','Sebagian','Lunas') NOT NULL DEFAULT 'Belum Dibayar',
  `dihitung_pada` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `kendaraan`
--

CREATE TABLE `kendaraan` (
  `id_kendaraan` int(10) UNSIGNED NOT NULL,
  `kode_kendaraan` varchar(20) NOT NULL,
  `nama_kendaraan` varchar(100) NOT NULL,
  `jenis` enum('Mobil','Motor') NOT NULL,
  `merk` varchar(50) NOT NULL,
  `tipe` varchar(50) NOT NULL,
  `tahun` year(4) NOT NULL,
  `nomor_polisi` varchar(20) NOT NULL,
  `harga_sewa` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('Tersedia','Dipesan','Disewa','Perawatan') NOT NULL DEFAULT 'Tersedia',
  `foto` varchar(255) DEFAULT NULL,
  `catatan` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `kendaraan`
--

INSERT INTO `kendaraan` (`id_kendaraan`, `kode_kendaraan`, `nama_kendaraan`, `jenis`, `merk`, `tipe`, `tahun`, `nomor_polisi`, `harga_sewa`, `status`, `foto`, `catatan`, `created_at`, `updated_at`) VALUES
(1, 'MOB-001', 'Mercedes-Benz GLE AMG 63 S', 'Mobil', 'Mercedes-Benz', 'Matic', '2015', 'KT 1001 RC', 3910000.00, 'Disewa', 'uploads/kendaraan/kendaraan_747e5e9dccb899325cf74824.jpg', NULL, '2026-10-28 00:28:22', '2026-10-28 00:50:02'),
(2, 'MOB-002', 'Porsche 911 GT3 RS', 'Mobil', 'Porsche', 'Matic', '2010', 'KT 1002 RC', 17391000.00, 'Tersedia', 'uploads/kendaraan/kendaraan_460cc7062746d177c4650805.webp', NULL, '2026-10-28 00:28:22', '2026-10-28 00:28:22'),
(3, 'MOT-001', 'Yamaha XSR 155', 'Motor', 'Yamaha', 'Kopling', '2019', 'KU 1703 09', 350000.00, 'Tersedia', 'uploads/kendaraan/kendaraan_cd0cd390b8f9ba63d91326f3.jpg', NULL, '2026-10-28 02:10:24', '2026-10-28 02:10:24');

-- --------------------------------------------------------

--
-- Table structure for table `notifikasi`
--

CREATE TABLE `notifikasi` (
  `id_notifikasi` bigint(20) UNSIGNED NOT NULL,
  `id_transaksi` int(10) UNSIGNED DEFAULT NULL,
  `jenis` enum('Penyewaan Baru','Pembayaran Masuk','Pembayaran Ditolak','Pengembalian Mendekat','Terlambat','Denda Bertambah','Pengembalian Dikonfirmasi','Penyewaan Dibatalkan') NOT NULL,
  `tanggal_kejadian` date NOT NULL,
  `untuk_role` enum('admin','petugas','pelanggan') NOT NULL DEFAULT 'admin',
  `id_user_tujuan` int(10) UNSIGNED DEFAULT NULL,
  `judul` varchar(150) NOT NULL,
  `pesan` varchar(500) NOT NULL,
  `dibaca_pada` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifikasi`
--

INSERT INTO `notifikasi` (`id_notifikasi`, `id_transaksi`, `jenis`, `tanggal_kejadian`, `untuk_role`, `id_user_tujuan`, `judul`, `pesan`, `dibaca_pada`, `created_at`) VALUES
(1, 1, 'Penyewaan Baru', '2026-10-28', 'admin', NULL, 'Penyewaan baru', 'Pesanan RC-20261028-6F586240 menunggu verifikasi pembayaran.', NULL, '2026-10-28 00:50:02'),
(2, 1, 'Penyewaan Baru', '2026-10-28', 'petugas', NULL, 'Penyewaan baru', 'Pesanan RC-20261028-6F586240 menunggu verifikasi pembayaran.', NULL, '2026-10-28 00:50:02'),
(4, 1, 'Pembayaran Masuk', '2026-10-28', 'pelanggan', 3, 'Pembayaran diverifikasi', 'Pembayaran RC-20261028-6F586240 berhasil diverifikasi.', NULL, '2026-10-28 00:51:13'),
(5, 1, 'Pengembalian Mendekat', '2026-10-29', 'admin', NULL, 'Pengembalian mendekat', 'Transaksi RC-20261028-6F586240 mendekati waktu pengembalian.', NULL, '2026-10-28 01:00:14'),
(6, 1, 'Pengembalian Mendekat', '2026-10-29', 'petugas', NULL, 'Pengembalian mendekat', 'Transaksi RC-20261028-6F586240 mendekati waktu pengembalian.', NULL, '2026-10-28 01:00:14');

-- --------------------------------------------------------

--
-- Table structure for table `pelanggan`
--

CREATE TABLE `pelanggan` (
  `id_pelanggan` int(10) UNSIGNED NOT NULL,
  `nama` varchar(100) NOT NULL,
  `nik` varchar(30) NOT NULL,
  `no_hp` varchar(20) NOT NULL,
  `alamat` text NOT NULL,
  `jenis_kelamin` enum('Laki-laki','Perempuan') NOT NULL,
  `no_sim` varchar(50) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pelanggan`
--

INSERT INTO `pelanggan` (`id_pelanggan`, `nama`, `nik`, `no_hp`, `alamat`, `jenis_kelamin`, `no_sim`, `created_at`, `updated_at`) VALUES
(1, 'ucup', '3270000000000001', '081347003870', 'jl sabanar lama', 'Laki-laki', 'SIM-DEMO-001', '2026-10-28 00:28:22', '2026-10-28 00:33:54');

-- --------------------------------------------------------

--
-- Table structure for table `pembayaran`
--

CREATE TABLE `pembayaran` (
  `id_pembayaran` int(10) UNSIGNED NOT NULL,
  `id_transaksi` int(10) UNSIGNED NOT NULL,
  `nominal` decimal(12,2) NOT NULL,
  `metode_pembayaran` enum('Transfer Bank','QRIS','Tunai di Kantor') NOT NULL,
  `jenis` enum('DP','Pembayaran Sebagian','Pembayaran Penuh','Pelunasan','Denda') NOT NULL DEFAULT 'Pembayaran Sebagian',
  `nomor_referensi` varchar(100) DEFAULT NULL,
  `bukti_pembayaran` varchar(255) DEFAULT NULL,
  `status` enum('Menunggu Verifikasi','Diverifikasi','Ditolak','Gagal') NOT NULL DEFAULT 'Menunggu Verifikasi',
  `catatan_admin` varchar(255) DEFAULT NULL,
  `request_token` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `verified_at` datetime DEFAULT NULL,
  `verified_by` int(10) UNSIGNED DEFAULT NULL
) ;

--
-- Dumping data for table `pembayaran`
--

INSERT INTO `pembayaran` (`id_pembayaran`, `id_transaksi`, `nominal`, `metode_pembayaran`, `jenis`, `nomor_referensi`, `bukti_pembayaran`, `status`, `catatan_admin`, `request_token`, `created_at`, `verified_at`, `verified_by`) VALUES
(1, 1, 2469000.00, 'Tunai di Kantor', 'Pembayaran Sebagian', NULL, NULL, 'Diverifikasi', '', 'ef8800d70082373696c4def21c347474e98ef98f38a066cd700a98e940abd688', '2026-10-28 00:50:02', '2026-10-28 08:51:13', 1),
(2, 1, 1441000.00, 'Tunai di Kantor', 'Pelunasan', 'CASH-20261028085243-02c147', NULL, 'Diverifikasi', 'Pembayaran tunai diterima dan dicatat oleh Administrator', '024557999ac03a9cfa342227b4fb0eaa6f8ec4b91a8d810046951bc23d5afbc9', '2026-10-28 00:52:43', '2026-10-28 08:52:43', 1);

-- --------------------------------------------------------

--
-- Table structure for table `pengaturan_rentcar`
--

CREATE TABLE `pengaturan_rentcar` (
  `id_pengaturan` tinyint(3) UNSIGNED NOT NULL,
  `persentase_dp` decimal(5,2) NOT NULL DEFAULT 30.00,
  `denda_per_hari` decimal(12,2) NOT NULL DEFAULT 100000.00,
  `pengingat_sebelum_jam` smallint(5) UNSIGNED NOT NULL DEFAULT 24,
  `maksimal_hari_sewa` smallint(5) UNSIGNED NOT NULL DEFAULT 90,
  `updated_by` int(10) UNSIGNED DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `pengaturan_rentcar`
--

INSERT INTO `pengaturan_rentcar` (`id_pengaturan`, `persentase_dp`, `denda_per_hari`, `pengingat_sebelum_jam`, `maksimal_hari_sewa`, `updated_by`, `updated_at`) VALUES
(1, 30.00, 100000.00, 24, 90, NULL, '2026-10-28 00:28:22');

-- --------------------------------------------------------

--
-- Table structure for table `pengembalian`
--

CREATE TABLE `pengembalian` (
  `id_pengembalian` int(10) UNSIGNED NOT NULL,
  `id_transaksi` int(10) UNSIGNED NOT NULL,
  `tanggal_pengembalian` date NOT NULL,
  `waktu_pengembalian` time NOT NULL DEFAULT '09:00:00',
  `terlambat` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `denda` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_bayar` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Snapshot total kewajiban sewa dan denda ketika dikembalikan',
  `jumlah_denda_dibayar` decimal(12,2) NOT NULL DEFAULT 0.00,
  `keterangan` varchar(255) DEFAULT NULL,
  `status_kondisi` enum('Baik','Perlu Pemeriksaan','Perawatan') NOT NULL DEFAULT 'Baik',
  `dikonfirmasi_oleh` int(10) UNSIGNED DEFAULT NULL,
  `dikonfirmasi_pada` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transaksi`
--

CREATE TABLE `transaksi` (
  `id_transaksi` int(10) UNSIGNED NOT NULL,
  `kode_transaksi` varchar(30) NOT NULL,
  `id_pelanggan` int(10) UNSIGNED NOT NULL,
  `id_kendaraan` int(10) UNSIGNED NOT NULL,
  `tanggal_sewa` date NOT NULL,
  `waktu_sewa` time NOT NULL DEFAULT '09:00:00',
  `tanggal_kembali` date NOT NULL,
  `waktu_kembali` time NOT NULL DEFAULT '09:00:00',
  `lama_sewa` smallint(5) UNSIGNED NOT NULL,
  `harga_sewa` decimal(12,2) NOT NULL,
  `total_harga` decimal(12,2) NOT NULL,
  `uang_bayar` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Cache total pembayaran yang diverifikasi, diperbarui dari tabel pembayaran',
  `metode_pembayaran` varchar(30) DEFAULT NULL,
  `kembalian` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status_pembayaran` enum('Belum Dibayar','Menunggu Verifikasi','Belum Lunas','DP Dibayar','Lunas','Gagal','Pengembalian Dana') NOT NULL DEFAULT 'Belum Dibayar',
  `status_transaksi` enum('Dipesan','Berjalan','Terlambat','Selesai','Dibatalkan') NOT NULL DEFAULT 'Dipesan',
  `created_by_user` int(10) UNSIGNED DEFAULT NULL,
  `request_token` varchar(64) DEFAULT NULL,
  `catatan_pelanggan` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `transaksi`
--

INSERT INTO `transaksi` (`id_transaksi`, `kode_transaksi`, `id_pelanggan`, `id_kendaraan`, `tanggal_sewa`, `waktu_sewa`, `tanggal_kembali`, `waktu_kembali`, `lama_sewa`, `harga_sewa`, `total_harga`, `uang_bayar`, `metode_pembayaran`, `kembalian`, `status_pembayaran`, `status_transaksi`, `created_by_user`, `request_token`, `catatan_pelanggan`, `created_at`, `updated_at`) VALUES
(1, 'RC-20261028-6F586240', 1, 1, '2026-10-28', '09:00:00', '2026-10-29', '09:00:00', 1, 3910000.00, 3910000.00, 3910000.00, 'Tunai di Kantor', 0.00, 'Lunas', 'Berjalan', 3, '1d28a3e40a494b7bcb85c599aedc3eb7223529e11973f6706ccf2b156ab0111b', NULL, '2026-10-28 00:50:02', '2026-10-28 01:00:14');

-- --------------------------------------------------------

--
-- Table structure for table `ulasan_pelanggan`
--

CREATE TABLE `ulasan_pelanggan` (
  `id_ulasan` int(10) UNSIGNED NOT NULL,
  `id_pelanggan` int(10) UNSIGNED NOT NULL,
  `rating` tinyint(3) UNSIGNED NOT NULL DEFAULT 5,
  `komentar` varchar(500) NOT NULL,
  `status` enum('Menunggu','Tampil','Ditolak') NOT NULL DEFAULT 'Menunggu',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id_user` int(10) UNSIGNED NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `nama` varchar(100) NOT NULL,
  `level` enum('admin','petugas') DEFAULT NULL COMMENT 'NULL = akun pelanggan',
  `id_pelanggan` int(10) UNSIGNED DEFAULT NULL,
  `foto_profil` varchar(255) DEFAULT NULL,
  `aktif` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id_user`, `username`, `password`, `nama`, `level`, `id_pelanggan`, `foto_profil`, `aktif`, `last_login_at`, `created_at`, `updated_at`) VALUES
(1, 'admin', '$2y$10$DURyD91Pxr5dBlaFIOAo1uerNThwfhHcO06Dvw7LvA0HZHSMKQmzi', 'Administrator', 'admin', NULL, NULL, 1, '2026-10-28 08:31:00', '2026-10-28 00:28:22', '2026-10-28 00:31:19'),
(2, 'raul', 'raul123', 'Petugas RentCar', 'petugas', NULL, NULL, 1, NULL, '2026-10-28 00:28:22', '2026-10-28 00:35:07'),
(3, 'ucup', '$2y$10$mykKxTQoxAW6bDc1intFPOFriAMuaJyGpvLPFkUYjz/t/x9IHUzoK', 'ucup', NULL, 1, NULL, 1, '2026-10-28 08:32:12', '2026-10-28 00:28:22', '2026-10-28 00:34:42');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id_log`),
  ADD KEY `idx_audit_entitas` (`entitas`,`id_entitas`,`created_at`),
  ADD KEY `idx_audit_user` (`id_user`,`created_at`);

--
-- Indexes for table `denda`
--
ALTER TABLE `denda`
  ADD PRIMARY KEY (`id_denda`),
  ADD UNIQUE KEY `uq_denda_transaksi` (`id_transaksi`),
  ADD KEY `idx_denda_status` (`status`,`updated_at`);

--
-- Indexes for table `kendaraan`
--
ALTER TABLE `kendaraan`
  ADD PRIMARY KEY (`id_kendaraan`),
  ADD UNIQUE KEY `uq_kendaraan_kode` (`kode_kendaraan`),
  ADD UNIQUE KEY `uq_kendaraan_nomor_polisi` (`nomor_polisi`),
  ADD KEY `idx_kendaraan_status_jenis` (`status`,`jenis`),
  ADD KEY `idx_kendaraan_nama` (`nama_kendaraan`);

--
-- Indexes for table `notifikasi`
--
ALTER TABLE `notifikasi`
  ADD PRIMARY KEY (`id_notifikasi`),
  ADD UNIQUE KEY `uq_notifikasi_transaksi_jenis_tanggal` (`id_transaksi`,`jenis`,`tanggal_kejadian`,`untuk_role`),
  ADD KEY `idx_notifikasi_role_baca` (`untuk_role`,`dibaca_pada`,`created_at`),
  ADD KEY `fk_notifikasi_user` (`id_user_tujuan`);

--
-- Indexes for table `pelanggan`
--
ALTER TABLE `pelanggan`
  ADD PRIMARY KEY (`id_pelanggan`),
  ADD UNIQUE KEY `uq_pelanggan_nik` (`nik`),
  ADD KEY `idx_pelanggan_nama` (`nama`),
  ADD KEY `idx_pelanggan_hp` (`no_hp`);

--
-- Indexes for table `pembayaran`
--
ALTER TABLE `pembayaran`
  ADD PRIMARY KEY (`id_pembayaran`),
  ADD UNIQUE KEY `uq_pembayaran_request_token` (`request_token`),
  ADD KEY `idx_pembayaran_transaksi_status` (`id_transaksi`,`status`),
  ADD KEY `idx_pembayaran_metode` (`metode_pembayaran`,`created_at`),
  ADD KEY `fk_pembayaran_verifikator` (`verified_by`);

--
-- Indexes for table `pengaturan_rentcar`
--
ALTER TABLE `pengaturan_rentcar`
  ADD PRIMARY KEY (`id_pengaturan`),
  ADD KEY `fk_pengaturan_updated_by` (`updated_by`);

--
-- Indexes for table `pengembalian`
--
ALTER TABLE `pengembalian`
  ADD PRIMARY KEY (`id_pengembalian`),
  ADD UNIQUE KEY `uq_pengembalian_transaksi` (`id_transaksi`),
  ADD KEY `idx_pengembalian_tanggal` (`tanggal_pengembalian`),
  ADD KEY `fk_pengembalian_petugas` (`dikonfirmasi_oleh`);

--
-- Indexes for table `transaksi`
--
ALTER TABLE `transaksi`
  ADD PRIMARY KEY (`id_transaksi`),
  ADD UNIQUE KEY `uq_transaksi_kode` (`kode_transaksi`),
  ADD UNIQUE KEY `uq_transaksi_request_token` (`request_token`),
  ADD KEY `idx_transaksi_kendaraan_jadwal` (`id_kendaraan`,`status_transaksi`,`tanggal_sewa`,`tanggal_kembali`),
  ADD KEY `idx_transaksi_pelanggan_tanggal` (`id_pelanggan`,`created_at`),
  ADD KEY `idx_transaksi_status_jatuh_tempo` (`status_transaksi`,`tanggal_kembali`,`waktu_kembali`),
  ADD KEY `fk_transaksi_user` (`created_by_user`);

--
-- Indexes for table `ulasan_pelanggan`
--
ALTER TABLE `ulasan_pelanggan`
  ADD PRIMARY KEY (`id_ulasan`),
  ADD KEY `idx_ulasan_status_tanggal` (`status`,`created_at`),
  ADD KEY `idx_ulasan_pelanggan` (`id_pelanggan`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id_user`),
  ADD UNIQUE KEY `uq_users_username` (`username`),
  ADD UNIQUE KEY `uq_users_id_pelanggan` (`id_pelanggan`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `id_log` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `denda`
--
ALTER TABLE `denda`
  MODIFY `id_denda` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `kendaraan`
--
ALTER TABLE `kendaraan`
  MODIFY `id_kendaraan` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `notifikasi`
--
ALTER TABLE `notifikasi`
  MODIFY `id_notifikasi` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=171;

--
-- AUTO_INCREMENT for table `pelanggan`
--
ALTER TABLE `pelanggan`
  MODIFY `id_pelanggan` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `pembayaran`
--
ALTER TABLE `pembayaran`
  MODIFY `id_pembayaran` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pengembalian`
--
ALTER TABLE `pengembalian`
  MODIFY `id_pengembalian` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `transaksi`
--
ALTER TABLE `transaksi`
  MODIFY `id_transaksi` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ulasan_pelanggan`
--
ALTER TABLE `ulasan_pelanggan`
  MODIFY `id_ulasan` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id_user` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD CONSTRAINT `fk_audit_user` FOREIGN KEY (`id_user`) REFERENCES `users` (`id_user`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `denda`
--
ALTER TABLE `denda`
  ADD CONSTRAINT `fk_denda_transaksi` FOREIGN KEY (`id_transaksi`) REFERENCES `transaksi` (`id_transaksi`) ON UPDATE CASCADE;

--
-- Constraints for table `notifikasi`
--
ALTER TABLE `notifikasi`
  ADD CONSTRAINT `fk_notifikasi_transaksi` FOREIGN KEY (`id_transaksi`) REFERENCES `transaksi` (`id_transaksi`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_notifikasi_user` FOREIGN KEY (`id_user_tujuan`) REFERENCES `users` (`id_user`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `pembayaran`
--
ALTER TABLE `pembayaran`
  ADD CONSTRAINT `fk_pembayaran_transaksi` FOREIGN KEY (`id_transaksi`) REFERENCES `transaksi` (`id_transaksi`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_pembayaran_verifikator` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id_user`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `pengaturan_rentcar`
--
ALTER TABLE `pengaturan_rentcar`
  ADD CONSTRAINT `fk_pengaturan_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id_user`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `pengembalian`
--
ALTER TABLE `pengembalian`
  ADD CONSTRAINT `fk_pengembalian_petugas` FOREIGN KEY (`dikonfirmasi_oleh`) REFERENCES `users` (`id_user`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_pengembalian_transaksi` FOREIGN KEY (`id_transaksi`) REFERENCES `transaksi` (`id_transaksi`) ON UPDATE CASCADE;

--
-- Constraints for table `transaksi`
--
ALTER TABLE `transaksi`
  ADD CONSTRAINT `fk_transaksi_kendaraan` FOREIGN KEY (`id_kendaraan`) REFERENCES `kendaraan` (`id_kendaraan`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_transaksi_pelanggan` FOREIGN KEY (`id_pelanggan`) REFERENCES `pelanggan` (`id_pelanggan`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_transaksi_user` FOREIGN KEY (`created_by_user`) REFERENCES `users` (`id_user`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `ulasan_pelanggan`
--
ALTER TABLE `ulasan_pelanggan`
  ADD CONSTRAINT `fk_ulasan_pelanggan` FOREIGN KEY (`id_pelanggan`) REFERENCES `pelanggan` (`id_pelanggan`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_pelanggan` FOREIGN KEY (`id_pelanggan`) REFERENCES `pelanggan` (`id_pelanggan`) ON DELETE SET NULL ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
