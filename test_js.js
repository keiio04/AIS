const accounts = [
    { id: "1", code: "1-110", name: "Accounts Receivable", category: "Assets" },
    { id: "4", code: "4-100", name: "Service Revenue", category: "Revenue" }
];

function _isCash(acc)       { return acc.category === 'Assets' && /cash|bank/i.test(acc.name); }
function _isReceivable(acc) { return /receivable/i.test(acc.name); }
function _isPayable(acc)    { return /payable/i.test(acc.name); }
function _isRevenue(acc)    { return acc.category === 'Revenue'; }
function _isExpense(acc)    { return acc.category === 'Expenses'; }

const lines = [
    { acc: accounts[0], dr: 10000, cr: 0 },
    { acc: accounts[1], dr: 0, cr: 10000 }
];

function _detectGJMismatch(lines) {
    if (!lines.length) return null;
    const cashDr  = lines.some(l => _isCash(l.acc) && l.dr > 0);
    const cashCr  = lines.some(l => _isCash(l.acc) && l.cr > 0);
    const hasRev  = lines.some(l => _isRevenue(l.acc));
    const hasExp  = lines.some(l => _isExpense(l.acc));
    const hasRec  = lines.some(l => _isReceivable(l.acc));
    const hasPay  = lines.some(l => _isPayable(l.acc));
    
    console.log({cashDr, cashCr, hasRev, hasExp, hasRec, hasPay});

    if (cashDr)              return { journal: 'Cash Receipts Journal' };
    if (cashCr)              return { journal: 'Cash Disbursements Journal' };
    if (hasRev && hasRec)    return { journal: 'Sales Journal' };
    if (hasExp && hasPay)    return { journal: 'Purchases Journal' };
    return null;
}

console.log(_detectGJMismatch(lines));
