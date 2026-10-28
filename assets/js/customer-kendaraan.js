document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('customerRentalForm');
    if (!form) return;

    const startInput = document.getElementById('tanggalSewa');
    const returnInput = document.getElementById('tanggalKembali');
    const startTimeInput = document.getElementById('waktuSewa');
    const returnTimeInput = document.getElementById('waktuKembali');
    const totalOutput = document.getElementById('rentalEstimateTotal');
    const daysOutput = document.getElementById('rentalEstimateDays');
    const paymentChoice = document.getElementById('jenisPembayaran');
    const paymentMethod = document.getElementById('metodePembayaran');
    const paymentInput = document.getElementById('nominalPembayaran');
    const paymentOutput = document.getElementById('rentalPaymentEstimate');
    const dueOutput = document.getElementById('rentalTotalDue');
    const proofField = document.getElementById('paymentProofField');
    const proofInput = document.getElementById('buktiPembayaran');
    const dailyPrice = Number.parseFloat(form.dataset.dailyPrice || '0');
    const dpPercent = Number.parseFloat(form.dataset.dpPercent || '30');
    const maxRentalDays = Number.parseInt(form.dataset.maxDays || '90', 10);

    const formatCurrency = (amount) => new Intl.NumberFormat('id-ID', {
        style: 'currency', currency: 'IDR', maximumFractionDigits: 0
    }).format(Math.max(0, Math.round(amount)));

    const digitsOnly = (value) => String(value || '').replace(/[^0-9]/g, '');
    const parseAmount = (value) => {
        const digits = digitsOnly(value);
        if (!digits) return 0;
        const amount = Number(digits);
        return Number.isSafeInteger(amount) ? amount : 0;
    };

    const formatInput = (input) => {
        if (!input) return;
        const original = input.value;
        const caret = input.selectionStart ?? original.length;
        const digitsBeforeCaret = digitsOnly(original.slice(0, caret)).length;
        const digits = digitsOnly(original);
        const formattedNumber = digits ? new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(Number(digits)) : '';
        const formatted = formattedNumber ? `Rp${formattedNumber}` : '';
        input.value = formatted;
        let nextCaret = formatted.length;
        if (digitsBeforeCaret < digits.length) {
            let seen = 0;
            nextCaret = 0;
            while (nextCaret < formatted.length && seen < digitsBeforeCaret) {
                if (/[0-9]/.test(formatted[nextCaret])) seen += 1;
                nextCaret += 1;
            }
        }
        try { input.setSelectionRange(nextCaret, nextCaret); } catch (_) { /* input type unsupported */ }
    };

    const parseDateTime = (dateValue, timeValue) => {
        if (!dateValue || !timeValue) return null;
        const [year, month, day] = dateValue.split('-').map(Number);
        const [hours, minutes] = timeValue.split(':').map(Number);
        if (![year, month, day, hours, minutes].every(Number.isFinite)) return null;
        const date = new Date(year, month - 1, day, hours, minutes, 0, 0);
        if (date.getFullYear() !== year || date.getMonth() !== month - 1 || date.getDate() !== day) return null;
        return date;
    };

    const getRental = () => {
        const start = parseDateTime(startInput?.value, startTimeInput?.value);
        const end = parseDateTime(returnInput?.value, returnTimeInput?.value);
        if (!start || !end || end <= start) return { days: 0, total: 0 };
        const days = Math.max(1, Math.ceil((end.getTime() - start.getTime()) / 86400000));
        return { days, total: Math.round(dailyPrice * days) };
    };

    const setPaymentSuggestion = (force = false) => {
        if (!paymentInput) return;
        const { total } = getRental();
        const mode = paymentChoice?.value || 'dp';
        const suggestion = mode === 'penuh' ? total : Math.ceil(total * dpPercent / 100);
        if (force || mode === 'penuh' || !paymentInput.value) {
            paymentInput.value = suggestion > 0 ? `Rp${new Intl.NumberFormat('id-ID').format(suggestion)}` : '';
        }
        paymentInput.readOnly = mode === 'penuh';
        if (mode !== 'penuh') paymentInput.readOnly = false;
    };

    const updateProofRequirement = () => {
        const method = paymentMethod?.value || 'Transfer Bank';
        const proofRequired = method !== 'Tunai di Kantor';
        if (proofInput) proofInput.required = proofRequired;
        if (proofField) proofField.classList.toggle('is-optional-proof', !proofRequired);
    };

    const updateEstimate = (refreshPayment = false) => {
        const { days, total } = getRental();
        if (totalOutput) totalOutput.textContent = formatCurrency(total);
        if (daysOutput) daysOutput.textContent = days ? `${days} hari` : 'Pilih tanggal dan waktu kembali setelah mulai sewa';
        if (dueOutput) dueOutput.textContent = formatCurrency(total);
        if (refreshPayment && paymentChoice?.value !== 'sebagian') setPaymentSuggestion(true);
        if (paymentOutput) paymentOutput.textContent = formatCurrency(parseAmount(paymentInput?.value));
    };

    startInput?.addEventListener('change', () => updateEstimate(true));
    returnInput?.addEventListener('change', () => updateEstimate(true));
    startTimeInput?.addEventListener('change', () => updateEstimate(true));
    returnTimeInput?.addEventListener('change', () => updateEstimate(true));
    paymentChoice?.addEventListener('change', () => {
        setPaymentSuggestion(true);
        updateEstimate();
    });
    paymentInput?.addEventListener('input', () => {
        formatInput(paymentInput);
        if (paymentOutput) paymentOutput.textContent = formatCurrency(parseAmount(paymentInput.value));
    });
    paymentMethod?.addEventListener('change', updateProofRequirement);

    form.addEventListener('submit', (event) => {
        const start = parseDateTime(startInput?.value, startTimeInput?.value);
        const end = parseDateTime(returnInput?.value, returnTimeInput?.value);
        const amount = parseAmount(paymentInput?.value);
        const total = getRental().total;
        const mode = paymentChoice?.value || 'dp';
        const proofRequired = (paymentMethod?.value || 'Transfer Bank') !== 'Tunai di Kantor';

        if (!start || !end || start <= new Date() || end <= start) {
            event.preventDefault();
            window.alert('Periksa tanggal dan waktu sewa. Waktu kembali harus setelah waktu mulai.');
            return;
        }
        if (getRental().days > maxRentalDays) {
            event.preventDefault();
            window.alert(`Durasi sewa maksimal ${maxRentalDays} hari.`);
            return;
        }
        if (!amount || amount < 1 || amount > total) {
            event.preventDefault();
            window.alert('Nominal pembayaran harus lebih dari nol dan tidak melebihi total tagihan.');
            paymentInput?.focus();
            return;
        }
        if (mode === 'penuh' && amount !== total) {
            event.preventDefault();
            window.alert('Nominal pembayaran penuh harus sama dengan total tagihan.');
            return;
        }
        if (proofRequired && (!proofInput?.files || proofInput.files.length === 0)) {
            event.preventDefault();
            window.alert('Unggah bukti pembayaran untuk metode Transfer Bank atau QRIS.');
            proofInput?.focus();
            return;
        }

        const submitButton = form.querySelector('button[type="submit"]');
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = 'Mengirim pesanan...';
        }
    });

    if (paymentInput?.value) formatInput(paymentInput);
    updateProofRequirement();
    setPaymentSuggestion(paymentChoice?.value === 'penuh' || !paymentInput?.value);
    updateEstimate();
});
