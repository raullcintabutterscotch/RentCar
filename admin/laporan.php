<?php

session_start();

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../config/kendaraan_helper.php';

if (($_SESSION['level'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Akses laporan hanya tersedia untuk admin.');
}

require_once __DIR__ . '/../config/rentcar_automation.php';
try { rc_refresh_automation($koneksi); } catch (Throwable $e) { error_log('[RentCar report refresh] ' . $e->getMessage()); }

mysqli_set_charset($koneksi, 'utf8mb4');

$reportType = (string) ($_GET['jenis_laporan'] ?? 'transaksi');
if (!in_array($reportType, ['transaksi', 'pengembalian'], true)) {
    $reportType = 'transaksi';
}

$today = date('Y-m-d');
$firstDay = date('Y-m-01');
$startDate = trim((string) ($_GET['mulai'] ?? $firstDay));
$endDate = trim((string) ($_GET['sampai'] ?? $today));

$startParsed = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);
$endParsed = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate);
$startValid = $startParsed && $startParsed->format('Y-m-d') === $startDate;
$endValid = $endParsed && $endParsed->format('Y-m-d') === $endDate;
$pageError = '';

if (!$startValid || !$endValid) {
    $startDate = $firstDay;
    $endDate = $today;
    $pageError = 'Rentang tanggal tidak valid. Rentang tanggal bulan ini digunakan kembali.';
} elseif ($startDate > $endDate) {
    $startDate = $firstDay;
    $endDate = $today;
    $pageError = 'Tanggal mulai tidak boleh setelah tanggal akhir.';
}

$reportRows = [];
$summary = [
    'total_rows' => 0,
    'nominal_utama' => 0.0,
    'sudah_bayar' => 0.0,
    'sisa_tagihan' => 0.0,
    'terlambat' => 0,
];

try {
    if ($reportType === 'transaksi') {
        $stmt = $koneksi->prepare(
            'SELECT
                t.id_transaksi,
                t.kode_transaksi,
                t.created_at,
                t.tanggal_sewa,
                t.tanggal_kembali,
                t.lama_sewa,
                t.harga_sewa,
                t.total_harga,
                COALESCE(pembayaran_sewa.total_verified,0) AS uang_bayar,
                t.kembalian,
                t.status_pembayaran,
                COALESCE(d.total_denda,0) AS total_denda,
                COALESCE(pembayaran_denda.total_verified,0) AS denda_dibayar,
                t.status_transaksi,
                p.nama AS nama_pelanggan,
                p.no_hp,
                k.kode_kendaraan,
                k.nama_kendaraan
             FROM transaksi t
             LEFT JOIN pelanggan p ON p.id_pelanggan = t.id_pelanggan
             LEFT JOIN kendaraan k ON k.id_kendaraan = t.id_kendaraan
             LEFT JOIN denda d ON d.id_transaksi = t.id_transaksi
             LEFT JOIN (
                SELECT id_transaksi, SUM(nominal) AS total_verified
                FROM pembayaran
                WHERE status = \'Diverifikasi\' AND jenis <> \'Denda\'
                GROUP BY id_transaksi
             ) pembayaran_sewa ON pembayaran_sewa.id_transaksi = t.id_transaksi
             LEFT JOIN (
                SELECT id_transaksi, SUM(nominal) AS total_verified
                FROM pembayaran
                WHERE status = \'Diverifikasi\' AND jenis = \'Denda\'
                GROUP BY id_transaksi
             ) pembayaran_denda ON pembayaran_denda.id_transaksi = t.id_transaksi
             WHERE DATE(t.created_at) BETWEEN ? AND ?
             ORDER BY t.created_at DESC, t.id_transaksi DESC'
        );
    } else {
        $stmt = $koneksi->prepare(
            'SELECT
                r.id_pengembalian,
                r.tanggal_pengembalian,
                r.terlambat,
                r.denda,
                r.total_bayar,
                r.keterangan,
                t.kode_transaksi,
                t.tanggal_sewa,
                t.tanggal_kembali,
                p.nama AS nama_pelanggan,
                k.kode_kendaraan,
                k.nama_kendaraan
             FROM pengembalian r
             INNER JOIN transaksi t ON t.id_transaksi = r.id_transaksi
             LEFT JOIN pelanggan p ON p.id_pelanggan = t.id_pelanggan
             LEFT JOIN kendaraan k ON k.id_kendaraan = t.id_kendaraan
             WHERE r.tanggal_pengembalian BETWEEN ? AND ?
             ORDER BY r.tanggal_pengembalian DESC, r.id_pengembalian DESC'
        );
    }

    $stmt->bind_param('ss', $startDate, $endDate);
    $stmt->execute();
    $reportRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $summary['total_rows'] = count($reportRows);

    foreach ($reportRows as $row) {
        if ($reportType === 'transaksi') {
            $summary['nominal_utama'] += (float) $row['total_harga'];
            $summary['sudah_bayar'] += (float) $row['uang_bayar'];
            $summary['sisa_tagihan'] += max(0, (float) $row['total_harga'] - (float) $row['uang_bayar'])
                + max(0, (float) ($row['total_denda'] ?? 0) - (float) ($row['denda_dibayar'] ?? 0));
        } else {
            $summary['nominal_utama'] += (float) $row['denda'];
            $summary['sudah_bayar'] += (float) $row['total_bayar'];
            $summary['terlambat'] += (int) $row['terlambat'];
        }
    }
} catch (Throwable $exception) {
    $pageError = $pageError !== ''
        ? $pageError . ' Data laporan gagal dimuat.'
        : 'Data laporan gagal dimuat. Periksa struktur tabel transaksi dan pengembalian.';
}

