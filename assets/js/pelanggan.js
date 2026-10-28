'use strict';

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-confirm-customer-delete]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            const customerName = form.dataset.customerName || 'pelanggan ini';
            const confirmed = window.confirm(
                `Hapus data ${customerName}? Data yang sudah terhubung dengan transaksi tidak bisa dihapus.`
            );

            if (!confirmed) {
                event.preventDefault();
            }
        });
    });

    const form = document.getElementById('customerForm');
    if (form) {
        form.addEventListener('submit', (event) => {
            const nik = form.querySelector('[name="nik"]')?.value.trim() || '';
            const phone = form.querySelector('[name="no_hp"]')?.value.trim() || '';

            if (!nik || !phone) {
                event.preventDefault();
                window.alert('NIK dan nomor HP wajib diisi.');
            }
        });
    }
});
