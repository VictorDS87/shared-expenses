const $ = (selector, scope = document) => scope.querySelector(selector);
const $$ = (selector, scope = document) => [...scope.querySelectorAll(selector)];

const sidebar = $('#sidebar');
const menuToggle = $('[data-sidebar-toggle]');
const mobileMedia = window.matchMedia('(max-width: 760px)');
function setMenu(open) {
    document.body.classList.toggle('sidebar-open', open);
    menuToggle?.setAttribute('aria-expanded', String(open));
    if (sidebar) sidebar.inert = mobileMedia.matches && !open;
}
menuToggle?.addEventListener('click', () => setMenu(!document.body.classList.contains('sidebar-open')));
$('[data-sidebar-close]')?.addEventListener('click', () => setMenu(false));
mobileMedia.addEventListener('change', () => setMenu(false));
setMenu(false);
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && document.body.classList.contains('sidebar-open')) {
        setMenu(false);
        menuToggle?.focus();
    }
});
$$('[data-dismiss]').forEach((button) => button.addEventListener('click', () => button.closest('.notice')?.remove()));
$$('[data-open-dialog]').forEach((button) => button.addEventListener('click', () => document.getElementById(button.dataset.openDialog)?.showModal()));
$$('[data-close-dialog]').forEach((button) => button.addEventListener('click', () => button.closest('dialog')?.close()));
$$('dialog').forEach((dialog) => dialog.addEventListener('click', (event) => {
    if (event.target === dialog) {
        const rect = dialog.getBoundingClientRect();
        if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) dialog.close();
    }
}));

// Integer cents and basis points mirror the server's largest-remainder rule.
function decimalToMinor(value) {
    const text = String(value).trim().replace(',', '.');
    if (!/^\d{1,9}(?:\.\d{1,2})?$/.test(text)) return null;
    const [whole, fraction = ''] = text.split('.');
    return BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'));
}
function formatMinor(value, currency) {
    const cents = value < 0n ? -value : value;
    const whole = (cents / 100n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    const decimals = (cents % 100n).toString().padStart(2, '0');
    return `${currency === 'USD' ? 'US$' : 'R$'} ${value < 0n ? '-' : ''}${whole},${decimals}`;
}
const expenseForm = $('[data-expense-form]');
if (expenseForm) {
    const rowsContainer = $('[data-allocation-rows]', expenseForm);
    const amountInput = $('[data-amount]', expenseForm);
    const currencyInput = $('[data-currency]', expenseForm);
    const addButton = $('[data-add-allocation]', expenseForm);
    const rowTemplate = $('.allocation-row', rowsContainer)?.cloneNode(true);

    function refreshAllocation() {
        const rows = $$('.allocation-row', rowsContainer);
        const amount = decimalToMinor(amountInput.value) ?? 0n;
        const currency = currencyInput.value;
        const allocations = rows.map((row, index) => ({
            row, index, unit: $('[data-unit]', row).value,
            weight: decimalToMinor($('[data-percentage]', row).value) ?? 0n,
            allocated: 0n, remainder: 0n,
        }));
        const total = allocations.reduce((sum, item) => sum + item.weight, 0n);
        const duplicates = allocations.some((item, index) => item.unit && allocations.some((other, otherIndex) => otherIndex !== index && other.unit === item.unit));
        const positive = allocations.every((item) => item.weight > 0n && item.weight <= 10000n);
        const validTotal = total === 10000n && positive;
        if (validTotal) {
            allocations.forEach((item) => {
                item.allocated = amount * item.weight / 10000n;
                item.remainder = amount * item.weight % 10000n;
            });
            let remaining = amount - allocations.reduce((sum, item) => sum + item.allocated, 0n);
            const ordered = [...allocations].sort((a, b) => {
                if (a.remainder !== b.remainder) return a.remainder > b.remainder ? -1 : 1;
                return Number(a.unit || a.index) - Number(b.unit || b.index);
            });
            for (const item of ordered) {
                if (remaining <= 0n) break;
                item.allocated += 1n;
                remaining -= 1n;
            }
        }
        allocations.forEach((item) => {
            const input = $('[data-percentage]', item.row);
            const unit = $('[data-unit]', item.row);
            input.name = `allocations[${item.index}][percentage]`;
            input.setAttribute('aria-label', `Percentual da unidade ${item.index + 1}`);
            input.setCustomValidity(validTotal ? '' : 'Os percentuais devem ser positivos e somar exatamente 100%.');
            unit.name = `allocations[${item.index}][unit_id]`;
            unit.id = `unit-${item.index}`;
            $('label', item.row).htmlFor = unit.id;
            $('label', item.row).textContent = `Unidade do rateio ${item.index + 1}`;
            unit.setCustomValidity(item.unit && allocations.some((other) => other !== item && other.unit === item.unit) ? 'Cada unidade pode aparecer apenas uma vez no rateio.' : '');
            $('[data-row-amount]', item.row).textContent = validTotal ? formatMinor(item.allocated, currency) : '—';
            $('[data-remove-allocation]', item.row).disabled = rows.length === 1;
        });
        const percentage = `${total / 100n}${total % 100n ? ',' + (total % 100n).toString().padStart(2, '0') : ''}%`;
        $('[data-allocation-total]').textContent = percentage;
        $('[data-summary-percent]').textContent = percentage;
        $('[data-summary-units]').textContent = String(rows.length);
        $('[data-summary-amount]').textContent = formatMinor(amount, currency);
        $('[data-summary-currency]').textContent = currency;
        $('[data-currency-symbol]').textContent = currency === 'USD' ? 'US$' : 'R$';
        const feedback = $('[data-allocation-feedback]');
        feedback.classList.toggle('valid', validTotal && !duplicates);
        feedback.textContent = duplicates ? 'Selecione unidades diferentes.' : validTotal ? '100% distribuído. Rateio completo.' : `Distribuído: ${percentage}. A soma deve ser 100%.`;
        const unitCount = $$('option', $('[data-unit]', rows[0])).filter((option) => option.value).length;
        addButton.disabled = rows.length >= unitCount || rows.length >= 100;
        amountInput.setCustomValidity(amountInput.value && (amount <= 0n || amount > 99999999999n) ? 'Informe um valor maior que zero, até 999999999,99.' : '');
    }
    addButton.addEventListener('click', () => {
        if (!rowTemplate) return;
        const row = rowTemplate.cloneNode(true);
        $('[data-unit]', row).value = '';
        $('[data-percentage]', row).value = '';
        rowsContainer.append(row);
        refreshAllocation();
        $('[data-unit]', row).focus();
    });
    rowsContainer.addEventListener('click', (event) => {
        const button = event.target.closest('[data-remove-allocation]');
        if (!button || $$('.allocation-row', rowsContainer).length === 1) return;
        button.closest('.allocation-row').remove();
        refreshAllocation();
        addButton.focus();
    });
    expenseForm.addEventListener('input', (event) => {
        const input = event.target.closest('[data-percentage]');
        if (input && (decimalToMinor(input.value) ?? 0n) > 10000n) input.value = '100';
        refreshAllocation();
    });
    expenseForm.addEventListener('change', refreshAllocation);
    expenseForm.addEventListener('submit', () => {
        const button = $('[data-submit-expense]');
        button.disabled = true;
        button.textContent = 'Registrando despesa…';
    });
    refreshAllocation();
}