/* EXPORT EXCEL XML. SpreadsheetML mendukung warna, judul, ikon, ringkasan, serta format tabel di Microsoft Excel. */
if (($_GET['export'] ?? '') === 'excel') {
    $headers = [];
    $rowsForExcel = [];
    $sheetName = $reportType === 'transaksi' ? 'Laporan Transaksi' : 'Laporan Pengembalian';
    $exportTitle = $reportType === 'transaksi'
        ? '🚗 RENTCAR | LAPORAN TRANSAKSI'
        : '🚗 RENTCAR | LAPORAN PENGEMBALIAN';
    $reportLabel = $reportType === 'transaksi' ? 'Transaksi' : 'Pengembalian';
    $subtitle = 'Periode ' . date('d/m/Y', strtotime($startDate)) . ' sampai ' . date('d/m/Y', strtotime($endDate))
        . ' | Jenis laporan: ' . $reportLabel
        . ' | Diekspor: ' . date('d/m/Y H:i');

    if ($reportType === 'transaksi') {
        $headers = [
            'ID', 'Kode Transaksi', 'Tanggal Transaksi', 'Pelanggan', 'No. HP', 'Kode Kendaraan',
            'Nama Kendaraan', 'Tanggal Sewa', 'Tanggal Kembali', 'Lama Sewa (Hari)',
            'Tarif Harian', 'Total Harga', 'Uang Dibayar', 'Kembalian',
            'Status Pembayaran', 'Status Transaksi',
        ];

        foreach ($reportRows as $row) {
            $rowsForExcel[] = [
                ['value' => (int) $row['id_transaksi'], 'type' => 'Number'],
                ['value' => $row['kode_transaksi'], 'type' => 'String'],
                ['value' => $row['created_at'], 'type' => 'String'],
                ['value' => $row['nama_pelanggan'] ?? '-', 'type' => 'String'],
                ['value' => $row['no_hp'] ?? '-', 'type' => 'String'],
                ['value' => $row['kode_kendaraan'] ?? '-', 'type' => 'String'],
                ['value' => $row['nama_kendaraan'] ?? '-', 'type' => 'String'],
                ['value' => $row['tanggal_sewa'], 'type' => 'String'],
                ['value' => $row['tanggal_kembali'], 'type' => 'String'],
                ['value' => (int) $row['lama_sewa'], 'type' => 'Number'],
                ['value' => (float) $row['harga_sewa'], 'type' => 'Number'],
                ['value' => (float) $row['total_harga'], 'type' => 'Number'],
                ['value' => (float) $row['uang_bayar'], 'type' => 'Number'],
                ['value' => (float) $row['kembalian'], 'type' => 'Number'],
                ['value' => $row['status_pembayaran'], 'type' => 'String'],
                ['value' => $row['status_transaksi'], 'type' => 'String'],
            ];
        }

        $summaryMetrics = [
            ['label' => 'JUMLAH TRANSAKSI', 'value' => (int) $summary['total_rows'], 'money' => false],
            ['label' => 'TOTAL TAGIHAN SEWA', 'value' => (float) $summary['nominal_utama'], 'money' => true],
            ['label' => 'PEMBAYARAN TERVERIFIKASI', 'value' => (float) $summary['sudah_bayar'], 'money' => true],
            ['label' => 'SISA TAGIHAN + DENDA', 'value' => (float) $summary['sisa_tagihan'], 'money' => true],
        ];
        $columnWidths = [42, 110, 125, 145, 95, 105, 155, 100, 110, 82, 95, 100, 100, 90, 125, 115];
        $moneyColumns = [10, 11, 12, 13];
        $statusColumns = [14, 15];
    } else {
        $headers = [
            'ID Pengembalian', 'Kode Transaksi', 'Pelanggan', 'Kode Kendaraan',
            'Nama Kendaraan', 'Tanggal Sewa', 'Tanggal Seharusnya Kembali',
            'Tanggal Pengembalian', 'Terlambat (Hari)', 'Denda', 'Total Dibayar', 'Keterangan',
        ];

        foreach ($reportRows as $row) {
            $rowsForExcel[] = [
                ['value' => (int) $row['id_pengembalian'], 'type' => 'Number'],
                ['value' => $row['kode_transaksi'], 'type' => 'String'],
                ['value' => $row['nama_pelanggan'] ?? '-', 'type' => 'String'],
                ['value' => $row['kode_kendaraan'] ?? '-', 'type' => 'String'],
                ['value' => $row['nama_kendaraan'] ?? '-', 'type' => 'String'],
                ['value' => $row['tanggal_sewa'], 'type' => 'String'],
                ['value' => $row['tanggal_kembali'], 'type' => 'String'],
                ['value' => $row['tanggal_pengembalian'], 'type' => 'String'],
                ['value' => (int) $row['terlambat'], 'type' => 'Number'],
                ['value' => (float) $row['denda'], 'type' => 'Number'],
                ['value' => (float) $row['total_bayar'], 'type' => 'Number'],
                ['value' => $row['keterangan'] ?? '', 'type' => 'String'],
            ];
        }

        $summaryMetrics = [
            ['label' => 'JUMLAH PENGEMBALIAN', 'value' => (int) $summary['total_rows'], 'money' => false],
            ['label' => 'TOTAL DENDA', 'value' => (float) $summary['nominal_utama'], 'money' => true],
            ['label' => 'TOTAL DIBAYAR', 'value' => (float) $summary['sudah_bayar'], 'money' => true],
            ['label' => 'TOTAL HARI TERLAMBAT', 'value' => (int) $summary['terlambat'], 'money' => false],
        ];
        $columnWidths = [105, 115, 145, 105, 155, 100, 145, 130, 95, 100, 105, 190];
        $moneyColumns = [9, 10];
        $statusColumns = [];
    }

    $columnCount = count($headers);
    $metricSpan = (int) ($columnCount / 4) - 1;
    $lastRow = 8 + count($rowsForExcel);

    $xmlEscape = static function ($value): string {
        $value = (string) $value;
        // XML 1.0 tidak menerima sebagian karakter kontrol yang mungkin berasal dari input pengguna.
        $value = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    };

    $makeCell = static function ($value, string $styleId = 'Body', string $type = 'String', ?int $mergeAcross = null) use ($xmlEscape): string {
        $mergeAttribute = $mergeAcross !== null && $mergeAcross > 0 ? ' ss:MergeAcross="' . $mergeAcross . '"' : '';
        return '<Cell ss:StyleID="' . $xmlEscape($styleId) . '"' . $mergeAttribute . '><Data ss:Type="'
            . $xmlEscape($type) . '">' . $xmlEscape($value) . '</Data></Cell>';
    };

    $filename = 'laporan-rentcar-' . $reportType . '-' . $startDate . '-sampai-' . $endDate . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<?mso-application progid="Excel.Sheet"?>';
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"';
    echo ' xmlns:o="urn:schemas-microsoft-com:office:office"';
    echo ' xmlns:x="urn:schemas-microsoft-com:office:excel"';
    echo ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"';
    echo ' xmlns:html="http://www.w3.org/TR/REC-html40">';

    echo '<DocumentProperties xmlns="urn:schemas-microsoft-com:office:office">';
    echo '<Author>RentCar</Author><Title>' . $xmlEscape($exportTitle) . '</Title>';
    echo '<Subject>Laporan ' . $xmlEscape($reportLabel) . '</Subject><Created>' . date('Y-m-d\TH:i:s\Z') . '</Created>';
    echo '</DocumentProperties>';

    echo '<Styles>';
    echo '<Style ss:ID="Default" ss:Name="Normal"><Alignment ss:Vertical="Center"/><Font ss:FontName="Arial" x:Family="Swiss" ss:Size="10" ss:Color="#111316"/><Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/></Style>';
    echo '<Style ss:ID="Title"><Alignment ss:Horizontal="Left" ss:Vertical="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="3" ss:Color="#E32636"/></Borders><Font ss:FontName="Arial" x:Family="Swiss" ss:Size="18" ss:Bold="1" ss:Color="#F5F6F7"/><Interior ss:Color="#08090B" ss:Pattern="Solid"/></Style>';
    echo '<Style ss:ID="Subtitle"><Alignment ss:Horizontal="Left" ss:Vertical="Center"/><Font ss:FontName="Arial" x:Family="Swiss" ss:Size="10" ss:Color="#C7CCD2"/><Interior ss:Color="#111316" ss:Pattern="Solid"/></Style>';
    echo '<Style ss:ID="Section"><Alignment ss:Horizontal="Left" ss:Vertical="Center"/><Font ss:FontName="Arial" x:Family="Swiss" ss:Size="10" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#E32636" ss:Pattern="Solid"/></Style>';
    echo '<Style ss:ID="Header"><Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#08090B"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#F5F6F7"/></Borders><Font ss:FontName="Arial" x:Family="Swiss" ss:Size="10" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#E32636" ss:Pattern="Solid"/></Style>';
    echo '<Style ss:ID="Body"><Alignment ss:Vertical="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D9DDE2"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/></Borders><Font ss:FontName="Arial" x:Family="Swiss" ss:Size="10" ss:Color="#111316"/><Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/></Style>';
    echo '<Style ss:ID="BodyAlt"><Alignment ss:Vertical="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D9DDE2"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/></Borders><Font ss:FontName="Arial" x:Family="Swiss" ss:Size="10" ss:Color="#111316"/><Interior ss:Color="#F1F3F5" ss:Pattern="Solid"/></Style>';
    echo '<Style ss:ID="BodyNumber"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D9DDE2"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/></Borders><Font ss:FontName="Arial" x:Family="Swiss" ss:Size="10" ss:Color="#111316"/><Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/></Style>';
    echo '<Style ss:ID="BodyNumberAlt"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D9DDE2"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/></Borders><Font ss:FontName="Arial" x:Family="Swiss" ss:Size="10" ss:Color="#111316"/><Interior ss:Color="#F1F3F5" ss:Pattern="Solid"/></Style>';
    echo '<Style ss:ID="BodyMoney"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D9DDE2"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/></Borders><Font ss:FontName="Arial" x:Family="Swiss" ss:Size="10" ss:Color="#111316"/><Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/><NumberFormat ss:Format="&quot;Rp&quot; #,##0;[Red]-&quot;Rp&quot; #,##0"/></Style>';
    echo '<Style ss:ID="BodyMoneyAlt"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D9DDE2"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#E5E7EB"/></Borders><Font ss:FontName="Arial" x:Family="Swiss" ss:Size="10" ss:Color="#111316"/><Interior ss:Color="#F1F3F5" ss:Pattern="Solid"/><NumberFormat ss:Format="&quot;Rp&quot; #,##0;[Red]-&quot;Rp&quot; #,##0"/></Style>';
    echo '<Style ss:ID="StatusGood"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D9DDE2"/></Borders><Font ss:FontName="Arial" x:Family="Swiss" ss:Size="10" ss:Bold="1" ss:Color="#166534"/><Interior ss:Color="#DCFCE7" ss:Pattern="Solid"/></Style>';
    echo '<Style ss:ID="StatusPending"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D9DDE2"/></Borders><Font ss:FontName="Arial" x:Family="Swiss" ss:Size="10" ss:Bold="1" ss:Color="#92400E"/><Interior ss:Color="#FEF3C7" ss:Pattern="Solid"/></Style>';
    echo '<Style ss:ID="StatusRisk"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D9DDE2"/></Borders><Font ss:FontName="Arial" x:Family="Swiss" ss:Size="10" ss:Bold="1" ss:Color="#991B1B"/><Interior ss:Color="#FEE2E2" ss:Pattern="Solid"/></Style>';
    echo '<Style ss:ID="Spacer"><Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/></Style>';
    for ($metricIndex = 1; $metricIndex <= 4; $metricIndex++) {
        $metricColor = $metricIndex === 4 ? '#E32636' : ($metricIndex === 1 ? '#1A1D21' : '#2D3136');
        echo '<Style ss:ID="MetricLabel' . $metricIndex . '"><Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#FFFFFF"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#FFFFFF"/></Borders><Font ss:FontName="Arial" x:Family="Swiss" ss:Size="9" ss:Bold="1" ss:Color="#F5F6F7"/><Interior ss:Color="' . $metricColor . '" ss:Pattern="Solid"/></Style>';
        echo '<Style ss:ID="MetricValue' . $metricIndex . '"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#E32636"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D9DDE2"/></Borders><Font ss:FontName="Arial" x:Family="Swiss" ss:Size="14" ss:Bold="1" ss:Color="#08090B"/><Interior ss:Color="#F5F6F7" ss:Pattern="Solid"/></Style>';
        echo '<Style ss:ID="MetricMoney' . $metricIndex . '"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="2" ss:Color="#E32636"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D9DDE2"/></Borders><Font ss:FontName="Arial" x:Family="Swiss" ss:Size="13" ss:Bold="1" ss:Color="#08090B"/><Interior ss:Color="#F5F6F7" ss:Pattern="Solid"/><NumberFormat ss:Format="&quot;Rp&quot; #,##0;[Red]-&quot;Rp&quot; #,##0"/></Style>';
    }
    echo '</Styles>';

    echo '<Worksheet ss:Name="' . $xmlEscape($sheetName) . '">';
    echo '<Table ss:ExpandedColumnCount="' . $columnCount . '" ss:ExpandedRowCount="' . $lastRow . '" x:FullColumns="1" x:FullRows="1">';
    foreach ($columnWidths as $width) {
        echo '<Column ss:Width="' . (int) $width . '"/>';
    }

    echo '<Row ss:Height="38">' . $makeCell($exportTitle, 'Title', 'String', $columnCount - 1) . '</Row>';
    echo '<Row ss:Height="24">' . $makeCell($subtitle, 'Subtitle', 'String', $columnCount - 1) . '</Row>';
    echo '<Row ss:Height="8"><Cell ss:StyleID="Spacer" ss:MergeAcross="' . ($columnCount - 1) . '"><Data ss:Type="String"></Data></Cell></Row>';
    echo '<Row ss:Height="22">' . $makeCell('▣ RINGKASAN LAPORAN', 'Section', 'String', $columnCount - 1) . '</Row>';

    echo '<Row ss:Height="28">';
    foreach ($summaryMetrics as $index => $metric) {
        echo $makeCell($metric['label'], 'MetricLabel' . ($index + 1), 'String', $metricSpan);
    }
    echo '</Row><Row ss:Height="32">';
    foreach ($summaryMetrics as $index => $metric) {
        $metricStyle = ($metric['money'] ? 'MetricMoney' : 'MetricValue') . ($index + 1);
        echo $makeCell($metric['value'], $metricStyle, 'Number', $metricSpan);
    }
    echo '</Row>';
    echo '<Row ss:Height="8"><Cell ss:StyleID="Spacer" ss:MergeAcross="' . ($columnCount - 1) . '"><Data ss:Type="String"></Data></Cell></Row>';

    echo '<Row ss:Height="34">';
    foreach ($headers as $headerLabel) {
        echo $makeCell($headerLabel, 'Header', 'String');
    }
    echo '</Row>';

    foreach ($rowsForExcel as $rowIndex => $excelRow) {
        $isAlt = ($rowIndex % 2) === 1;
        echo '<Row ss:Height="22">';
        foreach ($excelRow as $columnIndex => $cell) {
            $value = $cell['value'];
            $styleId = $isAlt ? 'BodyAlt' : 'Body';
            if ($cell['type'] === 'Number') {
                $styleId = in_array($columnIndex, $moneyColumns, true)
                    ? ($isAlt ? 'BodyMoneyAlt' : 'BodyMoney')
                    : ($isAlt ? 'BodyNumberAlt' : 'BodyNumber');
            }

            if (in_array($columnIndex, $statusColumns, true)) {
                $statusText = strtolower((string) $value);
                if (str_contains($statusText, 'lunas') || str_contains($statusText, 'selesai') || str_contains($statusText, 'diverifikasi')) {
                    $styleId = 'StatusGood';
                } elseif (str_contains($statusText, 'batal') || str_contains($statusText, 'terlambat') || str_contains($statusText, 'ditolak')) {
                    $styleId = 'StatusRisk';
                } elseif (str_contains($statusText, 'menunggu') || str_contains($statusText, 'dipesan') || str_contains($statusText, 'belum')) {
                    $styleId = 'StatusPending';
                }
            }

            echo $makeCell($value, $styleId, $cell['type']);
        }
        echo '</Row>';
    }

    echo '</Table>';
    echo '<x:AutoFilter x:Range="R8C1:R' . $lastRow . 'C' . $columnCount . '"/>';
    echo '<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel"><Selected/><DoNotDisplayGridlines/><PageSetup><Layout x:Orientation="Landscape"/></PageSetup><Print><PaperSizeIndex>9</PaperSizeIndex><HorizontalResolution>300</HorizontalResolution><VerticalResolution>300</VerticalResolution></Print></WorksheetOptions>';
    echo '</Worksheet></Workbook>';
    exit;
}

