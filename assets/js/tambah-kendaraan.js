'use strict';

document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('form.vehicle-form');
    if (!form) {
        return;
    }

    form.addEventListener('submit', (event) => {
        const price = Number(form.querySelector('[name="harga_sewa"]')?.value || 0);
        if (price <= 0) {
            event.preventDefault();
            window.alert('Harga sewa harus lebih besar dari nol.');
        }
    });
});
