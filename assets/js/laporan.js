'use strict';

document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('.report-filters');
    const start = form?.querySelector('[name="mulai"]');
    const end = form?.querySelector('[name="sampai"]');

    form?.addEventListener('submit', (event) => {
        if (!start?.value || !end?.value || start.value > end.value) {
            event.preventDefault();
            window.alert('Tanggal mulai harus terisi dan tidak boleh setelah tanggal akhir.');
        }
    });
});