$exportParams = [
    'jenis_laporan' => $reportType,
    'mulai' => $startDate,
    'sampai' => $endDate,
    'export' => 'excel',
];
$exportUrl = 'laporan.php?' . http_build_query($exportParams);

kh_render_start('Laporan', 'laporan', 'laporan.css', 'laporan.js');
?>

<section class="vehicle-page-heading">
    <div>
        <div class="vehicle-eyebrow">RINGKASAN OPERASIONAL</div>
        <h1>Laporan RentCar</h1>
        <p>Filter periode laporan dan ekspor data ke format Excel.</p>
    </div>
    <a href="<?= kh_h($exportUrl); ?>" class="vehicle-btn vehicle-btn-primary">
        <i class="bi bi-file-earmark-spreadsheet"></i>
        <span>Unduh Excel</span>
    </a>
</section>

<?php if ($pageError !== '') : ?>
    <div class="vehicle-alert vehicle-alert-error" role="alert">
        <i class="bi bi-exclamation-triangle"></i>
        <span><?= kh_h($pageError); ?></span>
    </div>
<?php endif; ?>

<section class="vehicle-panel report-filter-panel">
    <div class="vehicle-panel-heading">
        <div>
            <h2>Filter laporan</h2>
            <p>Laporan transaksi mengikuti tanggal dibuat. Laporan pengembalian mengikuti tanggal pengembalian.</p>
        </div>
    </div>

    <form method="get" action="laporan.php" class="module-filters report-filters">
        <label class="module-field module-filter-field">
            <span>Jenis laporan</span>
            <select name="jenis_laporan" id="reportTypeSelect">
                <option value="transaksi" <?= $reportType === 'transaksi' ? 'selected' : ''; ?>>Transaksi</option>
                <option value="pengembalian" <?= $reportType === 'pengembalian' ? 'selected' : ''; ?>>Pengembalian</option>
            </select>
        </label>

        <label class="module-field module-filter-field">
            <span>Tanggal mulai</span>
            <input type="date" name="mulai" value="<?= kh_h($startDate); ?>" required>
        </label>

        <label class="module-field module-filter-field">
            <span>Tanggal akhir</span>
            <input type="date" name="sampai" value="<?= kh_h($endDate); ?>" required>
        </label>

        <button type="submit" class="vehicle-btn vehicle-btn-secondary">
            <i class="bi bi-funnel"></i> Terapkan filter
        </button>
    </form>
