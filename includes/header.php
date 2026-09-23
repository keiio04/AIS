<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/access_control.php';
require_once __DIR__ . '/notifications.php';

// Calculate initials
$userName = $_SESSION['user_name'] ?? 'Admin';
$userRole = $_SESSION['user_role'] ?? 'Super Admin';
$parts = explode(' ', $userName);
$initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));

// Notifications for the bell (the Admin Panel has no bell, so skip the queries there)
$notifUnread = 0;
$notifItems = [];
if (!defined('IS_ADMIN_PANEL') && !empty($_SESSION['user_id'])) {
    $notifUnread = notif_unread_count(get_db(), (int)$_SESSION['user_id']);
    $notifItems  = notif_recent(get_db(), (int)$_SESSION['user_id'], 10);
}

// Get active company info if needed (for sidebar display)
$activeCompanyId = $_SESSION['active_company_id'] ?? null;
$activeCompanyName = null;
$activeCompanyType = 'Service';
if ($activeCompanyId) {
    $db = get_db();
    $stmt = $db->prepare("SELECT name, business_type, tax_registered, tax_type FROM companies WHERE id = ?");
    $stmt->bind_param('i', $activeCompanyId);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    if ($res) {
        $activeCompanyName = $res['name'];
        $activeCompanyType = $res['business_type'];
        // Load tax info into session if not yet set
        if (!isset($_SESSION['company_tax_type'])) {
            $_SESSION['company_tax_registered'] = $res['tax_registered'];
            $_SESSION['company_tax_type'] = $res['tax_type'];
        }
    }
}
$companyTaxType = $_SESSION['company_tax_type'] ?? null;
$companyTaxRegistered = $_SESSION['company_tax_registered'] ?? 0;

$current_page = basename($_SERVER['PHP_SELF'], '.php');

// Simple page titles for Topbar
$pageNames = [
    'dashboard' => in_array($userRole, ['Instructor', 'Admin']) ? 'Reports/Outputs' : 'Dashboard',
    'journal_entries' => 'Journal Entries',
    'sales_journal' => 'Sales Journal',
    'purchases_journal' => 'Purchases Journal',
    'cash_receipts_journal' => 'Cash Receipts Journal',
    'cash_disbursements_journal' => 'Cash Disbursements Journal',
    'general_ledger' => 'General Ledger',
    'subsidiary_ledger' => 'Subsidiary Ledger',
    'trial_balance' => 'Trial Balance',
    'financial_statements' => 'Financial Statements',
    'chart_of_accounts' => 'Chart of Accounts',
    'company_setup' => 'Company Setup',
    'trash_bin' => 'Trash Bin',
    'customers' => 'Customers',
    'suppliers' => 'Suppliers',
    'employees' => 'Employees',
    'student_output' => 'Student Output Review',
    'system_notices' => 'System Notices',
];
$pageTitle = $pageNames[$current_page] ?? ucfirst($current_page);

// Top search bar: on pages that can filter their own list, search stays on the same page;
// everywhere else it falls back to Journal Entries (as before).
$searchPages = ['journal_entries', 'sales_journal', 'purchases_journal', 'cash_receipts_journal', 'cash_disbursements_journal', 'chart_of_accounts', 'employees', 'company_setup'];
$searchOnThisPage = in_array($current_page, $searchPages, true);
$searchAction = BASE_URL . 'pages/' . ($searchOnThisPage ? $current_page : 'journal_entries') . '.php';
$searchValue = $searchOnThisPage ? trim($_GET['search'] ?? '') : '';
$searchPlaceholders = [
    'chart_of_accounts' => 'Search by code or name…',
    'employees' => 'Search employees…',
    'company_setup' => 'Search companies…',
];
$searchPlaceholder = $searchPlaceholders[$current_page]
    ?? (in_array($current_page, ['journal_entries', 'sales_journal', 'purchases_journal', 'cash_receipts_journal', 'cash_disbursements_journal'], true)
        ? 'Search ref no., name, account, amount…' : 'Search accounts, entries…');

