(() => {
    'use strict';

    const digitsOnly = (value) => String(value ?? '').replace(/\D/g, '');

    const splitAmount = (raw) => {
        let value = String(raw ?? '').trim().replace(/[^\d.,]/g, '');
        if (!value) {
            return { integer: '', decimal: '', hasDecimal: false };
        }

        const commaPosition = value.lastIndexOf(',');
        const hasComma = commaPosition >= 0;
        let integerPart = hasComma ? value.slice(0, commaPosition) : value;
        let decimalPart = hasComma ? value.slice(commaPosition + 1) : '';

        integerPart = integerPart.replace(/\D/g, '');
        decimalPart = decimalPart.replace(/\D/g, '').slice(0, 2);

        return {
            integer: integerPart.replace(/^0+(?=\d)/, '') || (integerPart ? '0' : ''),
            decimal: decimalPart,
            hasDecimal: hasComma
        };
    };

    const groupInteger = (value) => {
        const normalized = value.replace(/^0+(?=\d)/, '') || '0';
        return normalized.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    };

    const formatValue = (raw, forceDecimal = false) => {
        const parts = splitAmount(raw);
        if (!parts.integer && !parts.decimal) {
            return '';
        }

        const integer = groupInteger(parts.integer || '0');
        if (!parts.hasDecimal && !forceDecimal) {
            return `Rp${integer}`;
        }

        return `Rp${integer},${(parts.decimal || '').padEnd(forceDecimal ? 2 : 0, '0')}`;
    };

    const parseMoney = (raw) => {
        let value = String(raw ?? '').trim().replace(/[^\d.,-]/g, '');
        if (!value || value.startsWith('-')) {
            return 0;
        }

        if (value.includes(',')) {
            value = value.replace(/\./g, '').replace(',', '.');
        } else if ((value.match(/\./g) || []).length > 1) {
            value = value.replace(/\./g, '');
        } else if (value.includes('.')) {
            const parts = value.split('.');
            if ((parts[1] || '').length === 3) {
                value = parts.join('');
            }
        }

        const result = Number(value);
        return Number.isFinite(result) ? result : 0;
    };

    const formatField = (input, forceDecimal = false) => {
        if (!input) return;
        input.value = formatValue(input.value, forceDecimal);
    };

    const caretAnchor = (value, position) => {
        const commaPosition = value.lastIndexOf(',');
        const prefix = value.slice(0, position);

        if (commaPosition >= 0 && position > commaPosition) {
            return {
                section: 'decimal',
                decimalDigits: digitsOnly(value.slice(commaPosition + 1, position)).length,
                totalDigits: digitsOnly(prefix).length
            };
        }

        if (commaPosition >= 0 && position === commaPosition) {
            return {
                section: 'separator',
                integerDigits: digitsOnly(prefix).length,
                totalDigits: digitsOnly(prefix).length
            };
        }

        return {
            section: 'integer',
            integerDigits: digitsOnly(prefix).length,
            totalDigits: digitsOnly(prefix).length
        };
    };

    const caretPosition = (formattedValue, anchor) => {
        const commaPosition = formattedValue.lastIndexOf(',');

        if (anchor.section === 'decimal' && commaPosition >= 0) {
            let position = commaPosition + 1;
            let countedDigits = 0;
            while (position < formattedValue.length && countedDigits < anchor.decimalDigits) {
                if (/\d/.test(formattedValue[position])) countedDigits++;
                position++;
            }
            return position;
        }

        if (anchor.section === 'separator' && commaPosition >= 0) {
            return commaPosition;
        }

        const targetDigits = anchor.totalDigits ?? anchor.integerDigits ?? 0;
        if (targetDigits <= 0) return 0;

        let position = 0;
        let countedDigits = 0;
        while (position < formattedValue.length && countedDigits < targetDigits) {
            if (/\d/.test(formattedValue[position])) countedDigits++;
            position++;
        }
        return position;
    };

    const attachField = (input) => {
        if (input.dataset.moneyReady === '1') return;
        input.dataset.moneyReady = '1';
        input.value = formatValue(input.value, false);

        input.addEventListener('input', () => {
            const previousValue = input.value;
            const start = input.selectionStart ?? previousValue.length;
            const end = input.selectionEnd ?? start;
            const startAnchor = caretAnchor(previousValue, start);
            const endAnchor = caretAnchor(previousValue, end);
            const nextValue = formatValue(previousValue, false);
            input.value = nextValue;

            const nextStart = caretPosition(nextValue, startAnchor);
            const nextEnd = caretPosition(nextValue, endAnchor);
            try {
                input.setSelectionRange(nextStart, nextEnd);
            } catch (_) {
                /* Some input types do not support selection ranges. */
            }
        });

        input.addEventListener('blur', () => formatField(input, false));
    };

    window.RentCarMoney = {
        parse: parseMoney,
        format: (amount, decimals = 2) => new Intl.NumberFormat('id-ID', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals
        }).format(Number(amount) || 0),
        formatField,
        setValue: (input, value) => {
            if (!input) return;
            input.value = String(value ?? '0');
            formatField(input, false);
        }
    };

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-money-input]').forEach(attachField);
    });
})();