</section>

<section class="module-stats report-stats">
    <article class="module-stat-card">
        <span class="module-stat-icon"><i class="bi bi-list-check"></i></span>
        <div><span><?= $reportType === 'transaksi' ? 'Jumlah transaksi' : 'Jumlah pengembalian'; ?></span><strong><?= number_format((int) $summary['total_rows'], 0, ',', '.'); ?></strong></div>
    </article>

    <article class="module-stat-card">
        <span class="module-stat-icon is-red"><i class="bi bi-cash-stack"></i></span>
        <div><span><?= $reportType === 'transaksi' ? 'Total nilai transaksi' : 'Total denda'; ?></span><strong><?= kh_h(kh_money($summary['nominal_utama'])); ?></strong></div>
    </article>

    <article class="module-stat-card">
        <span class="module-stat-icon is-green"><i class="bi bi-wallet2"></i></span>
        <div><span><?= $reportType === 'transaksi' ? 'Sudah dibayar' : 'Total tagihan pengembalian'; ?></span><strong><?= kh_h(kh_money($summary['sudah_bayar'])); ?></strong></div>
    </article>

    <article class="module-stat-card">
        <span class="module-stat-icon is-amber"><i class="bi bi-calendar2-week"></i></span>
        <div>
            <span><?= $reportType === 'transaksi' ? 'Sisa tagihan' : 'Akumulasi hari terlambat'; ?></span>
            <strong><?= $reportType === 'transaksi' ? kh_h(kh_money($summary['sisa_tagihan'])) : number_format((int) $summary['terlambat'], 0, ',', '.') . ' hari'; ?></strong>
        </div>
    </article>
