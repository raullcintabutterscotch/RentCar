document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('customerPaymentForm');
    if (!form) return;
    const input = document.getElementById('paymentAmount');
    const type = document.getElementById('paymentType');
    const method = document.getElementById('paymentMethod');
    const proof = document.getElementById('paymentProof');
    const proofField = document.getElementById('proofField');
    const preview = document.getElementById('paymentAmountPreview');
    const remaining = Number(form.dataset.remaining || 0);
    const kind = form.dataset.kind || 'sewa';
    const digits = (value) => String(value ?? '').replace(/\D/g, '');
    const parse = () => Number(digits(input?.value) || 0);
    const rupiah = (value) => new Intl.NumberFormat('id-ID', {
        style: 'currency', currency: 'IDR', maximumFractionDigits: 0
    }).format(Number(value) || 0);
    const format = () => {
        if (!input) return;
        const old = input.value;
        const caret = input.selectionStart ?? old.length;
        const before = digits(old.slice(0, caret)).length;
        const num = digits(old);
        const pretty = num ? `Rp${new Intl.NumberFormat('id-ID').format(Number(num))}` : '';
        input.value = pretty;
        let next = 0;
        let seen = 0;
        while (next < pretty.length && seen < before) {
            if (/\d/.test(pretty[next])) seen++;
            next++;
        }
        try { input.setSelectionRange(next, next); } catch (_) { /* unsupported */ }
        if (preview) preview.textContent = rupiah(parse());
    };
    const updateMethod = () => {
        const required = method?.value !== 'Tunai di Kantor';
        if (proof) proof.required = required;
        if (proofField) proofField.hidden = !required;
    };
    input?.addEventListener('input', format);
    type?.addEventListener('change', () => {
        if (type.value === 'Pelunasan' && input) {
            input.value = `Rp${new Intl.NumberFormat('id-ID').format(remaining)}`;
            if (preview) preview.textContent = rupiah(remaining);
        }
    });
    method?.addEventListener('change', updateMethod);
    form.addEventListener('submit', (event) => {
        const amount = parse();
        if (amount <= 0 || amount > remaining) {
            event.preventDefault();
            window.alert(`Nominal harus lebih dari Rp0 dan tidak melebihi sisa tagihan ${rupiah(remaining)}.`);
            input?.focus();
            return;
        }
        if (kind === 'sewa' && type?.value === 'Pelunasan' && amount !== remaining) {
            event.preventDefault();
            window.alert(`Pelunasan harus sama dengan sisa tagihan ${rupiah(remaining)}.`);
            return;
        }
        if (method?.value !== 'Tunai di Kantor' && (!proof?.files || proof.files.length === 0)) {
            event.preventDefault();
            window.alert('Unggah bukti pembayaran untuk Transfer Bank atau QRIS.');
            proof?.focus();
            return;
        }
        const button = form.querySelector('button[type="submit"]');
        if (button) { button.disabled = true; button.textContent = 'Mengirim pembayaran...'; }
    });
    updateMethod();
    format();
});
