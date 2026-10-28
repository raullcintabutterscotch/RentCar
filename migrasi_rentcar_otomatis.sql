-- Jalankan pada database db_rental_kendaraan setelah membuat backup.
-- Migrasi mempertahankan tabel dan data transaksi yang sudah ada.
ALTER TABLE kendaraan
  MODIFY status ENUM('Tersedia','Dipesan','Disewa','Perawatan') NOT NULL DEFAULT 'Tersedia';

ALTER TABLE transaksi
  MODIFY status_pembayaran ENUM('Belum Lunas','DP Dibayar','Lunas','Gagal','Pengembalian Dana') NOT NULL DEFAULT 'Belum Lunas',
  MODIFY status_transaksi ENUM('Dipesan','Berjalan','Selesai','Dibatalkan') NOT NULL DEFAULT 'Berjalan',
  ADD COLUMN metode_pembayaran VARCHAR(30) NULL AFTER uang_bayar,
  ADD COLUMN created_by_user INT NULL AFTER created_at;

CREATE TABLE IF NOT EXISTS pembayaran (
  id_pembayaran INT NOT NULL AUTO_INCREMENT,
  id_transaksi INT NOT NULL,
  nominal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  metode VARCHAR(30) NOT NULL DEFAULT 'Tunai',
  jenis ENUM('DP','Pelunasan','Pembayaran Penuh','Denda') NOT NULL DEFAULT 'Pembayaran Penuh',
  status ENUM('Berhasil','Menunggu Verifikasi','Gagal') NOT NULL DEFAULT 'Berhasil',
  catatan VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_pembayaran),
  KEY idx_pembayaran_transaksi (id_transaksi),
  CONSTRAINT fk_pembayaran_transaksi FOREIGN KEY (id_transaksi) REFERENCES transaksi(id_transaksi) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE pengembalian
  ADD COLUMN jumlah_denda_dibayar DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN dikonfirmasi_oleh INT NULL,
  ADD COLUMN dikonfirmasi_pada DATETIME NULL;

CREATE TABLE IF NOT EXISTS pengaturan_rentcar (
  id_pengaturan TINYINT NOT NULL PRIMARY KEY,
  persentase_dp DECIMAL(5,2) NOT NULL DEFAULT 30.00,
  denda_per_hari DECIMAL(12,2) NOT NULL DEFAULT 100000.00,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO pengaturan_rentcar (id_pengaturan, persentase_dp, denda_per_hari)
VALUES (1, 30.00, 100000.00)
ON DUPLICATE KEY UPDATE id_pengaturan = VALUES(id_pengaturan);

-- Isi metode pembayaran lama yang belum tercatat secara terpisah.
INSERT INTO pembayaran (id_transaksi, nominal, metode, jenis, status, catatan, created_at)
SELECT id_transaksi, uang_bayar, COALESCE(NULLIF(metode_pembayaran, ''), 'Tunai'),
       CASE WHEN uang_bayar >= total_harga THEN 'Pembayaran Penuh' ELSE 'DP' END,
       'Berhasil', 'Migrasi dari kolom uang_bayar lama', created_at
FROM transaksi
WHERE uang_bayar > 0
  AND NOT EXISTS (SELECT 1 FROM pembayaran p WHERE p.id_transaksi = transaksi.id_transaksi);
