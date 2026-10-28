<?php
/*
 * Shared sidebar for all admin pages.
 * Set $sidebarMode to "dashboard" or "vehicle" before including this file.
 * Set $sidebarActive to the current menu key.
 */
$sidebarMode = $sidebarMode ?? 'vehicle';
$sidebarActive = $sidebarActive ?? 'dashboard';
$isDashboardSidebar = $sidebarMode === 'dashboard';

$sidebarItems = [
    [
        'key' => 'dashboard',
        'label' => 'Dashboard',
        'icon' => 'bi-grid-1x2',
        'url' => 'dashboard.php',
    ],
    [
        'key' => 'kendaraan',
        'label' => 'Kendaraan',
        'icon' => 'bi-car-front',
        'url' => 'kendaraan.php',
    ],
    [
        'key' => 'transaksi',
        'label' => 'Transaksi',
        'icon' => 'bi-receipt',
        'url' => 'transaksi.php',
    ],
    [
        'key' => 'pelanggan',
        'label' => 'Pelanggan',
        'icon' => 'bi-people',
        'url' => 'pelanggan.php',
    ],
    [
        'key' => 'pengembalian',
        'label' => 'Pengembalian',
        'icon' => 'bi-arrow-return-left',
        'url' => 'pengembalian.php',
    ],
    [
        'key' => 'laporan',
        'label' => 'Laporan',
        'icon' => 'bi-bar-chart-line',
        'url' => 'laporan.php',
    ],
];

$sidebarAsideClass = $isDashboardSidebar ? 'sidebar' : 'vehicle-sidebar';
$sidebarBrandClass = $isDashboardSidebar ? 'brand' : 'vehicle-brand';
$sidebarLogoClass = $isDashboardSidebar ? 'brand-mark' : 'vehicle-brand-logo';
$sidebarNameClass = $isDashboardSidebar ? 'brand-text' : 'vehicle-brand-name';
$sidebarLabelClass = $isDashboardSidebar ? 'nav-label' : 'vehicle-nav-label';
$sidebarNavClass = $isDashboardSidebar ? 'sidebar-nav' : 'vehicle-nav';
$sidebarBackdropClass = $isDashboardSidebar ? 'sidebar-backdrop' : 'vehicle-sidebar-backdrop';
$sidebarId = $isDashboardSidebar ? 'sidebar' : 'vehicleSidebar';
$sidebarBackdropId = $isDashboardSidebar ? 'sidebarBackdrop' : 'vehicleSidebarBackdrop';
?>
<aside class="<?= htmlspecialchars($sidebarAsideClass, ENT_QUOTES, 'UTF-8'); ?>" id="<?= htmlspecialchars($sidebarId, ENT_QUOTES, 'UTF-8'); ?>">
    <a href="dashboard.php" class="<?= htmlspecialchars($sidebarBrandClass, ENT_QUOTES, 'UTF-8'); ?>">
        <img
            src="../assets/image/logo.svg"
            class="<?= htmlspecialchars($sidebarLogoClass, ENT_QUOTES, 'UTF-8'); ?>"
            alt="Logo RentCar"
        >
        <span class="<?= htmlspecialchars($sidebarNameClass, ENT_QUOTES, 'UTF-8'); ?>">Rent<span>Car</span></span>
    </a>

    <div class="<?= htmlspecialchars($sidebarLabelClass, ENT_QUOTES, 'UTF-8'); ?>">Menu Utama</div>

    <nav class="<?= htmlspecialchars($sidebarNavClass, ENT_QUOTES, 'UTF-8'); ?>" aria-label="Navigasi admin">
        <?php foreach ($sidebarItems as $item) : ?>
            <a
                href="<?= htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8'); ?>"
                class="<?= $sidebarActive === $item['key'] ? 'active' : ''; ?>"
                <?= $sidebarActive === $item['key'] ? 'aria-current="page"' : ''; ?>
            >
                <i class="bi <?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i>
                <span><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?></span>
            </a>
        <?php endforeach; ?>

        <div class="<?= htmlspecialchars($sidebarLabelClass, ENT_QUOTES, 'UTF-8'); ?> <?= $isDashboardSidebar ? 'nav-label-secondary' : 'vehicle-nav-label-secondary'; ?>">
            Lainnya
        </div>

        <a href="pengaturan.php" class="<?= $sidebarActive === 'pengaturan' ? 'active' : ''; ?>" <?= $sidebarActive === 'pengaturan' ? 'aria-current="page"' : ''; ?>>
            <i class="bi bi-gear"></i>
            <span>Pengaturan</span>
        </a>

        <a href="../index.php" target="_blank" rel="noopener">
            <i class="bi bi-box-arrow-up-right"></i>
            <span>Lihat Website</span>
        </a>

        <a href="../logout.php">
            <i class="bi bi-box-arrow-right"></i>
            <span>Keluar</span>
        </a>
    </nav>

    <?php if ($isDashboardSidebar) : ?>
        <div class="sidebar-promo">
            <?php if (!empty($fotoBanner)) : ?>
                <img
                    class="sidebar-promo-image"
                    src="<?= htmlspecialchars((string) $fotoBanner, ENT_QUOTES, 'UTF-8'); ?>"
                    alt=""
                >
            <?php endif; ?>

            <div class="sidebar-promo-content">
                <h3>Rental Kendaraan<br>Lebih Mudah</h3>
                <p>Kelola armada dan penyewaan dalam satu dashboard.</p>
                <a href="kendaraan.php" class="btn-sport">
                    Lihat Kendaraan
                    <i class="bi bi-arrow-right"></i>
                </a>
            </div>
        </div>
    <?php else : ?>
        <div class="vehicle-sidebar-note">
            <span class="vehicle-sidebar-note-icon"><i class="bi bi-speedometer2"></i></span>
            <strong>Panel RentCar</strong>
            <p>Kelola armada, transaksi, pelanggan, dan laporan dari satu tempat.</p>
        </div>
    <?php endif; ?>
</aside>

<div class="<?= htmlspecialchars($sidebarBackdropClass, ENT_QUOTES, 'UTF-8'); ?>" id="<?= htmlspecialchars($sidebarBackdropId, ENT_QUOTES, 'UTF-8'); ?>"></div>
