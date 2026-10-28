'use strict';

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.password-visibility').forEach((button) => {
        button.addEventListener('click', () => {
            const wrapper = button.closest('.password-input-wrap');
            const input = wrapper ? wrapper.querySelector('input') : null;
            const icon = button.querySelector('i');

            if (!input) {
                return;
            }

            const showPassword = input.type === 'password';
            input.type = showPassword ? 'text' : 'password';
            button.setAttribute('aria-label', showPassword ? 'Sembunyikan password' : 'Tampilkan password');

            if (icon) {
                icon.classList.toggle('bi-eye', !showPassword);
                icon.classList.toggle('bi-eye-slash', showPassword);
            }
        });
    });
});
