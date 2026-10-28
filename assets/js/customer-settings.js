document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('customerSettingsForm');
    if (!form) return;

    form.addEventListener('submit', (event) => {
        if (!form.checkValidity()) {
            event.preventDefault();
            form.reportValidity();
            return;
        }

        const submit = form.querySelector('button[type="submit"]');
        if (submit) {
            submit.disabled = true;
            submit.innerHTML = '<i class="bi bi-check2-circle"></i> Menyimpan...';
        }
    });
});
