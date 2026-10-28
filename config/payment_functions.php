<?php

/** Helper aman untuk proses pembayaran pelanggan dan admin. */

function pay_parse_money($value): ?float
{
    if (!is_string($value) && !is_numeric($value)) {
        return null;
    }

    $value = trim((string) $value);
    $value = preg_replace('/^Rp\s*/iu', '', $value) ?? $value;
    $value = preg_replace('/[\s\x{00A0}]+/u', '', $value) ?? $value;

    if ($value === '' || !preg_match('/^[0-9.,]+$/u', $value)) {
        return null;
    }

    if (str_contains($value, ',')) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    } else {
        $dotCount = substr_count($value, '.');
        if ($dotCount > 1) {
            $value = str_replace('.', '', $value);
        } elseif ($dotCount === 1) {
            $parts = explode('.', $value);
            if (isset($parts[1]) && strlen($parts[1]) === 3) {
                $value = $parts[0] . $parts[1];
            }
        }
    }

    if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
        return null;
    }

    $amount = (float) $value;
    return is_finite($amount) ? $amount : null;
}

function pay_parse_date(string $value): ?DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    if (!$date || $date->format('Y-m-d') !== $value) {
        return null;
    }

    return $date;
}

function pay_bind(mysqli_stmt $stmt, string $types, array &$params): void
{
    if ($types === '') {
        return;
    }

    $arguments = [$types];

    foreach ($params as &$value) {
        $arguments[] = &$value;
    }

    unset($value);
    call_user_func_array([$stmt, 'bind_param'], $arguments);
}

/** Simpan bukti pembayaran; hanya gambar raster dan PDF yang diterima. */
function pay_store_proof(array $file, bool $required): ?string
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        if ($required) {
            throw new RuntimeException('Unggah bukti pembayaran untuk metode transfer atau QRIS.');
        }

        return null;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Bukti pembayaran gagal diunggah. Coba kembali.');
    }

    if ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('Ukuran bukti pembayaran maksimal 5 MB.');
    }

    $temporaryPath = (string) ($file['tmp_name'] ?? '');
    if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
        throw new RuntimeException('Berkas bukti pembayaran tidak valid.');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, $temporaryPath) : false;
    if ($finfo) {
        finfo_close($finfo);
    }

    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Bukti harus berupa JPG, PNG, WEBP, atau PDF.');
    }

    $root = dirname(__DIR__);
    $directory = $root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'pembayaran';

    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Folder bukti pembayaran tidak tersedia.');
    }

    $filename = 'payment_' . bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    $destination = $directory . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($temporaryPath, $destination)) {
        throw new RuntimeException('Bukti pembayaran tidak berhasil disimpan.');
    }

    return 'uploads/pembayaran/' . $filename;
}

function pay_remove_proof($relativePath): void
{
    $relativePath = str_replace('\\', '/', trim((string) $relativePath));

    if (!preg_match('#^uploads/pembayaran/[A-Za-z0-9_-]+\.(jpg|png|webp|pdf)$#i', $relativePath)) {
        return;
    }

    $root = realpath(dirname(__DIR__));
    if (!$root) {
        return;
    }

    $path = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
    $uploadRoot = realpath($root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'pembayaran');

    if (
        $path
        && $uploadRoot
        && is_file($path)
        && strncmp($path, rtrim($uploadRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR, strlen(rtrim($uploadRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) === 0
    ) {
        unlink($path);
    }
}

function pay_proof_url($relativePath, string $prefix = ''): ?string
{
    $relativePath = str_replace('\\', '/', trim((string) $relativePath));

    if (!preg_match('#^uploads/pembayaran/[A-Za-z0-9_-]+\.(jpg|png|webp|pdf)$#i', $relativePath)) {
        return null;
    }

    return $prefix . implode('/', array_map('rawurlencode', explode('/', $relativePath)));
}
