'use strict';

document.addEventListener('DOMContentLoaded', () => {
    const transactionSelect = document.getElementById('returnTransactionSelect');
    const returnDateInput = document.getElementById('returnDate');
    const returnTimeInput = document.getElementById('returnTime');
    const fineInput = document.getElementById('returnFinePerDay');
    const lateEstimate = document.getElementById('returnLateEstimate');
    const fineEstimate = document.getElementById('returnFineEstimate');
    const form = document.getElementById('returnForm');

    const parseDateTime = (dateValue, timeValue = '00:00') => {
        if (!dateValue || !timeValue) return null;
        const [year, month, day] = String(dateValue).split('-').map(Number);
        const [hour, minute] = String(timeValue).split(':').map(Number);
        if (![year, month, day, hour, minute].every(Number.isFinite)) return null;
        const date = new Date(Date.UTC(year, month - 1, day, hour, minute));
        if (date.getUTCFullYear() !== year || date.getUTCMonth() !== month - 1 || date.getUTCDate() !== day) return null;
        return date;
    };

    const money = (value) => new Intl.NumberFormat('id-ID', {
        style: 'currency', currency: 'IDR', maximumFractionDigits: 0
    }).format(value);

    const calculateFine = () => {
        const option = transactionSelect?.options[transactionSelect.selectedIndex];
        const dueDate = parseDateTime(option?.dataset.dueDate || '', option?.dataset.dueTime || '09:00');
        const returnDate = parseDateTime(returnDateInput?.value || '', returnTimeInput?.value || '00:00');
        const rate = Math.max(0, Number(fineInput?.value || 0));
        if (!option?.value || !dueDate || !returnDate) {
            if (lateEstimate) lateEstimate.textContent = 'Pilih transaksi';
            if (fineEstimate) fineEstimate.textContent = 'Denda dihitung dari keterlambatan berdasarkan tarif sistem.';
            return;
        }
        const secondsLate = Math.max(0, (returnDate.getTime() - dueDate.getTime()) / 1000);
        const daysLate = secondsLate > 0 ? Math.ceil(secondsLate / 86400) : 0;
        if (lateEstimate) lateEstimate.textContent = `${daysLate} hari keterlambatan`;
        if (fineEstimate) fineEstimate.textContent = `Estimasi denda: ${money(daysLate * rate)} (${daysLate} × ${money(rate)}). Nilai akhir divalidasi server.`;
    };

    transactionSelect?.addEventListener('change', calculateFine);
    returnDateInput?.addEventListener('change', calculateFine);
    returnTimeInput?.addEventListener('change', calculateFine);
    form?.addEventListener('submit', (event) => {
        if (!transactionSelect?.value) {
            event.preventDefault();
            window.alert('Pilih transaksi yang akan dikembalikan.');
            return;
        }
        if (!window.confirm('Konfirmasi pengembalian kendaraan? Sistem akan menyimpan waktu aktual dan menghitung denda otomatis.')) event.preventDefault();
    });
    calculateFine();
});