// Pages where the top search bar is hidden (these pages use their own in-page search, or none at all).
$hideTopSearchPages = ['dashboard', 'chart_of_accounts', 'trial_balance', 'financial_statements', 'employees', 'customers', 'suppliers', 'system_notices', 'instructor_dashboard', 'student_output', 'users', 'assignments', 'companies'];
$showTopSearch = !in_array($current_page, $hideTopSearchPages, true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?> - TALA-AIS</title>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
<?php if (!defined('IS_ADMIN_PANEL')): ?>
<style>
.notif-msg { display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; white-space: pre-line; word-break: break-word; }
.notif-msg.open { display: block; -webkit-line-clamp: unset; overflow: visible; }
.notif-item:hover { background: var(--bg-tertiary) !important; }
</style>
<?php endif; ?>
<!-- Lucide Icons -->
<script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>

<div class="layout">
    <!-- SIDEBAR -->
    <div class="sidebar" id="mainSidebar">
        <div class="sidebar-header">
            <div class="logo-container" style="flex-direction: column; align-items: flex-start; gap: 0.25rem;">
                <div class="flex items-center gap-2">
                    <div class="star-glow-badge" style="background: linear-gradient(135deg, #0ea5e9, #3b82f6, #4f46e5); padding: 6px; border-radius: 10px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 15px rgba(59, 130, 246, 0.5), inset 0 2px 4px rgba(255,255,255,0.3); position: relative; overflow: hidden;">
                        <div style="position: absolute; top: 0; left: 0; right: 0; height: 50%; background: linear-gradient(to bottom, rgba(255,255,255,0.25), transparent);"></div>
                        <i data-lucide="star" color="#ffffff" stroke-width="2.5" fill="#ffffff" style="width: 20px; height: 20px; z-index: 1;"></i>
                    </div>
                    <div>
                        <?php if (defined('IS_ADMIN_PANEL')): ?>
                        <div style="font-size: 1.05rem; font-weight: 800; background: linear-gradient(to right, #ffffff, #93c5fd); -webkit-background-clip: text; -webkit-text-fill-color: transparent; letter-spacing: -0.02em; line-height: 1.2;">
                            Admin Panel
                        </div>
                        <?php elseif ($activeCompanyName): ?>
                        <div style="font-size: 1.05rem; font-weight: 800; background: linear-gradient(to right, #ffffff, #93c5fd); -webkit-background-clip: text; -webkit-text-fill-color: transparent; letter-spacing: -0.02em; line-height: 1.2;">
                            <?= htmlspecialchars($activeCompanyName) ?>
                        </div>
                        <?php else: ?>
                        <div style="font-size: 1.05rem; font-weight: 800; background: linear-gradient(to right, #ffffff, #93c5fd); -webkit-background-clip: text; -webkit-text-fill-color: transparent; letter-spacing: -0.02em; line-height: 1.2;">
                            TALA-AIS
                        </div>
                        <?php endif; ?>
                        <div style="font-size: 0.7rem; color: #60a5fa; font-weight: 600; letter-spacing: 0.03em; line-height: 1;">
                            TALA-AIS
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <nav class="sidebar-nav">
            <?php if (!defined('IS_ADMIN_PANEL')): ?>
            <div style="padding: 0 0.75rem; margin-bottom: 0.5rem;">
                <a href="<?= BASE_URL ?>pages/dashboard.php" class="nav-item <?= in_array($current_page, ['dashboard','instructor_dashboard','student_output']) ? 'active' : '' ?>" <?= in_array($userRole, ['Instructor', 'Admin']) ? '' : ($activeCompanyId ? '' : 'style="pointer-events:none;opacity:0.5;"') ?>>
                    <?php if (in_array($userRole, ['Instructor', 'Admin'])): ?>
                    <i data-lucide="graduation-cap" style="width: 17px; height: 17px;"></i>
                    <span>Reports/Outputs</span>
                    <?php else: ?>
                    <i data-lucide="layout-dashboard" style="width: 17px; height: 17px;"></i>
                    <span>Dashboard</span>
                    <?php endif; ?>
                </a>
            </div>
            <?php endif; ?>

            <?php if (defined('IS_ADMIN_PANEL')): ?>
            <div style="padding: 0 0.75rem; margin-bottom: 0.5rem;">
                <?php
                $adminSidebarItems = [
                    'dashboard'   => ['icon' => 'layout-dashboard', 'label' => 'Dashboard',     'href' => 'dashboard.php'],
                    'users'       => ['icon' => 'users',            'label' => 'Users',          'href' => 'users.php'],
                    'companies'   => ['icon' => 'building-2',       'label' => 'Companies',      'href' => 'companies.php'],
                    'assignments' => ['icon' => 'link',             'label' => 'Assignments',    'href' => 'assignments.php'],
    'system_notices' => ['icon' => 'megaphone',     'label' => 'System Notices', 'href' => 'system_notices.php'],
                    'logs'        => ['icon' => 'scroll-text',      'label' => 'Activity Logs',  'href' => 'logs.php'],
                ];
                foreach ($adminSidebarItems as $key => $item):
                    $isActive = ($current_page === $key);
                ?>
                <a href="<?= $item['href'] ?>" class="nav-item <?= $isActive ? 'active' : '' ?>" style="margin-bottom: 2px;">
                    <i data-lucide="<?= $item['icon'] ?>" style="width: 17px; height: 17px;"></i>
                    <span><?= $item['label'] ?></span>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!defined('IS_ADMIN_PANEL') && ($userRole !== 'Instructor' || is_view_only())): ?>
            <div class="nav-section" style="pointer-events: <?= $activeCompanyId ? 'auto' : 'none' ?>; opacity: <?= $activeCompanyId ? '1' : '0.5' ?>;">
                <div class="nav-section-title" onclick="toggleSidebarSection('biz_type')">
                    <div class="flex items-center gap-2"><i data-lucide="building" style="width: 15px; height: 15px;"></i><span>Business Type</span></div>
                    <i data-lucide="chevron-down" style="width: 13px; height: 13px;"></i>
                </div>
                <div id="biz_type" class="nav-subitems collapse-smooth">
                    
                    <?php 
                    $bizTypes = ['Service'];
                    foreach($bizTypes as $bt): 
                        if ($activeCompanyType === $bt):
                    ?>
                    <!-- Active Business Type with Modules -->
                    <div style="padding: 0.25rem 0.5rem; margin-bottom: 0.5rem;">
                        <div class="flex justify-between items-center" onclick="toggleSidebarSection('modules_<?= $bt ?>')" style="margin-bottom: 0.5rem; font-size: 0.85rem; font-weight: 600; color: var(--sidebar-text); cursor: pointer;">
                            <div class="flex items-center gap-2">
                                <div style="width: 6px; height: 6px; border-radius: 50%; background: #eab308;"></div>
                                <?= $bt ?>
                            </div>
                            <div class="flex items-center gap-2">
                                <i data-lucide="chevron-down" style="width: 14px; height: 14px; color: var(--sidebar-muted);"></i>
                            </div>
                        </div>
                        
                        <div id="modules_<?= $bt ?>" class="collapse-smooth" style="padding-left: 0.5rem; margin-top: 0.5rem;">
                            <!-- Journals Dropdown -->
                            <div class="flex justify-between items-center nav-subitem" onclick="toggleSidebarSection('journals_<?= $bt ?>')" style="padding: 0.25rem 0.5rem; cursor: pointer; border-radius: 6px; margin-bottom: 0.25rem; <?= in_array($current_page, ['journal_entries','sales_journal','purchases_journal','cash_receipts_journal','cash_disbursements_journal']) ? 'background:rgba(59,130,246,0.1);color:#3b82f6;' : '' ?>">
                                <div class="flex items-center gap-2"><i data-lucide="book-marked" style="width: 13px; height: 13px;"></i><span>Journal Entries</span></div>
                                <i data-lucide="chevron-down" style="width: 13px; height: 13px; color: var(--sidebar-muted);"></i>
                            </div>
                            <div id="journals_<?= $bt ?>" class="collapse-smooth <?= in_array($current_page, ['journal_entries','sales_journal','purchases_journal','cash_receipts_journal','cash_disbursements_journal']) ? '' : 'is-collapsed' ?>" style="padding-left: 1.5rem; margin-bottom: 0.5rem;">
                                <a href="<?= BASE_URL ?>pages/journal_entries.php" class="nav-subitem <?= $current_page==='journal_entries'?'active':'' ?>" style="padding: 0.25rem 0.5rem; margin-bottom: 2px;">
                                    <span style="font-size: 0.75rem;">General</span>
                                </a>
                                <a href="<?= BASE_URL ?>pages/sales_journal.php" class="nav-subitem <?= $current_page==='sales_journal'?'active':'' ?>" style="padding: 0.25rem 0.5rem; margin-bottom: 2px;">
                                    <span style="font-size: 0.75rem;">Sales</span>
                                </a>
                                <a href="<?= BASE_URL ?>pages/purchases_journal.php" class="nav-subitem <?= $current_page==='purchases_journal'?'active':'' ?>" style="padding: 0.25rem 0.5rem; margin-bottom: 2px;">
                                    <span style="font-size: 0.75rem;">Purchases</span>
                                </a>
                                <a href="<?= BASE_URL ?>pages/cash_receipts_journal.php" class="nav-subitem <?= $current_page==='cash_receipts_journal'?'active':'' ?>" style="padding: 0.25rem 0.5rem; margin-bottom: 2px;">
                                    <span style="font-size: 0.75rem;">Receipts</span>
                                </a>
                                <a href="<?= BASE_URL ?>pages/cash_disbursements_journal.php" class="nav-subitem <?= $current_page==='cash_disbursements_journal'?'active':'' ?>" style="padding: 0.25rem 0.5rem; margin-bottom: 2px;">
                                    <span style="font-size: 0.75rem;">Disbursements</span>
                                </a>
                            </div>
                            <!-- Ledgers Dropdown -->
                            <div class="flex justify-between items-center nav-subitem" onclick="toggleSidebarSection('ledgers_<?= $bt ?>')" style="padding: 0.25rem 0.5rem; cursor: pointer; border-radius: 6px; margin-bottom: 0.25rem; <?= in_array($current_page, ['general_ledger', 'subsidiary_ledger']) ? 'background:rgba(59,130,246,0.1);color:#3b82f6;' : '' ?>">
                                <div class="flex items-center gap-2"><i data-lucide="layers" style="width: 13px; height: 13px;"></i><span>Ledger</span></div>
                                <i data-lucide="chevron-down" style="width: 13px; height: 13px; color: var(--sidebar-muted);"></i>
                            </div>
                            <div id="ledgers_<?= $bt ?>" class="collapse-smooth <?= in_array($current_page, ['general_ledger', 'subsidiary_ledger']) ? '' : 'is-collapsed' ?>" style="padding-left: 1.5rem; margin-bottom: 0.5rem;">
                                <a href="<?= BASE_URL ?>pages/general_ledger.php" class="nav-subitem <?= $current_page==='general_ledger'?'active':'' ?>" style="padding: 0.25rem 0.5rem; margin-bottom: 2px;">
                                    <span style="font-size: 0.75rem;">General Ledger</span>
                                </a>
                                <a href="<?= BASE_URL ?>pages/subsidiary_ledger.php" class="nav-subitem <?= $current_page==='subsidiary_ledger'?'active':'' ?>" style="padding: 0.25rem 0.5rem; margin-bottom: 2px;">
                                    <span style="font-size: 0.75rem;">Subsidiary Ledger</span>
                                </a>
                            </div>
                            <a href="<?= BASE_URL ?>pages/trial_balance.php" class="nav-subitem <?= $current_page==='trial_balance'?'active':'' ?>" style="padding: 0.25rem 0.5rem;">
                                <div class="flex items-center gap-2"><i data-lucide="scale" style="width: 13px; height: 13px;"></i><span>Trial Balance</span></div>
                            </a>
                            <a href="<?= BASE_URL ?>pages/financial_statements.php" class="nav-subitem <?= $current_page==='financial_statements'?'active':'' ?>" style="padding: 0.25rem 0.5rem;">
                                <div class="flex items-center gap-2"><i data-lucide="bar-chart-3" style="width: 13px; height: 13px;"></i><span>Financial Statements</span></div>
                            </a>
                        </div>
                    </div>
                    <?php else: ?>
                    <!-- Inactive Business Type -->
                    <div class="flex justify-between items-center" style="padding: 0.5rem; font-size: 0.85rem; color: var(--text-muted); opacity: 0.6;">
                        <div class="flex items-center gap-2">
                            <div style="width: 6px; height: 6px; border-radius: 50%; background: #d1d5db;"></div>
                            <?= $bt ?>
                        </div>
                        <span style="font-size: 0.65rem; background: #f3f4f6; color: #6b7280; padding: 2px 6px; border-radius: 99px;">Soon</span>
                    </div>
                    <?php endif; endforeach; ?>

                </div>
            </div>
            <?php endif; ?>

            <?php if (!defined('IS_ADMIN_PANEL') && (in_array($userRole, ['Admin', 'Student']) || ($userRole === 'Instructor' && is_view_only()))): ?>
            <div class="nav-section">
                <div class="nav-section-title" onclick="toggleSidebarSection('setup')">
                    <span>Setup & Accounts</span>
                    <i data-lucide="chevron-down" style="width: 13px; height: 13px;"></i>
                </div>
                <div id="setup" class="nav-subitems collapse-smooth">
                    <a href="<?= BASE_URL ?>pages/company_setup.php" class="nav-subitem <?= $current_page==='company_setup'?'active':'' ?>">
                        <div class="flex items-center gap-2"><i data-lucide="building-2" style="width: 15px; height: 15px;"></i><span>Company Setup</span></div>
                    </a>
                    <a href="<?= BASE_URL ?>pages/chart_of_accounts.php" class="nav-subitem <?= $current_page==='chart_of_accounts'?'active':'' ?>">
                        <div class="flex items-center gap-2"><i data-lucide="book-open" style="width: 15px; height: 15px;"></i><span>Chart of Accounts</span></div>
                    </a>
                    <!-- Card List Dropdown -->
                    <div class="flex justify-between items-center nav-subitem" onclick="toggleSidebarSection('cardlist')" style="padding: 0.25rem 0.5rem; cursor: pointer; border-radius: 6px; margin-bottom: 0.25rem; <?= in_array($current_page, ['customers','suppliers','employees']) ? 'background:rgba(59,130,246,0.1);color:#3b82f6;' : '' ?>">
                        <div class="flex items-center gap-2"><i data-lucide="id-card" style="width: 15px; height: 15px;"></i><span>Card List</span></div>
                        <i data-lucide="chevron-down" style="width: 13px; height: 13px; color: var(--sidebar-muted);"></i>
                    </div>
                    <div id="cardlist" class="collapse-smooth <?= in_array($current_page, ['customers','suppliers','employees']) ? '' : 'is-collapsed' ?>" style="padding-left: 1.5rem; margin-bottom: 0.5rem;">
                        <a href="<?= BASE_URL ?>pages/customers.php" class="nav-subitem <?= $current_page==='customers'?'active':'' ?>" style="padding: 0.25rem 0.5rem; margin-bottom: 2px;">
                            <div class="flex items-center gap-2"><i data-lucide="users" style="width: 13px; height: 13px;"></i><span style="font-size: 0.75rem;">Customers</span></div>
                        </a>
                        <a href="<?= BASE_URL ?>pages/suppliers.php" class="nav-subitem <?= $current_page==='suppliers'?'active':'' ?>" style="padding: 0.25rem 0.5rem; margin-bottom: 2px;">
                            <div class="flex items-center gap-2"><i data-lucide="truck" style="width: 13px; height: 13px;"></i><span style="font-size: 0.75rem;">Suppliers</span></div>
                        </a>
                        <a href="<?= BASE_URL ?>pages/employees.php" class="nav-subitem <?= $current_page==='employees'?'active':'' ?>" style="padding: 0.25rem 0.5rem; margin-bottom: 2px;">
                            <div class="flex items-center gap-2"><i data-lucide="user-round" style="width: 13px; height: 13px;"></i><span style="font-size: 0.75rem;">Employees</span></div>
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>


        </nav>

        <?php if ($userRole === 'Admin' && !defined('IS_ADMIN_PANEL')): ?>
        <div style="padding: 0 0.75rem; margin-bottom: 0.5rem;">
            <a href="<?= BASE_URL ?>admin/dashboard.php" class="nav-item" style="width: 100%; background: rgba(59,130,246,0.1); color: #60a5fa; justify-content: center; text-decoration: none;">
                <i data-lucide="settings" style="width: 15px; height: 15px;"></i>
                <span>Admin Panel</span>
            </a>
        </div>
        <?php endif; ?>



        <?php if (!defined('IS_ADMIN_PANEL') && ($userRole !== 'Instructor' || is_view_only())): ?>
        <div style="padding: 0 0.75rem; padding-top: 0.5rem; pointer-events: <?= $activeCompanyId ? 'auto' : 'none' ?>; opacity: <?= $activeCompanyId ? '1' : '0.5' ?>;">
            <a href="<?= BASE_URL ?>pages/trash_bin.php" class="nav-item <?= $current_page==='trash_bin'?'active':'' ?>" style="margin-bottom: 0;">
                <i data-lucide="trash-2" style="width: 17px; height: 17px;"></i>
                <span>Trash Bin</span>
            </a>
        </div>
        <?php endif; ?>

        <div class="sidebar-footer">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2" style="color: var(--sidebar-muted); font-size: 0.8rem; min-width: 0;">
                    <div style="width: 28px; height: 28px; border-radius: 50%; background: linear-gradient(135deg, #3b82f6, #1d4ed8); display: flex; align-items: center; justify-content: center; font-size: 0.7rem; font-weight: 700; color: #fff; flex-shrink: 0;">
                        <?= htmlspecialchars($initials) ?>
                    </div>
                    <div style="overflow: hidden;">
                        <div style="color: var(--sidebar-text); font-weight: 500; font-size: 0.8rem; line-height: 1.2; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= htmlspecialchars($userName) ?></div>
                        <div style="font-size: 0.7rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= htmlspecialchars($userRole) ?></div>
                    </div>
                </div>
                <a href="<?= BASE_URL ?>auth/logout.php" class="icon-btn" style="color: var(--sidebar-muted);" title="Sign Out">
                    <i data-lucide="log-out" style="width: 15px; height: 15px;"></i>
                </a>
            </div>
        </div>
        
        <script>
        (function() {
            var sidebarNav = document.querySelector('.sidebar-nav');
            if (!sidebarNav) return;

            // Restore scroll position saved before the last navigation
            var savedScroll = sessionStorage.getItem('sidebarScrollPos');
            if (savedScroll !== null) {
                sidebarNav.scrollTop = parseInt(savedScroll, 10);
            }

            // Keep saving as the user scrolls the sidebar
            sidebarNav.addEventListener('scroll', function() {
                sessionStorage.setItem('sidebarScrollPos', sidebarNav.scrollTop);
            });

            // Also save right before a sidebar link is clicked, in case the
            // scroll event above hasn't fired yet for the final position
            sidebarNav.querySelectorAll('a[href]').forEach(function(link) {
                link.addEventListener('click', function() {
                    sessionStorage.setItem('sidebarScrollPos', sidebarNav.scrollTop);
                });
            });
        })();
        </script>
        <script src="<?= BASE_URL ?>assets/js/script.js"></script>
    </div>

    <!-- Mobile Sidebar Backdrop -->
    <div class="sidebar-backdrop" id="sidebarBackdrop" onclick="toggleMainSidebar()"></div>

    <!-- MAIN WRAPPER -->
    <div class="main-wrapper">
        <!-- TOPBAR -->
        <div class="topbar">
            <div class="topbar-left" style="display: flex; align-items: center;">
                <button class="icon-btn" onclick="toggleMainSidebar()" title="Toggle Sidebar" style="margin-right: 1rem;">
                    <i data-lucide="menu" style="width: 20px; height: 20px;"></i>
                </button>
                <div class="breadcrumb" style="font-size: 1.1rem; font-weight: 600; display: flex; align-items: center; gap: 0.6rem;">
                <span class="breadcrumb-current"><?= htmlspecialchars($pageTitle) ?></span>
                <?php if ($companyTaxRegistered && $companyTaxType): ?>
                  <?php if ($companyTaxType === 'VAT'): ?>
                    <span style="font-size: 0.65rem; font-weight: 700; color: #1e40af; background: #dbeafe; padding: 2px 8px; border-radius: 99px; letter-spacing: 0.04em; border: 1px solid #bfdbfe;">VAT</span>
                  <?php elseif ($companyTaxType === 'Percentage Tax'): ?>
                    <span style="font-size: 0.65rem; font-weight: 700; color: #92400e; background: #fef3c7; padding: 2px 8px; border-radius: 99px; letter-spacing: 0.04em; border: 1px solid #fde68a;">% TAX</span>
                  <?php endif; ?>
                <?php endif; ?>
            </div>
            </div>

            <div class="topbar-search" style="position: relative;">
                <?php if ($showTopSearch): ?>
                <form action="<?= $searchAction ?>" method="GET" style="margin: 0;">
                    <i data-lucide="search" class="topbar-search-icon" style="width: 14px; height: 14px; position: absolute; left: 0.875rem; top: 50%; transform: translateY(-50%); color: var(--text-muted);"></i>
                    <input type="text" name="search" class="form-control" placeholder="<?= htmlspecialchars($searchPlaceholder) ?>" value="<?= htmlspecialchars($searchValue) ?>" style="padding-left: 2.5rem; border-radius: 999px; height: 36px; font-size: 0.825rem; background-color: var(--bg-tertiary); border: 1px solid var(--border-color);">
                </form>
                <?php endif; ?>
            </div>

            <div class="topbar-actions">
                <button class="icon-btn" id="darkModeBtn" title="Toggle Dark/Light Mode" onclick="toggleDarkMode()">
                    <i data-lucide="sun" style="width: 18px; height: 18px;" id="darkModeIcon"></i>
                </button>
                <div class="topbar-divider" style="width: 1px; height: 20px; background: var(--border-color); margin: 0 0.25rem;"></div>
                
                <?php if (!defined('IS_ADMIN_PANEL')): ?>
                <!-- Notifications Dropdown -->
                <div style="position: relative;" id="notifDropdownContainer">
                    <button class="icon-btn" style="position: relative;" onclick="toggleNotifMenu(event)" title="Notifications" aria-label="Notifications">
                        <i data-lucide="bell" style="width: 18px; height: 18px;"></i>
                        <span id="notifBadge" style="position: absolute; top: 0; right: 0; min-width: 14px; height: 14px; padding: 0 3px; border-radius: 99px; background: #ef4444; color: #fff; font-size: 0.6rem; font-weight: 700; line-height: 14px; text-align: center; border: 2px solid var(--bg-secondary); box-sizing: content-box; <?= $notifUnread > 0 ? '' : 'display: none;' ?>"><?= $notifUnread > 99 ? '99+' : (int)$notifUnread ?></span>
                    </button>
                    <div id="notifMenu" class="hidden" style="position: absolute; right: 0; top: 110%; width: 340px; max-width: calc(100vw - 2rem); background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: var(--radius-md); box-shadow: var(--shadow-lg); z-index: 50; overflow: hidden;">
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1rem; border-bottom: 1px solid var(--border-color);">
                            <h4 style="margin: 0; font-size: 0.875rem;">Notifications</h4>
                            <button type="button" id="notifMarkAll" onclick="notifMarkAll()" style="background: none; border: none; cursor: pointer; color: var(--primary-color); font-size: 0.75rem; font-weight: 600; <?= $notifUnread > 0 ? '' : 'display: none;' ?>">Mark all as read</button>
                        </div>
                        <div style="max-height: 360px; overflow-y: auto;">
                            <?php if (empty($notifItems)): ?>
                            <div style="font-size: 0.8125rem; color: var(--text-muted); text-align: center; padding: 2rem 1rem;">No notifications yet</div>
                            <?php else: foreach ($notifItems as $notif):
                                [$nIcon, $nColor, $nBg] = notif_visual($notif['type'], $notif['severity']);
                                $nUnread = !$notif['is_read'];
                            ?>
                            <div class="notif-item" data-id="<?= (int)$notif['id'] ?>" data-url="<?= htmlspecialchars(notif_url($notif['link'])) ?>" data-unread="<?= $nUnread ? 1 : 0 ?>" onclick="notifOpen(this)"
                                 style="display: flex; gap: 0.65rem; padding: 0.75rem 1rem; border-bottom: 1px solid var(--border-color); cursor: pointer; <?= $nUnread ? 'background: rgba(59,130,246,0.06);' : '' ?>">
                                <div style="width: 30px; height: 30px; border-radius: 50%; background: <?= $nBg ?>; color: <?= $nColor ?>; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                    <i data-lucide="<?= $nIcon ?>" style="width: 15px; height: 15px;"></i>
                                </div>
                                <div style="min-width: 0; flex: 1;">
                                    <div style="display: flex; align-items: center; gap: 0.4rem;">
                                        <span class="notif-title" style="font-size: 0.8125rem; font-weight: <?= $nUnread ? 600 : 500 ?>; color: var(--text-primary); word-break: break-word;"><?= htmlspecialchars($notif['title']) ?></span>
                                        <span class="notif-dot" style="width: 7px; height: 7px; border-radius: 50%; background: #3b82f6; flex-shrink: 0; <?= $nUnread ? '' : 'display: none;' ?>"></span>
                                    </div>
                                    <?php if (!empty($notif['message'])): ?>
                                    <div class="notif-msg" style="font-size: 0.75rem; color: var(--text-muted); margin-top: 2px;"><?= htmlspecialchars($notif['message']) ?></div>
                                    <?php endif; ?>
                                    <div style="font-size: 0.7rem; color: var(--text-muted); margin-top: 4px; opacity: 0.8;"><?= htmlspecialchars(notif_time_ago((int)$notif['age_sec'])) ?></div>
                                </div>
                            </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                </div>
                <script>
                (function () {
                    var API = '<?= BASE_URL ?>pages/notifications_api.php';

                    function post(params) {
                        return fetch(API, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams(params).toString()
                        }).then(function (r) { return r.json(); });
                    }
                    function setBadge(n) {
                        var badge = document.getElementById('notifBadge');
                        var all = document.getElementById('notifMarkAll');
                        badge.textContent = n > 99 ? '99+' : n;
                        badge.style.display = n > 0 ? '' : 'none';
                        all.style.display = n > 0 ? '' : 'none';
                    }
                    function markItemRead(el) {
                        el.dataset.unread = '0';
                        el.style.background = '';
                        var dot = el.querySelector('.notif-dot');
                        if (dot) dot.style.display = 'none';
                        var title = el.querySelector('.notif-title');
                        if (title) title.style.fontWeight = '500';
                    }

                    window.toggleNotifMenu = function (e) {
                        e.stopPropagation();
                        document.getElementById('notifMenu').classList.toggle('hidden');
                    };
                    document.addEventListener('click', function (e) {
                        var box = document.getElementById('notifDropdownContainer');
                        if (box && !box.contains(e.target)) document.getElementById('notifMenu').classList.add('hidden');
                    });

                    window.notifOpen = function (el) {
                        var url = el.dataset.url;
                        var wasUnread = el.dataset.unread === '1';
                        if (!url) {
                            var msg = el.querySelector('.notif-msg');
                            if (msg) msg.classList.toggle('open');
                        }
                        if (wasUnread) {
                            markItemRead(el);
                            post({ action: 'read', id: el.dataset.id })
                                .then(function (d) { if (d && typeof d.unread === 'number') setBadge(d.unread); })
                                .catch(function () {})
                                .then(function () { if (url) window.location.href = url; });
                        } else if (url) {
                            window.location.href = url;
                        }
                    };

                    window.notifMarkAll = function () {
                        document.querySelectorAll('#notifMenu .notif-item').forEach(markItemRead);
                        setBadge(0);
                        post({ action: 'read_all' }).catch(function () {});
                    };
                })();
                </script>

                <div class="topbar-divider" style="width: 1px; height: 20px; background: var(--border-color); margin: 0 0.25rem;"></div>
                <?php endif; ?>

                <!-- User Profile Dropdown -->
                <div class="user-profile-container" style="position: relative;">
                    <div class="user-profile" style="display: flex; align-items: center; gap: 0.625rem; padding: 0.375rem 0.75rem; border-radius: 8px; cursor: pointer;" onclick="document.getElementById('profileMenu').classList.toggle('hidden')">
                        <div class="user-avatar" style="width: 32px; height: 32px; border-radius: 50%; background: linear-gradient(135deg, #3b82f6, #1d4ed8); display: flex; align-items: center; justify-content: center; color: white; font-size: 0.75rem; font-weight: 700;"><?= htmlspecialchars($initials) ?></div>
                        <div class="user-info" style="display: flex; flex-direction: column;">
                            <span class="user-name" style="font-size: 0.8125rem; font-weight: 600; line-height: 1.2;"><?= htmlspecialchars($userName) ?></span>
                            <span class="user-role" style="font-size: 0.7rem; color: var(--text-muted); line-height: 1.2;"><?= htmlspecialchars($userRole) ?></span>
                        </div>
                        <i data-lucide="chevron-down" style="width: 14px; height: 14px; color: var(--text-muted); margin-left: 2px;"></i>
                    </div>
                    <div id="profileMenu" class="hidden" style="position: absolute; right: 0; top: 110%; width: 200px; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: var(--radius-md); box-shadow: var(--shadow-lg); z-index: 50; padding: 0.5rem 0;">
                        <div style="padding: 0.5rem 1rem; border-bottom: 1px solid var(--border-color); margin-bottom: 0.5rem;">
                            <div style="font-weight: 600; font-size: 0.875rem; color: var(--text-primary);"><?= htmlspecialchars($userName) ?></div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);"><?= htmlspecialchars($userRole) ?></div>
                        </div>
                        <a href="<?= BASE_URL ?>pages/profile.php" style="display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem 1rem; color: var(--text-primary); font-size: 0.875rem; text-decoration: none;">
                            <i data-lucide="user" style="width: 16px; height: 16px;"></i> Profile
                        </a>
                        <a href="<?= BASE_URL ?>pages/profile.php" style="display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem 1rem; color: var(--text-primary); font-size: 0.875rem; text-decoration: none;">
                            <i data-lucide="settings" style="width: 16px; height: 16px;"></i> Settings
                        </a>
                        <div style="height: 1px; background: var(--border-color); margin: 0.5rem 0;"></div>
                        <a href="<?= BASE_URL ?>auth/logout.php" style="display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem 1rem; color: var(--danger-color); font-size: 0.875rem; text-decoration: none;">
                            <i data-lucide="log-out" style="width: 16px; height: 16px;"></i> Sign Out
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <?php 
        if (is_view_only()): 
            $vStudent = $_SESSION['instructor_viewing_student_name'] ?? 'Student';
            $vCompany = $_SESSION['instructor_viewing_company_name'] ?? 'Simulation';
        ?>
        <div style="background: linear-gradient(90deg, #1e1b4b, #312e81); border-bottom: 1px solid #4f46e5; padding: 0.6rem 1.25rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; color: #fff; font-size: 0.85rem; z-index: 50; flex-wrap: wrap;">
            <div class="flex items-center gap-2" style="flex-wrap: wrap;">
                <span class="badge" style="background: #ef4444; color: #fff; font-size: 0.7rem; font-weight: 700; padding: 2px 8px; border-radius: 99px;">READ-ONLY</span>
                <span>Instructor Viewing Mode: <strong><?= htmlspecialchars($vStudent) ?></strong> (<em><?= htmlspecialchars($vCompany) ?></em>)</span>
            </div>
            <div class="flex items-center gap-2">
                <a href="<?= BASE_URL ?>pages/student_output.php" class="btn btn-sm" style="background: rgba(255,255,255,0.15); color: #fff; border: 1px solid rgba(255,255,255,0.25); padding: 3px 10px; font-size: 0.75rem; text-decoration: none; border-radius: 6px;">Output Hub</a>
                <a href="<?= BASE_URL ?>pages/dashboard.php?action=exit_view" class="btn btn-sm btn-primary" style="padding: 3px 12px; font-size: 0.75rem; text-decoration: none; border-radius: 6px;">Exit Student View</a>
            </div>
        </div>
        <?php endif; ?>

        <!-- MAIN CONTENT -->
        <main class="main-content">