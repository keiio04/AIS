<?php
require_once __DIR__ . '/auth.php';

// Calculate initials
$userName = $_SESSION['user_name'] ?? 'Admin';
$userRole = $_SESSION['user_role'] ?? 'Super Admin';
$parts = explode(' ', $userName);
$initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));

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
    'dashboard' => 'Dashboard',
    'journal_entries' => 'Journal Entries',
    'general_ledger' => 'General Ledger',
    'trial_balance' => 'Trial Balance',
    'financial_statements' => 'Financial Statements',
    'chart_of_accounts' => 'Chart of Accounts',
    'company_setup' => 'Company Setup',
    'trash_bin' => 'Trash Bin',
];
$pageTitle = $pageNames[$current_page] ?? ucfirst($current_page);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?> - AccounTech</title>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
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
                    <div style="background: linear-gradient(135deg, #0ea5e9, #3b82f6, #4f46e5); padding: 6px; border-radius: 10px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 15px rgba(59, 130, 246, 0.5), inset 0 2px 4px rgba(255,255,255,0.3); position: relative; overflow: hidden;">
                        <div style="position: absolute; top: 0; left: 0; right: 0; height: 50%; background: linear-gradient(to bottom, rgba(255,255,255,0.25), transparent);"></div>
                        <i data-lucide="bar-chart-3" color="#ffffff" stroke-width="2.5" style="width: 20px; height: 20px; z-index: 1;"></i>
                    </div>
                    <span style="font-size: 1.15rem; font-weight: 800; background: linear-gradient(to right, #ffffff, #93c5fd); -webkit-background-clip: text; -webkit-text-fill-color: transparent; letter-spacing: -0.02em;">
                        AccounTech
                    </span>
                </div>
                <?php if ($activeCompanyName): ?>
                <div style="font-size: 0.75rem; color: #60a5fa; font-weight: 600; padding-left: 0.25rem; margin-top: 0.15rem;">
                    <?= htmlspecialchars($activeCompanyName) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <nav class="sidebar-nav">
            <div style="padding: 0 0.75rem; margin-bottom: 0.5rem; pointer-events: <?= $activeCompanyId ? 'auto' : 'none' ?>; opacity: <?= $activeCompanyId ? '1' : '0.5' ?>;">
                <a href="<?= BASE_URL ?>pages/dashboard.php" class="nav-item <?= $current_page==='dashboard'?'active':'' ?>">
                    <i data-lucide="layout-dashboard" style="width: 17px; height: 17px;"></i>
                    <span>Dashboard</span>
                </a>
            </div>

            <div class="nav-section" style="pointer-events: <?= $activeCompanyId ? 'auto' : 'none' ?>; opacity: <?= $activeCompanyId ? '1' : '0.5' ?>;">
                <div class="nav-section-title" onclick="toggleSidebarSection('biz_type')">
                    <div class="flex items-center gap-2"><i data-lucide="building" style="width: 15px; height: 15px;"></i><span>Business Type</span></div>
                    <i data-lucide="chevron-down" style="width: 13px; height: 13px;"></i>
                </div>
                <div id="biz_type" class="nav-subitems collapse-smooth">
                    
                    <?php 
                    $bizTypes = ['Service', 'Merchandise', 'Manufacturing'];
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
                                    <span style="font-size: 0.75rem;">General Journal</span>
                                </a>
                                <a href="<?= BASE_URL ?>pages/sales_journal.php" class="nav-subitem <?= $current_page==='sales_journal'?'active':'' ?>" style="padding: 0.25rem 0.5rem; margin-bottom: 2px;">
                                    <span style="font-size: 0.75rem;">Sales Journal</span>
                                </a>
                                <a href="<?= BASE_URL ?>pages/purchases_journal.php" class="nav-subitem <?= $current_page==='purchases_journal'?'active':'' ?>" style="padding: 0.25rem 0.5rem; margin-bottom: 2px;">
                                    <span style="font-size: 0.75rem;">Purchases Journal</span>
                                </a>
                                <a href="<?= BASE_URL ?>pages/cash_receipts_journal.php" class="nav-subitem <?= $current_page==='cash_receipts_journal'?'active':'' ?>" style="padding: 0.25rem 0.5rem; margin-bottom: 2px;">
                                    <span style="font-size: 0.75rem;">Cash Receipts</span>
                                </a>
                                <a href="<?= BASE_URL ?>pages/cash_disbursements_journal.php" class="nav-subitem <?= $current_page==='cash_disbursements_journal'?'active':'' ?>" style="padding: 0.25rem 0.5rem; margin-bottom: 2px;">
                                    <span style="font-size: 0.75rem;">Cash Disbursements</span>
                                </a>
                            </div>
                            <a href="<?= BASE_URL ?>pages/general_ledger.php" class="nav-subitem <?= $current_page==='general_ledger'?'active':'' ?>" style="padding: 0.25rem 0.5rem;">
                                <div class="flex items-center gap-2"><i data-lucide="layers" style="width: 13px; height: 13px;"></i><span>General Ledger</span></div>
                            </a>
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

            <?php if ($userRole === 'Admin'): ?>
            <div class="nav-section">
                <div class="nav-section-title" onclick="toggleSidebarSection('admin')">
                    <span>Administration</span>
                    <i data-lucide="chevron-down" style="width: 13px; height: 13px;"></i>
                </div>
                <div id="admin" class="nav-subitems collapse-smooth">
                    <a href="<?= BASE_URL ?>pages/company_setup.php" class="nav-subitem <?= $current_page==='company_setup'?'active':'' ?>">
                        <div class="flex items-center gap-2"><i data-lucide="building-2" style="width: 15px; height: 15px;"></i><span>Company Setup</span></div>
                    </a>
                    <a href="<?= BASE_URL ?>pages/chart_of_accounts.php" class="nav-subitem <?= $current_page==='chart_of_accounts'?'active':'' ?>">
                        <div class="flex items-center gap-2"><i data-lucide="book-open" style="width: 15px; height: 15px;"></i><span>Chart of Accounts</span></div>
                    </a>
                </div>
            </div>
            <?php endif; ?>


        </nav>

        <?php if ($userRole === 'Admin'): ?>
        <div style="padding: 0 0.75rem; margin-bottom: 0.5rem;">
            <a href="<?= BASE_URL ?>admin/dashboard.php" class="nav-item" style="width: 100%; background: rgba(59,130,246,0.1); color: #60a5fa; justify-content: center; text-decoration: none;">
                <i data-lucide="settings" style="width: 15px; height: 15px;"></i>
                <span>Admin Panel</span>
            </a>
        </div>
        <?php endif; ?>

        <div style="padding: 0 0.75rem; padding-top: 0.5rem; pointer-events: <?= $activeCompanyId ? 'auto' : 'none' ?>; opacity: <?= $activeCompanyId ? '1' : '0.5' ?>;">
            <a href="<?= BASE_URL ?>pages/trash_bin.php" class="nav-item <?= $current_page==='trash_bin'?'active':'' ?>" style="margin-bottom: 0;">
                <i data-lucide="trash-2" style="width: 17px; height: 17px;"></i>
                <span>Trash Bin</span>
            </a>
        </div>

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
        
        <script src="<?= BASE_URL ?>assets/js/script.js"></script>
    </div>

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
                <form action="<?= BASE_URL ?>pages/journal_entries.php" method="GET" style="margin: 0;">
                    <i data-lucide="search" class="topbar-search-icon" style="width: 14px; height: 14px; position: absolute; left: 0.875rem; top: 50%; transform: translateY(-50%); color: var(--text-muted);"></i>
                    <input type="text" name="search" class="form-control" placeholder="Search accounts, entries…" style="padding-left: 2.5rem; border-radius: 999px; height: 36px; font-size: 0.825rem; background-color: var(--bg-tertiary); border: 1px solid var(--border-color);">
                </form>
            </div>

            <div class="topbar-actions">
                <button class="icon-btn" id="darkModeBtn" title="Toggle Dark/Light Mode" onclick="toggleDarkMode()">
                    <i data-lucide="sun" style="width: 18px; height: 18px;" id="darkModeIcon"></i>
                </button>
                <div class="topbar-divider" style="width: 1px; height: 20px; background: var(--border-color); margin: 0 0.25rem;"></div>
                
                <!-- Notifications Dropdown -->
                <div style="position: relative;" id="notifDropdownContainer">
                    <button class="icon-btn" style="position: relative;" onclick="document.getElementById('notifMenu').classList.toggle('hidden')">
                        <i data-lucide="bell" style="width: 18px; height: 18px;"></i>
                        <span style="position: absolute; top: 4px; right: 4px; width: 8px; height: 8px; border-radius: 50%; background: #ef4444; border: 2px solid var(--bg-secondary);"></span>
                    </button>
                    <div id="notifMenu" class="hidden" style="position: absolute; right: 0; top: 110%; width: 280px; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: var(--radius-md); box-shadow: var(--shadow-lg); z-index: 50; padding: 1rem;">
                        <h4 style="margin-bottom: 0.5rem; font-size: 0.875rem;">Notifications</h4>
                        <div style="font-size: 0.8125rem; color: var(--text-muted); text-align: center; padding: 1rem 0;">No new notifications</div>
                    </div>
                </div>

                <div class="topbar-divider" style="width: 1px; height: 20px; background: var(--border-color); margin: 0 0.25rem;"></div>
                
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

        <!-- MAIN CONTENT -->
        <main class="main-content">
