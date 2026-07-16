<div id="vatSummary" style="display: none; max-width: 390px; margin: -0.5rem 0 1.5rem auto; padding: 0.9rem 1rem; border: 1px solid var(--border-color); border-radius: 8px; background: var(--bg-secondary); font-size: 0.9rem;">
    <div style="display:flex; justify-content:space-between; gap:1rem; margin-bottom:0.35rem;"><span>Base Amount</span><strong id="vatBaseAmount">&#8369;0.00</strong></div>
    <div id="vatAmountRow" style="display:flex; justify-content:space-between; gap:1rem; margin-bottom:0.35rem; color:var(--text-muted);"><span>+ <?= rtrim(rtrim(number_format(VAT_RATE * 100, 2), '0'), '.') ?>% VAT</span><strong id="vatAmount">&#8369;0.00</strong></div>
    <div style="display:flex; justify-content:space-between; gap:1rem; padding-top:0.5rem; border-top:1px solid var(--border-color);"><strong>Total Amount</strong><strong id="vatTotalAmount">&#8369;0.00</strong></div>
</div>
