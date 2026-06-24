        </main>
    </div> <!-- .main-wrapper -->
</div> <!-- .layout -->

<!-- Initialize Lucide Icons -->
<script>
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }

    // ── Dark Mode ──────────────────────────────────────────
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

    // Apply saved theme on load
    (function() {
        const saved = localStorage.getItem('theme');
        if (saved === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
            const icon = document.getElementById('darkModeIcon');
            if (icon) icon.setAttribute('data-lucide', 'moon');
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
    })();

    // ── Sidebar: Auto-expand section with active item ──────
    (function() {
        const activeSubitem = document.querySelector('.nav-subitem.active');
        if (activeSubitem) {
            // Expand the section containing the active item
            const subitems = activeSubitem.closest('.nav-subitems');
            if (subitems) {
                subitems.classList.remove('hidden');
            }
        } else {
            // On dashboard or no active subitem, expand Accounting Modules by default
            const mods = document.getElementById('mods');
            if (mods) mods.classList.remove('hidden');
        }
    })();

    // ── Dropdowns: Close when clicking outside ──────
    document.addEventListener('click', function(event) {
        const notifMenu = document.getElementById('notifMenu');
        const profileMenu = document.getElementById('profileMenu');
        const notifBtn = document.getElementById('notifDropdownContainer');
        const profileBtn = document.querySelector('.user-profile-container');
        
        if (notifMenu && notifBtn && !notifBtn.contains(event.target)) {
            notifMenu.classList.add('hidden');
        }
        if (profileMenu && profileBtn && !profileBtn.contains(event.target)) {
            profileMenu.classList.add('hidden');
        }
    });
</script>
</body>
</html>
