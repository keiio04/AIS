/**
 * script.js — Global JavaScript for TALA-AIS
 * Handles: sidebar toggle, dark mode, dropdowns, lucide icons
 */

// ── Sidebar Main Toggle ──────────────────────────────────────
function toggleMainSidebar() {
    const sb = document.getElementById('mainSidebar');
    if (sb) {
        sb.classList.toggle('is-hidden');
        const isHidden = sb.classList.contains('is-hidden');
        localStorage.setItem('main_sidebar_hidden', isHidden);
        document.body.classList.toggle('sidebar-hidden', isHidden);

        // Resize charts after the sidebar CSS transition (300ms) finishes
        setTimeout(function() {
            if (typeof Chart !== 'undefined') {
                Object.values(Chart.instances).forEach(function(chart) {
                    chart.resize();
                });
            }
        }, 320);
    }
}

// ── Sidebar Section Collapse ─────────────────────────────────
function toggleSidebarSection(id) {
    const el = document.getElementById(id);
    if (el) {
        el.classList.toggle('is-collapsed');
        localStorage.setItem('sidebar_' + id, el.classList.contains('is-collapsed'));
    }
}

// ── Restore Sidebar States (runs immediately on page load) ───
(function restoreSidebarStates() {
    ['admin', 'biz_type'].forEach(function(id) {
        const el = document.getElementById(id);
        if (el && localStorage.getItem('sidebar_' + id) === 'true') {
            el.classList.add('is-collapsed');
        }
    });

    const modulesEl = document.querySelector('[id^="modules_"]');
    if (modulesEl && localStorage.getItem('sidebar_' + modulesEl.id) === 'true') {
        modulesEl.classList.add('is-collapsed');
    }

    const mainSb = document.getElementById('mainSidebar');
    if (mainSb && localStorage.getItem('main_sidebar_hidden') === 'true') {
        mainSb.classList.add('is-hidden');
        document.body.classList.add('sidebar-hidden');
    }
})();

// ── Dark Mode Toggle ─────────────────────────────────────────
function toggleDarkMode() {
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    if (isDark) {
        document.documentElement.removeAttribute('data-theme');
        localStorage.setItem('theme', 'light');
        document.getElementById('darkModeIcon').setAttribute('data-lucide', 'sun');
    } else {
        document.documentElement.setAttribute('data-theme', 'dark');
        localStorage.setItem('theme', 'dark');
        document.getElementById('darkModeIcon').setAttribute('data-lucide', 'moon');
    }
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

// ── Apply Saved Theme on Load ────────────────────────────────
(function applyTheme() {
    const saved = localStorage.getItem('theme');
    if (saved === 'dark') {
        document.documentElement.setAttribute('data-theme', 'dark');
        const icon = document.getElementById('darkModeIcon');
        if (icon) icon.setAttribute('data-lucide', 'moon');
    }
})();

// ── Dropdowns: Close when clicking outside ───────────────────
document.addEventListener('click', function(event) {
    const notifMenu   = document.getElementById('notifMenu');
    const profileMenu = document.getElementById('profileMenu');
    const notifBtn    = document.getElementById('notifDropdownContainer');
    const profileBtn  = document.querySelector('.user-profile-container');

    if (notifMenu && notifBtn && !notifBtn.contains(event.target)) {
        notifMenu.classList.add('hidden');
    }
    if (profileMenu && profileBtn && !profileBtn.contains(event.target)) {
        profileMenu.classList.add('hidden');
    }
});

// ── Sidebar: Auto-expand section with active item ────────────
(function autoExpandSidebar() {
    const activeSubitem = document.querySelector('.nav-subitem.active');
    if (activeSubitem) {
        const subitems = activeSubitem.closest('.nav-subitems');
        if (subitems) subitems.classList.remove('hidden');
    } else {
        const mods = document.getElementById('mods');
        if (mods) mods.classList.remove('hidden');
    }
})();

// ── Initialize Lucide Icons (after full DOM is ready) ────────
document.addEventListener('DOMContentLoaded', function() {
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
});
