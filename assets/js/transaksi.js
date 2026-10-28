'use strict';

document.addEventListener('DOMContentLoaded', () => {
    const vehicleSelect = document.getElementById('transactionVehicleSelect');
    const startInput = document.getElementById('transactionStartDate');
    const endInput = document.getElementById('transactionEndDate');
    const estimate = document.getElementById('transactionEstimate');
    const estimateDetail = document.getElementById('transactionEstimateDetail');
    const createForm = document.getElementById('transactionCreateForm');

    const parseLocalDate = (value) => {
        const parts = String(value || '').split('-').map(Number);
        if (parts.length !== 3 || parts.some((part) => !Number.isFinite(part))) {
            return null;
        }
        return new Date(Date.UTC(parts[0], parts[1] - 1, parts[2]));
    };

    const formatMoney = (value) => new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0
    }).format(value);

    const updateEstimate = () => {
        if (!vehicleSelect || !startInput || !endInput || !estimate || !estimateDetail) {
            return;
        }

        const selectedOption = vehicleSelect.options[vehicleSelect.selectedIndex];
        const rate = Number(selectedOption?.dataset.rate || 0);
        const startDate = parseLocalDate(startInput.value);
        const endDate = parseLocalDate(endInput.value);

        if (!selectedOption || !selectedOption.value || !startDate || !endDate) {
            estimate.textContent = 'Pilih kendaraan dan tanggal';
            estimateDetail.textContent = 'Tarif dihitung dari harga sewa kendaraan per hari.';
            return;
        }

        const diff = Math.floor((endDate.getTime() - startDate.getTime()) / 86400000);
        if (diff < 0) {
            estimate.textContent = 'Tanggal kembali tidak valid';
            estimateDetail.textContent = 'Tanggal kembali harus sama dengan atau setelah tanggal sewa.';
            return;
        }

        const days = Math.max(1, diff);
        estimate.textContent = formatMoney(rate * days);
        estimateDetail.textContent = `${days} hari × ${formatMoney(rate)} per hari`;
    };

    [vehicleSelect, startInput, endInput].forEach((input) => {
        if (input) {
            input.addEventListener('change', updateEstimate);
        }
    });

    if (createForm) {
        createForm.addEventListener('submit', (event) => {
            const startDate = parseLocalDate(startInput?.value);
            const endDate = parseLocalDate(endInput?.value);

            if (!startDate || !endDate || endDate.getTime() < startDate.getTime()) {
                event.preventDefault();
                window.alert('Periksa tanggal sewa dan tanggal kembali.');
            }
        });
    }

    document.querySelectorAll('[data-payment-form]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            const amount = Number(form.querySelector('[name="nominal_pembayaran"]')?.value || 0);
            if (amount <= 0 || !window.confirm(`Catat pembayaran ${formatMoney(amount)}?`)) {
                event.preventDefault();
            }
        });
    });

    updateEstimate();
});
