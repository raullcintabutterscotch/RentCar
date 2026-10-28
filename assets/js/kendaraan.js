'use strict';

document.addEventListener('DOMContentLoaded', () => {
    const statusNodes = Array.from(document.querySelectorAll('[data-vehicle-status]'));
    const statusFilterPanel = document.querySelector('[data-status-filter]');
    const statusFilter = statusFilterPanel?.dataset.statusFilter || '';
    let lastVehicleStatuses = null;
    let statusRequestRunning = false;

    const formatCount = (value) => new Intl.NumberFormat('id-ID').format(Number(value) || 0);

    const refreshVehicleStatuses = async () => {
        if (statusRequestRunning || document.visibilityState === 'hidden' || !statusNodes.length) return;
        statusRequestRunning = true;
        try {
            const response = await fetch('kendaraan.php?ajax=statuses', {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { Accept: 'application/json' }
            });
            if (!response.ok) return;
            const payload = await response.json();
            if (!payload?.success || !Array.isArray(payload.vehicles)) return;

            const nextStatuses = new Map();
            let needsFilterReload = false;
            payload.vehicles.forEach((vehicle) => {
                const id = String(vehicle.id);
                const status = String(vehicle.status || 'Tersedia');
                nextStatuses.set(id, status);
                const node = document.querySelector(`[data-vehicle-status="${id}"]`);
                if (!node) return;

                const previous = node.dataset.currentStatus || '';
                const changed = previous !== status;
                const classStatus = status.toLowerCase().replace(/[^a-z0-9-]/g, '');
                node.className = `vehicle-status vehicle-status-${classStatus}`;
                node.dataset.currentStatus = status;
                const label = node.querySelector('[data-vehicle-status-label]');
                if (label) label.textContent = status;

                if (changed && statusFilter && status !== statusFilter) {
                    needsFilterReload = true;
                }
            });

            if (lastVehicleStatuses && statusFilter) {
                for (const [id, status] of nextStatuses) {
                    if (lastVehicleStatuses.has(id) && lastVehicleStatuses.get(id) !== status) {
                        needsFilterReload = true;
                        break;
                    }
                }
            }
            lastVehicleStatuses = nextStatuses;

            if (payload.stats) {
                Object.entries(payload.stats).forEach(([key, value]) => {
                    const node = document.querySelector(`[data-vehicle-stat="${key}"]`);
                    if (node) node.textContent = formatCount(value);
                });
            }

            if (needsFilterReload) window.location.reload();
        } catch (_) {
            // The server-rendered statuses remain visible if the network is unavailable.
        } finally {
            statusRequestRunning = false;
        }
    };

    if (statusNodes.length) {
        refreshVehicleStatuses();
        window.setInterval(refreshVehicleStatuses, 15000);
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') refreshVehicleStatuses();
        });
    }

    const sidebar = document.getElementById('vehicleSidebar');
    const menuToggle = document.getElementById('vehicleMenuToggle');
    const backdrop = document.getElementById('vehicleSidebarBackdrop');

    const closeSidebar = () => {
        if (!sidebar || !menuToggle || !backdrop) {
            return;
        }

        sidebar.classList.remove('is-open');
        backdrop.classList.remove('is-visible');
        menuToggle.setAttribute('aria-expanded', 'false');
        document.body.classList.remove('vehicle-menu-open');
    };

    const openSidebar = () => {
        if (!sidebar || !menuToggle || !backdrop) {
            return;
        }

        sidebar.classList.add('is-open');
        backdrop.classList.add('is-visible');
        menuToggle.setAttribute('aria-expanded', 'true');
        document.body.classList.add('vehicle-menu-open');
    };

    if (menuToggle) {
        menuToggle.addEventListener('click', () => {
            if (sidebar && sidebar.classList.contains('is-open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });
    }

    if (backdrop) {
        backdrop.addEventListener('click', closeSidebar);
    }

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeSidebar();
        }
    });

    window.addEventListener('resize', () => {
        if (window.innerWidth > 767) {
            closeSidebar();
        }
    });

    document.querySelectorAll('[data-confirm-delete]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            const vehicleName = form.dataset.vehicleName || 'kendaraan ini';
            const confirmed = window.confirm(
                `Hapus ${vehicleName}? Tindakan ini tidak bisa dibatalkan.`
            );

            if (!confirmed) {
                event.preventDefault();
            }
        });
    });

    document.querySelectorAll('[data-image-input]').forEach((input) => {
        const previewWrap = input.closest('.vehicle-photo-field')
            ?.querySelector('[data-image-preview-wrap]');
        const previewImage = previewWrap?.querySelector('[data-image-preview]');
        const removeButton = previewWrap?.querySelector('[data-remove-preview]');
        const uploadBox = input.closest('.vehicle-upload-box');
        let previewUrl = null;

        const resetPreview = () => {
            if (previewUrl) {
                URL.revokeObjectURL(previewUrl);
                previewUrl = null;
            }

            input.value = '';

            if (previewImage) {
                previewImage.removeAttribute('src');
            }

            if (previewWrap) {
                previewWrap.hidden = true;
            }

            if (uploadBox) {
                uploadBox.classList.remove('is-selected');
            }
        };

        input.addEventListener('change', () => {
            const file = input.files && input.files[0];

            if (!file) {
                resetPreview();
                return;
            }

            if (!file.type.startsWith('image/')) {
                window.alert('Pilih berkas gambar yang valid.');
                resetPreview();
                return;
            }

            if (file.size > 5 * 1024 * 1024) {
                window.alert('Ukuran foto maksimal 5 MB.');
                resetPreview();
                return;
            }

            if (previewUrl) {
                URL.revokeObjectURL(previewUrl);
            }

            previewUrl = URL.createObjectURL(file);

            if (previewImage) {
                previewImage.src = previewUrl;
            }

            if (previewWrap) {
                previewWrap.hidden = false;
            }

            if (uploadBox) {
                uploadBox.classList.add('is-selected');
            }
        });

        if (removeButton) {
            removeButton.addEventListener('click', resetPreview);
        }
    });

    document.querySelectorAll('.vehicle-alert-close').forEach((button) => {
        button.addEventListener('click', () => {
            button.closest('.vehicle-alert')?.remove();
        });
    });
});
