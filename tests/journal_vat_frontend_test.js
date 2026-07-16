'use strict';

const assert = require('node:assert/strict');

const elements = {
    taxStatus: { value: 'taxable', addEventListener() {} },
    vatSummary: { style: {} },
    vatBaseAmount: { textContent: '' },
    vatAmount: { textContent: '' },
    vatTotalAmount: { textContent: '' },
    vatAmountRow: { style: {} },
    'entry-form': { addEventListener() {} },
};

let debitValue = '10,000.00';
let creditValue = '10,000.00';
const rows = [
    {
        querySelector(selector) {
            if (selector === '.account-id-input') return { value: '2' };
            if (selector === '.dr-input') return { value: debitValue };
            if (selector === '.cr-input') return { value: '' };
            return null;
        },
    },
    {
        querySelector(selector) {
            if (selector === '.account-id-input') return { value: '5' };
            if (selector === '.dr-input') return { value: '' };
            if (selector === '.cr-input') return { value: creditValue };
            return null;
        },
    },
];

global.window = global;
global.document = {
    getElementById(id) { return elements[id] || null; },
    querySelectorAll(selector) { return selector === '#lines-container tr' ? rows : []; },
};

require('../assets/js/journal-vat.js');

const accounts = [
    { id: 2, name: 'Accounts Receivable', category: 'Assets' },
    { id: 5, name: 'Service Revenue', category: 'Revenue' },
];

initJournalVat({ companyRegistered: true, rate: 0.12, mode: 'output', accounts });
assert.equal(elements.vatBaseAmount.textContent, '₱10,000.00');
assert.equal(elements.vatAmount.textContent, '₱1,200.00');
assert.equal(elements.vatTotalAmount.textContent, '₱11,200.00');

debitValue = '20,000.00';
creditValue = '20,000.00';
updateVatSummary();
assert.equal(elements.vatAmount.textContent, '₱2,400.00');
assert.equal(elements.vatTotalAmount.textContent, '₱22,400.00');

elements.taxStatus.value = 'non_taxable';
updateVatSummary();
assert.equal(elements.vatAmountRow.style.display, 'none');
assert.equal(elements.vatTotalAmount.textContent, '₱20,000.00');

initJournalVat({ companyRegistered: false, rate: 0.12, mode: 'output', accounts });
assert.equal(elements.vatSummary.style.display, 'none');
assert.equal(elements.vatAmount.textContent, '₱0.00');

console.log('PASS: live amount changes update base, VAT, and total');
console.log('PASS: Taxable to Non-Taxable hides VAT and resets total');
console.log('PASS: non-registered company hides the VAT summary');
