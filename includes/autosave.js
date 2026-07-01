/**
 * autosave.js
 * Saves and restores journal entry modal form data using localStorage.
 * Works with journal_entries, sales_journal, purchases_journal,
 * cash_receipts_journal, and cash_disbursements_journal.
 *
 * Usage: Include this script AFTER the main page script on any journal page.
 * Requires: window.AUTOSAVE_KEY to be set before including this script.
 * Requires: addLine() to be defined globally.
 * Requires: calcTotals() to be defined globally.
 */

(function () {
    const STORAGE_KEY = window.AUTOSAVE_KEY || 'journal_autosave';

    // --- SAVE ---
    function saveToStorage() {
        try {
            const form = document.getElementById('entry-form');
            if (!form) return;

            // Header fields
            const data = { header: {}, lines: [] };
            form.querySelectorAll('input:not([type=hidden]), select, textarea').forEach(el => {
                if (el.name && !el.name.includes('[]')) {
                    data.header[el.name] = el.value;
                }
            });

            // Line rows
            const rows = document.querySelectorAll('#lines-container tr');
            rows.forEach(row => {
                const line = {};
                row.querySelectorAll('input, select').forEach(el => {
                    if (el.name) line[el.name.replace('[]', '')] = el.value;
                });
                // store account select index separately
                const sel = row.querySelector('select[name="account_id[]"]');
                if (sel) line['account_id'] = sel.value;
                data.lines.push(line);
            });

            localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
        } catch (e) { /* silently fail */ }
    }

    // --- RESTORE ---
    function restoreFromStorage() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) return;
            const data = JSON.parse(raw);
            if (!data) return;

            const form = document.getElementById('entry-form');
            if (!form) return;

            // Restore header fields
            Object.entries(data.header || {}).forEach(([name, value]) => {
                const el = form.querySelector(`[name="${name}"]`);
                if (el) el.value = value;
            });

            // Restore lines
            if (data.lines && data.lines.length > 0) {
                // Clear existing rows
                const container = document.getElementById('lines-container');
                container.innerHTML = '';
                window.lineCount = 0;

                data.lines.forEach(lineData => {
                    addLine(); // creates a new row
                    const lastRow = container.lastElementChild;

                    // account_id select
                    const sel = lastRow.querySelector('select[name="account_id[]"]');
                    if (sel && lineData.account_id) sel.value = lineData.account_id;

                    // text/number inputs
                    lastRow.querySelectorAll('input').forEach(inp => {
                        const key = inp.name.replace('[]', '');
                        if (lineData[key] !== undefined) inp.value = lineData[key];
                    });
                });

                if (typeof calcTotals === 'function') calcTotals();
                if (typeof updateRemoveButtons === 'function') updateRemoveButtons();
            }

            showDraftBanner();
        } catch (e) { /* silently fail */ }
    }

    // --- CLEAR ---
    function clearStorage() {
        localStorage.removeItem(STORAGE_KEY);
        hideDraftBanner();
    }

    // --- DRAFT BANNER ---
    function showDraftBanner() {
        if (document.getElementById('autosave-banner')) return;
        const banner = document.createElement('div');
        banner.id = 'autosave-banner';
        banner.style.cssText = `
            position: fixed; bottom: 1.25rem; right: 1.25rem; z-index: 9999;
            background: var(--bg-secondary, #1e293b);
            border: 1px solid var(--border-color, #334155);
            border-radius: 10px; padding: 0.6rem 1rem;
            display: flex; align-items: center; gap: 0.6rem;
            font-size: 0.82rem; color: var(--text-secondary, #94a3b8);
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        `;
        banner.innerHTML = `
            <span style="color: #22c55e; font-size: 1rem;">●</span>
            <span id="autosave-label">Draft restored</span>
            <button onclick="clearDraft()" style="margin-left:0.5rem; background:none; border:none; cursor:pointer; color:var(--danger-color,#ef4444); font-size:0.8rem; padding:0;">Discard</button>
        `;
        document.body.appendChild(banner);
    }

    function hideDraftBanner() {
        const b = document.getElementById('autosave-banner');
        if (b) b.remove();
    }

    function updateSaveLabel() {
        const label = document.getElementById('autosave-label');
        if (!label) return;
        const now = new Date();
        const hh = String(now.getHours()).padStart(2, '0');
        const mm = String(now.getMinutes()).padStart(2, '0');
        const ss = String(now.getSeconds()).padStart(2, '0');
        label.textContent = `Draft saved at ${hh}:${mm}:${ss}`;
    }

    // --- PUBLIC ---
    window.clearDraft = function () {
        clearStorage();
        // Also reset form
        const container = document.getElementById('lines-container');
        if (container) {
            container.innerHTML = '';
            window.lineCount = 0;
            if (typeof addLine === 'function') { addLine(); addLine(); }
            if (typeof calcTotals === 'function') calcTotals();
        }
        const form = document.getElementById('entry-form');
        if (form) form.reset();
    };

    // Patch openModal to restore draft
    const _origOpen = window.openModal;
    window.openModal = function () {
        if (typeof _origOpen === 'function') _origOpen();
        restoreFromStorage();
    };

    // Patch closeModal to NOT clear (preserve draft)
    // (no-op, we just let it close without clearing)

    // Clear on successful save (form submit)
    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('entry-form');
        if (form) {
            form.addEventListener('submit', function () {
                clearStorage();
            });
        }
    });

    // Autosave every 5 seconds when modal is visible
    setInterval(function () {
        const modal = document.getElementById('entryModal');
        if (!modal) return;
        const visible = modal.style.display !== 'none' && !modal.classList.contains('hidden');
        if (visible) {
            saveToStorage();
            updateSaveLabel();
            showDraftBanner();
        }
    }, 5000);

    // Also save on any input/change inside the modal
    document.addEventListener('input', function (e) {
        const modal = document.getElementById('entryModal');
        if (!modal) return;
        if (modal.contains(e.target)) {
            saveToStorage();
        }
    });
    document.addEventListener('change', function (e) {
        const modal = document.getElementById('entryModal');
        if (!modal) return;
        if (modal.contains(e.target)) {
            saveToStorage();
        }
    });
})();