</section>

<section class="vehicle-panel module-list-panel">
    <div class="vehicle-panel-heading">
        <div>
            <h2><?= $reportType === 'transaksi' ? 'Laporan Transaksi' : 'Laporan Pengembalian'; ?></h2>
            <p>Periode <?= kh_h($startDate); ?> sampai <?= kh_h($endDate); ?>. <?= number_format(count($reportRows), 0, ',', '.'); ?> baris.</p>
        </div>
        <a href="<?= kh_h($exportUrl); ?>" class="vehicle-btn vehicle-btn-primary vehicle-btn-small">
            <i class="bi bi-download"></i> Excel
        </a>
    </div>

    <div class="module-table-wrap">
        <table class="vehicle-table module-table report-table">
            <?php if ($reportType === 'transaksi') : ?>
                <thead>
                    <tr>
                        <th>Kode transaksi</th>
                        <th>Dibuat</th>
                        <th>Pelanggan</th>
                        <th>Kendaraan</th>
                        <th>Tanggal sewa</th>
                        <th>Lama</th>
                        <th>Total</th>
                        <th>Dibayar</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($reportRows) : ?>
                        <?php foreach ($reportRows as $row) : ?>
                            <tr>
                                <td><strong><?= kh_h($row['kode_transaksi']); ?></strong></td>
                                <td><?= kh_h(date('d-m-Y', strtotime((string) $row['created_at']))); ?></td>
                                <td><?= kh_h($row['nama_pelanggan'] ?? '-'); ?></td>
                                <td>
                                    <div class="module-primary-cell">
                                        <strong><?= kh_h($row['nama_kendaraan'] ?? '-'); ?></strong>
                                        <span><?= kh_h($row['kode_kendaraan'] ?? '-'); ?></span>
                                    </div>
                                </td>
                                <td><?= kh_h($row['tanggal_sewa']); ?></td>
                                <td><?= (int) $row['lama_sewa']; ?> hari</td>
                                <td><?= kh_h(kh_money($row['total_harga'])); ?></td>
                                <td><?= kh_h(kh_money($row['uang_bayar'])); ?></td>
                                <td>
                                    <span class="module-status <?= $row['status_pembayaran'] === 'Lunas' ? 'is-green' : 'is-amber'; ?>">
                                        <?= kh_h($row['status_pembayaran']); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="9"><div class="vehicle-empty-state"><span><i class="bi bi-file-earmark-bar-graph"></i></span><strong>Tidak ada data transaksi</strong><p>Belum ada transaksi pada periode yang dipilih.</p></div></td></tr>
                    <?php endif; ?>
                </tbody>
            <?php else : ?>
                <thead>
                    <tr>
                        <th>Kode transaksi</th>
                        <th>Pelanggan</th>
                        <th>Kendaraan</th>
                        <th>Tanggal kembali</th>
                        <th>Terlambat</th>
                        <th>Denda</th>
                        <th>Total tagihan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($reportRows) : ?>
                        <?php foreach ($reportRows as $row) : ?>
                            <tr>
                                <td><strong><?= kh_h($row['kode_transaksi']); ?></strong></td>
                                <td><?= kh_h($row['nama_pelanggan'] ?? '-'); ?></td>
                                <td>
                                    <div class="module-primary-cell">
                                        <strong><?= kh_h($row['nama_kendaraan'] ?? '-'); ?></strong>
                                        <span><?= kh_h($row['kode_kendaraan'] ?? '-'); ?></span>
                                    </div>
                                </td>
                                <td><?= kh_h($row['tanggal_pengembalian']); ?></td>
                                <td><span class="module-status <?= (int) $row['terlambat'] > 0 ? 'is-amber' : 'is-green'; ?>"><?= (int) $row['terlambat']; ?> hari</span></td>
                                <td><?= kh_h(kh_money($row['denda'])); ?></td>
                                <td><strong><?= kh_h(kh_money($row['total_bayar'])); ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="7"><div class="vehicle-empty-state"><span><i class="bi bi-file-earmark-bar-graph"></i></span><strong>Tidak ada data pengembalian</strong><p>Belum ada pengembalian pada periode yang dipilih.</p></div></td></tr>
                    <?php endif; ?>
                </tbody>
            <?php endif; ?>
        </table>
    </div>
</section>

<?php kh_render_end(); ?>
