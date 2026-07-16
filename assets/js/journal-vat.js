(function () {
    'use strict';

    let config = null;

    function parseAmount(value) {
        const number = Number(String(value || '').replace(/,/g, ''));
        return Number.isFinite(number) && number >= 0 ? number : 0;
    }

    function money(value) {
        return '\u20b1' + value.toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function isPurchaseBase(account) {
        return (account.category === 'Expenses' || account.category === 'Assets')
            && !/cash|bank|receivable|input vat|output vat/i.test(account.name);
    }

    function getBaseAmounts() {
        let totalDebit = 0;
        let inputBase = 0;
        let outputBase = 0;
        document.querySelectorAll('#lines-container tr').forEach(function (row) {
            const accountId = row.querySelector('.account-id-input')?.value;
            const debit = parseAmount(row.querySelector('.dr-input')?.value);
            const credit = parseAmount(row.querySelector('.cr-input')?.value);
            totalDebit += debit;
            const account = config.accounts.find(function (item) {
                return String(item.id) === String(accountId);
            });
            if (!account) return;
            if (account.category === 'Revenue') outputBase += credit;
            if (isPurchaseBase(account)) inputBase += debit;
        });
        return { totalDebit, inputBase, outputBase };
    }

    function selectedStatus() {
        if (!config.companyRegistered) return 'non_taxable';
        return document.getElementById('taxStatus')?.value === 'non_taxable'
            ? 'non_taxable'
            : 'taxable';
    }

    function update() {
        if (!config) return;
        const panel = document.getElementById('vatSummary');
        if (!panel) return;

        const amounts = getBaseAmounts();
        const taxable = selectedStatus() === 'taxable';
        let base = amounts.totalDebit;
        if (taxable) {
            if (config.mode === 'output') base = amounts.outputBase;
            else if (config.mode === 'input') base = amounts.inputBase;
            else base = amounts.outputBase > 0 ? amounts.outputBase : amounts.inputBase;
        }
        base = Math.round((base + Number.EPSILON) * 100) / 100;
        const vat = taxable ? Math.round((base * config.rate + Number.EPSILON) * 100) / 100 : 0;
        const total = Math.round((base + vat + Number.EPSILON) * 100) / 100;

        document.getElementById('vatBaseAmount').textContent = money(base);
        document.getElementById('vatAmount').textContent = money(vat);
        document.getElementById('vatTotalAmount').textContent = money(total);
        const vatRow = document.getElementById('vatAmountRow');
        if (vatRow) vatRow.style.display = taxable ? 'flex' : 'none';
        panel.style.display = config.companyRegistered ? 'block' : 'none';
    }

    window.initJournalVat = function (options) {
        config = {
            companyRegistered: Boolean(options.companyRegistered),
            rate: Number(options.rate) || 0,
            mode: options.mode || 'auto',
            accounts: Array.isArray(options.accounts) ? options.accounts : []
        };
        const select = document.getElementById('taxStatus');
        if (select) select.addEventListener('change', update);
        const form = document.getElementById('entry-form');
        if (form) form.addEventListener('input', function (event) {
            if (event.target?.id === 'entitySearchInput') {
                const vendorInput = document.getElementById('vendorNameInput');
                if (vendorInput) vendorInput.value = event.target.value;
            }
            update();
        });
        update();
    };

    window.updateVatSummary = update;

    window.setJournalVatStatus = function (status) {
        const select = document.getElementById('taxStatus');
        if (select) select.value = status === 'non_taxable' ? 'non_taxable' : 'taxable';
        update();
    };
})();
