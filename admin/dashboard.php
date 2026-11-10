<?php
require_once '../config.php';
require_once '../db.php';
require_once '../includes/admin_auth.php';
require_once '../includes/header.php';

$db = get_db();

// --- Stats ---
$users_count     = $db->query("SELECT COUNT(*) FROM users")->fetch_row()[0];
$companies_count = $db->query("SELECT COUNT(*) FROM companies")->fetch_row()[0];
$logs_count      = $db->query("SELECT COUNT(*) FROM activity_logs WHERE module='Authentication'")->fetch_row()[0];
$admin_count     = $db->query("SELECT COUNT(*) FROM users WHERE role='Admin'")->fetch_row()[0];
$instructor_count= $db->query("SELECT COUNT(*) FROM users WHERE role='Instructor'")->fetch_row()[0];
$instructor_approved = $db->query("SELECT COUNT(*) FROM users WHERE role='Instructor' AND (status='approved' OR status IS NULL)")->fetch_row()[0];
$instructor_pending  = $db->query("SELECT COUNT(*) FROM users WHERE role='Instructor' AND status='pending'")->fetch_row()[0];
$student_count   = $db->query("SELECT COUNT(*) FROM users WHERE role='Student'")->fetch_row()[0];

// --- Today's logins ---
$today_logs = $db->query("SELECT COUNT(*) FROM activity_logs WHERE module='Authentication' AND DATE(created_at)=CURDATE()")->fetch_row()[0];

// --- Recent Logins ---
$recent_logs = $db->query("
    SELECT l.*, u.name as user_name, u.role as user_role
    FROM activity_logs l
    LEFT JOIN users u ON l.user_id = u.id
    WHERE l.module = 'Authentication'
    ORDER BY l.created_at DESC
    LIMIT 8
")->fetch_all(MYSQLI_ASSOC);

// --- Latest users ---
$latest_users = $db->query("SELECT name, email, role, created_at FROM users ORDER BY created_at DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);

// --- Cybersecurity & System Asset Overview (Area A) ---
$php_version   = PHP_VERSION;
$db_version    = $db->server_info;
$db_tables_count = 0;
$tblRes = $db->query("SHOW TABLES");
if ($tblRes) $db_tables_count = $tblRes->num_rows;
$brevo_configured = (!empty(BREVO_API_KEY) || !empty(SMTP_PASS));
$google_oauth_configured = !empty(GOOGLE_CLIENT_ID);
$server_software = $_SERVER['SERVER_SOFTWARE'] ?? (php_sapi_name() === 'cli-server' ? 'PHP Dev Server' : 'Apache HTTP Server');
?>

<style>
/* ── Clean, Human-Crafted SaaS Admin Interface ── */
.adm-container {
    max-width: 1360px;
    margin: 0 auto;
    padding: 1.5rem 1.75rem 3rem;
    color: #0f172a;
}

/* Page Header */
.adm-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1.5rem;
    margin-bottom: 1.75rem;
    padding-bottom: 1.25rem;
    border-bottom: 1px solid #e2e8f0;
}
.adm-title {
    font-size: 1.5rem;
    font-weight: 700;
    color: #0f172a;
    letter-spacing: -0.02em;
    margin: 0 0 0.25rem 0;
}
.adm-subtitle {
    font-size: 0.875rem;
    color: #64748b;
    margin: 0;
}
.adm-actions-group {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
}
.adm-btn-primary {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    background: #166534;
    color: #ffffff !important;
    font-size: 0.84rem;
    font-weight: 600;
    padding: 0.5rem 0.95rem;
    border-radius: 8px;
    text-decoration: none;
    border: 1px solid #166534;
    transition: background 0.15s ease;
}
.adm-btn-primary:hover {
    background: #14532d;
}
.adm-btn-secondary {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    background: #ffffff;
    color: #334155 !important;
    font-size: 0.84rem;
    font-weight: 600;
    padding: 0.5rem 0.85rem;
    border-radius: 8px;
    text-decoration: none;
    border: 1px solid #e2e8f0;
    transition: background 0.15s ease, border-color 0.15s ease;
}
.adm-btn-secondary:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
    color: #0f172a !important;
}

/* Alert Notification */
.adm-alert {
    background: #fffbeb;
    border: 1px solid #fef3c7;
    border-left: 4px solid #f59e0b;
    border-radius: 8px;
    padding: 0.9rem 1.25rem;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}
.adm-alert-title {
    font-size: 0.875rem;
    font-weight: 600;
    color: #92400e;
}
.adm-alert-desc {
    font-size: 0.8rem;
    color: #b45309;
    margin-top: 0.15rem;
}

/* Metrics Grid */
.adm-metrics-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
    margin-bottom: 1.5rem;
}
@media (max-width: 960px) {
    .adm-metrics-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 520px) {
    .adm-metrics-grid { grid-template-columns: 1fr; }
}

.adm-metric-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 1.15rem 1.25rem;
}
.adm-metric-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.65rem;
}
.adm-metric-label {
    font-size: 0.72rem;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.adm-metric-val {
    font-size: 1.75rem;
    font-weight: 700;
    color: #0f172a;
    line-height: 1;
    margin-bottom: 0.4rem;
}
.adm-metric-note {
    font-size: 0.75rem;
    color: #64748b;
    line-height: 1.4;
}

/* Standard Section Box */
.adm-section-box {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    margin-bottom: 1.5rem;
    overflow: hidden;
}
.adm-section-head {
    padding: 0.95rem 1.25rem;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #fafafa;
}
.adm-section-title {
    font-size: 0.875rem;
    font-weight: 600;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

/* Badges */
.adm-badge-soft-green {
    display: inline-flex;
    align-items: center;
    font-size: 0.72rem;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 6px;
    background: #f0fdf4;
    color: #166534;
    border: 1px solid #bbf7d0;
}
.adm-badge-soft-blue {
    display: inline-flex;
    align-items: center;
    font-size: 0.72rem;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 6px;
    background: #eff6ff;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
}
.adm-badge-soft-amber {
    display: inline-flex;
    align-items: center;
    font-size: 0.72rem;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 6px;
    background: #fffbeb;
    color: #b45309;
    border: 1px solid #fde68a;
}
.adm-badge-soft-red {
    display: inline-flex;
    align-items: center;
    font-size: 0.72rem;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 6px;
    background: #fef2f2;
    color: #b91c1c;
    border: 1px solid #fecaca;
}
.adm-badge-soft-gray {
    display: inline-flex;
    align-items: center;
    font-size: 0.72rem;
    font-weight: 500;
    padding: 2px 8px;
    border-radius: 6px;
    background: #f8fafc;
    color: #475569;
    border: 1px solid #e2e8f0;
}

/* Area A: Subhead & Detail Styles */
.adm-subhead {
    font-size: 0.74rem;
    font-weight: 700;
    color: #334155;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding: 0.75rem 1.25rem;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.adm-info-box {
    padding: 1.15rem 1.25rem;
    background: #ffffff;
    font-size: 0.84rem;
    color: #334155;
    line-height: 1.6;
}
.adm-fun-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 0.65rem;
    margin-top: 0.85rem;
}
.adm-fun-item {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 0.65rem 0.85rem;
    font-size: 0.79rem;
    color: #475569;
    line-height: 1.45;
}
.adm-fun-item strong {
    color: #0f172a;
    display: block;
    margin-bottom: 0.2rem;
    font-size: 0.8rem;
}
.adm-asset-table-wrap {
    width: 100%;
    overflow-x: auto;
}
.adm-asset-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.8rem;
    text-align: left;
}
.adm-asset-table th {
    background: #f8fafc;
    color: #64748b;
    font-weight: 600;
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    padding: 0.65rem 1.25rem;
    border-bottom: 1px solid #e2e8f0;
    white-space: nowrap;
}
.adm-asset-table td {
    padding: 0.65rem 1.25rem;
    border-bottom: 1px solid #f1f5f9;
    color: #334155;
    vertical-align: middle;
}
.adm-asset-table tr:last-child td {
    border-bottom: none;
}
.adm-asset-table tr:hover td {
    background: #fbfcfe;
}

/* Area A: System & Assets Inventory Grid */
.adm-specs-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 0.85rem;
    padding: 1.25rem;
}
.adm-spec-item {
    border: 1px solid #e2e8f0;
    background: #ffffff;
    border-radius: 8px;
    padding: 0.9rem 1rem;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}
.adm-spec-label {
    font-size: 0.72rem;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    margin-bottom: 0.35rem;
}
.adm-spec-val {
    font-size: 0.95rem;
    font-weight: 600;
    color: #0f172a;
    margin-bottom: 0.2rem;
}
.adm-spec-detail {
    font-size: 0.75rem;
    color: #64748b;
    margin-bottom: 0.65rem;
    line-height: 1.4;
}

/* Two Column Layout */
.adm-grid-2col {
    display: grid;
    grid-template-columns: 1.35fr 1fr;
    gap: 1.5rem;
    margin-bottom: 1.5rem;
}
@media (max-width: 960px) {
    .adm-grid-2col { grid-template-columns: 1fr; }
}

/* List Rows */
.adm-list-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.75rem 1.25rem;
    border-bottom: 1px solid #f1f5f9;
}
.adm-list-row:last-child {
    border-bottom: none;
}
.adm-list-row:hover {
    background: #f8fafc;
}
.adm-user-info {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.adm-avatar-box {
    width: 32px;
    height: 32px;
    border-radius: 6px;
    background: #f1f5f9;
    color: #475569;
    font-size: 0.75rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #e2e8f0;
    flex-shrink: 0;
}
.adm-name-text {
    font-size: 0.85rem;
    font-weight: 600;
    color: #0f172a;
}
.adm-desc-text {
    font-size: 0.75rem;
    color: #64748b;
}

/* Quick Actions Grid */
.adm-nav-tiles {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0.5rem;
    padding: 1rem 1.25rem;
}
.adm-nav-tile {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.65rem 0.85rem;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    text-decoration: none;
    color: #334155;
    font-size: 0.8rem;
    font-weight: 500;
    background: #ffffff;
    transition: all 0.15s ease;
}
.adm-nav-tile:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
    color: #0f172a;
}
.adm-nav-tile i {
    color: #64748b;
    width: 14px;
    height: 14px;
}

/* Role Breakdown */
.adm-roles-box {
    padding: 1rem 1.25rem;
}
.adm-role-item {
    margin-bottom: 0.75rem;
}
.adm-role-item:last-child {
    margin-bottom: 0;
}
.adm-role-flex {
    display: flex;
    justify-content: space-between;
    font-size: 0.75rem;
    color: #475569;
    margin-bottom: 0.25rem;
}
.adm-track-bar {
    background: #f1f5f9;
    border-radius: 4px;
    height: 5px;
    overflow: hidden;
}
.adm-track-fill {
    background: #334155;
    height: 100%;
    border-radius: 4px;
}
</style>

<div class="adm-container">

    <!-- PAGE HEADER -->
    <div class="adm-header">
        <div>
            <h1 class="adm-title">System Overview</h1>
            <p class="adm-subtitle">Platform health, account activity, and infrastructure asset inventory.</p>
        </div>
    </div>

    <!-- PENDING FACULTY ALERT -->
    <?php if ($instructor_pending > 0): ?>
    <div class="adm-alert">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <i data-lucide="shield-alert" style="width: 20px; height: 20px; color: #d97706; flex-shrink: 0;"></i>
            <div>
                <div class="adm-alert-title">Faculty Verification Required (<?= $instructor_pending ?> Pending)</div>
                <div class="adm-alert-desc">One or more faculty registrations require administrator review and approval before dashboard access is permitted.</div>
            </div>
        </div>
        <a href="users.php" class="adm-btn-secondary" style="border-color: #fcd34d; background: #fff;">
            Review Accounts &rarr;
        </a>
    </div>
    <?php endif; ?>

    <!-- METRICS GRID -->
    <div class="adm-metrics-grid">
        <div class="adm-metric-card">
            <div class="adm-metric-header">
                <span class="adm-metric-label">Total Users</span>
                <i data-lucide="users" style="width: 16px; height: 16px; color: #94a3b8;"></i>
            </div>
            <div class="adm-metric-val"><?= $users_count ?></div>
            <div class="adm-metric-note"><?= $admin_count ?> Admin · <?= $instructor_approved ?> Faculty · <?= $student_count ?> Students</div>
        </div>

        <div class="adm-metric-card">
            <div class="adm-metric-header">
                <span class="adm-metric-label">Active Companies</span>
                <i data-lucide="building-2" style="width: 16px; height: 16px; color: #94a3b8;"></i>
            </div>
            <div class="adm-metric-val"><?= $companies_count ?></div>
            <div class="adm-metric-note">Registered accounting tenants</div>
        </div>

        <div class="adm-metric-card">
            <div class="adm-metric-header">
                <span class="adm-metric-label">Total Sign-Ins</span>
                <i data-lucide="scroll-text" style="width: 16px; height: 16px; color: #94a3b8;"></i>
            </div>
            <div class="adm-metric-val"><?= number_format($logs_count) ?></div>
            <div class="adm-metric-note">All-time authentication events</div>
        </div>

        <div class="adm-metric-card">
            <div class="adm-metric-header">
                <span class="adm-metric-label">Logins Today</span>
                <i data-lucide="calendar" style="width: 16px; height: 16px; color: #94a3b8;"></i>
            </div>
            <div class="adm-metric-val"><?= $today_logs ?></div>
            <div class="adm-metric-note">Sign-ins recorded for <?= date('M j') ?></div>
        </div>
    </div>

    <!-- AREA A: SYSTEM & ASSETS INVENTORY -->
    <div class="adm-section-box">
        <div class="adm-section-head">
            <div class="adm-section-title">
                <i data-lucide="cpu" style="width: 16px; height: 16px; color: #166534;"></i>
                System Overview
            </div>
        </div>

        <!-- Infrastructure Asset Cards -->
        <div class="adm-subhead">
            <span>Infrastructure Assets</span>
            <span style="font-size: 0.7rem; color: #94a3b8; text-transform: none; font-weight: 500; letter-spacing: 0;">Discovered at runtime</span>
        </div>
        <div class="adm-specs-grid">
            <div class="adm-spec-item">
                <div>
                    <div class="adm-spec-label">Application Runtime</div>
                    <div class="adm-spec-val">PHP <?= htmlspecialchars($php_version) ?></div>
                    <div class="adm-spec-detail"><?= htmlspecialchars(substr($server_software, 0, 40)) ?></div>
                </div>
                <div style="display:flex; gap:0.35rem; flex-wrap:wrap; margin-top:0.5rem;">
                    <span class="adm-badge-soft-gray">Internal</span>
                    <span class="adm-badge-soft-amber">System Admin</span>
                </div>
            </div>
            <div class="adm-spec-item">
                <div>
                    <div class="adm-spec-label">Database Asset</div>
                    <div class="adm-spec-val">MySQL <?= htmlspecialchars($db_version) ?></div>
                    <div class="adm-spec-detail">Host: <?= htmlspecialchars(DB_HOST) ?> · <?= $db_tables_count ?> Schema Tables</div>
                </div>
                <div style="display:flex; gap:0.35rem; flex-wrap:wrap; margin-top:0.5rem;">
                    <span class="adm-badge-soft-red">Confidential</span>
                    <span class="adm-badge-soft-amber">System Admin</span>
                </div>
            </div>
            <div class="adm-spec-item">
                <div>
                    <div class="adm-spec-label">External Integrations</div>
                    <div class="adm-spec-val">Brevo SMTP &amp; Google SSO</div>
                    <div class="adm-spec-detail">Brevo: <?= $brevo_configured ? 'Active' : 'Disabled' ?> · Google OAuth: <?= $google_oauth_configured ? 'Active' : 'Disabled' ?></div>
                </div>
                <div style="display:flex; gap:0.35rem; flex-wrap:wrap; margin-top:0.5rem;">
                    <span class="adm-badge-soft-gray">Internal</span>
                    <span class="adm-badge-soft-amber">System Admin</span>
                </div>
            </div>
            <div class="adm-spec-item">
                <div>
                    <div class="adm-spec-label">Cryptographic Protection</div>
                    <div class="adm-spec-val">BCrypt (Cost 10) &amp; 2FA (OTP)</div>
                    <div class="adm-spec-detail">Email OTP 2FA, CSRF Guard &amp; HTTP Security Headers (CSP, HSTS)</div>
                </div>
                <div style="display:flex; gap:0.35rem; flex-wrap:wrap; margin-top:0.5rem;">
                    <span class="adm-badge-soft-red">Restricted</span>
                    <span class="adm-badge-soft-amber">System Admin</span>
                </div>
            </div>
        </div>

        <!-- A3. Accounting Modules Inventory -->
        <div class="adm-subhead">
            <span>Accounting Modules Inventory</span>
            <span style="font-size: 0.7rem; color: #94a3b8; text-transform: none; font-weight: 500; letter-spacing: 0;">19 modules identified</span>
        </div>
        <div class="adm-asset-table-wrap">
            <table class="adm-asset-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Module Name</th>
                        <th>File / Path</th>
                        <th>Access Role</th>
                        <th>Classification</th>
                        <th>Owner</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $modules = [
                        ['no'=>'M-01','name'=>'User Management',          'file'=>'admin/users.php',                  'role'=>'Admin',                           'class'=>'Restricted',   'owner'=>'System Administrator'],
                        ['no'=>'M-02','name'=>'Company Setup',            'file'=>'pages/company_setup.php',          'role'=>'Student',                         'class'=>'Internal',     'owner'=>'Student User'],
                        ['no'=>'M-03','name'=>'Chart of Accounts',        'file'=>'pages/chart_of_accounts.php',      'role'=>'Student',                         'class'=>'Confidential', 'owner'=>'Student / Instructor'],
                        ['no'=>'M-04','name'=>'General Journal',          'file'=>'pages/journal_entries.php',        'role'=>'Student',                         'class'=>'Confidential', 'owner'=>'Student / Instructor'],
                        ['no'=>'M-05','name'=>'Cash Receipts Journal',    'file'=>'pages/cash_receipts_journal.php',  'role'=>'Student',                         'class'=>'Confidential', 'owner'=>'Student / Instructor'],
                        ['no'=>'M-06','name'=>'Cash Disbursements Journal','file'=>'pages/cash_disbursements_journal.php','role'=>'Student',                     'class'=>'Confidential', 'owner'=>'Student / Instructor'],
                        ['no'=>'M-07','name'=>'Sales Journal',            'file'=>'pages/sales_journal.php',          'role'=>'Student',                         'class'=>'Confidential', 'owner'=>'Student / Instructor'],
                        ['no'=>'M-08','name'=>'Purchases Journal',        'file'=>'pages/purchases_journal.php',      'role'=>'Student',                         'class'=>'Confidential', 'owner'=>'Student / Instructor'],
                        ['no'=>'M-09','name'=>'General Ledger',           'file'=>'pages/general_ledger.php',         'role'=>'Student / Instructor',            'class'=>'Confidential', 'owner'=>'Student / Instructor'],
                        ['no'=>'M-10','name'=>'Subsidiary Ledger',        'file'=>'pages/subsidiary_ledger.php',      'role'=>'Student / Instructor',            'class'=>'Confidential', 'owner'=>'Student / Instructor'],
                        ['no'=>'M-11','name'=>'Trial Balance',            'file'=>'pages/trial_balance.php',          'role'=>'Student / Instructor',            'class'=>'Confidential', 'owner'=>'Student / Instructor'],
                        ['no'=>'M-12','name'=>'Financial Statements',     'file'=>'pages/financial_statements.php',   'role'=>'Student / Instructor',            'class'=>'Confidential', 'owner'=>'Student / Instructor'],
                        ['no'=>'M-13','name'=>'Customers',                'file'=>'pages/customers.php',              'role'=>'Student',                         'class'=>'Internal',     'owner'=>'Student User'],
                        ['no'=>'M-14','name'=>'Suppliers',                'file'=>'pages/suppliers.php',              'role'=>'Student',                         'class'=>'Internal',     'owner'=>'Student User'],
                        ['no'=>'M-15','name'=>'Employees',                'file'=>'pages/employees.php',              'role'=>'Student',                         'class'=>'Internal',     'owner'=>'Student User'],
                        ['no'=>'M-16','name'=>'Student Output Monitoring','file'=>'pages/dashboard.php (Instructor)', 'role'=>'Instructor / Admin',              'class'=>'Internal',     'owner'=>'Instructor'],
                        ['no'=>'M-17','name'=>'Activity Audit Logs',      'file'=>'admin/logs.php',                   'role'=>'Admin',                           'class'=>'Restricted',   'owner'=>'System Administrator'],
                        ['no'=>'M-18','name'=>'Company Assignments',      'file'=>'admin/assignments.php',            'role'=>'Admin',                           'class'=>'Internal',     'owner'=>'System Administrator'],
                        ['no'=>'M-19','name'=>'Database Backup',          'file'=>'admin/backup.php',                 'role'=>'Admin',                           'class'=>'Restricted',   'owner'=>'System Administrator'],
                    ];
                    $classBadge = [
                        'Public'       => 'adm-badge-soft-green',
                        'Internal'     => 'adm-badge-soft-blue',
                        'Confidential' => 'adm-badge-soft-amber',
                        'Restricted'   => 'adm-badge-soft-red',
                    ];
                    foreach ($modules as $m): ?>
                    <tr>
                        <td style="color:#94a3b8; font-size:0.72rem; font-weight:600;"><?= $m['no'] ?></td>
                        <td style="font-weight:600; color:#0f172a;"><?= htmlspecialchars($m['name']) ?></td>
                        <td style="font-family: ui-monospace, monospace; font-size:0.72rem; color:#64748b;"><?= htmlspecialchars($m['file']) ?></td>
                        <td><?= htmlspecialchars($m['role']) ?></td>
                        <td><span class="<?= $classBadge[$m['class']] ?>"><?= $m['class'] ?></span></td>
                        <td style="color:#475569;"><?= htmlspecialchars($m['owner']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>


    </div>

    <!-- TWO COLUMN SECTION -->
    <div class="adm-grid-2col">

        <!-- Recent Logins -->
        <div class="adm-section-box" style="margin-bottom: 0;">
            <div class="adm-section-head">
                <div class="adm-section-title">
                    <i data-lucide="log-in" style="width: 15px; height: 15px; color: #475569;"></i>
                    Recent Authentication Activity
                </div>
                <a href="logs.php" style="font-size: 0.8rem; font-weight: 500; color: #166534; text-decoration: none;">
                    View All &rarr;
                </a>
            </div>
            <div>
                <?php if (empty($recent_logs)): ?>
                <div style="padding: 2rem; text-align: center; color: #94a3b8; font-size: 0.85rem;">No recent authentication events recorded.</div>
                <?php else: foreach($recent_logs as $log):
                    $initials = strtoupper(substr($log['user_name'] ?? 'U', 0, 2));
                    $timeAgo = date('M d, h:i A', strtotime($log['created_at']));
                ?>
                <div class="adm-list-row">
                    <div class="adm-user-info">
                        <div class="adm-avatar-box"><?= $initials ?></div>
                        <div>
                            <div class="adm-name-text"><?= htmlspecialchars($log['user_name'] ?? 'Unknown User') ?></div>
                            <div class="adm-desc-text">
                                <span style="font-weight: 600; color: #334155;"><?= htmlspecialchars($log['action']) ?></span>
                                &middot; <?= htmlspecialchars($log['description'] ?? '') ?>
                            </div>
                        </div>
                    </div>
                    <div style="font-size: 0.72rem; color: #94a3b8; flex-shrink: 0;"><?= $timeAgo ?></div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <!-- Right Column: Quick Navigation & User Roles -->
        <div style="display: flex; flex-direction: column; gap: 1.5rem;">

            <!-- Quick Management -->
            <div class="adm-section-box" style="margin-bottom: 0;">
                <div class="adm-section-head">
                    <div class="adm-section-title">
                        <i data-lucide="compass" style="width: 15px; height: 15px; color: #475569;"></i>
                        System Management
                    </div>
                </div>
                <div class="adm-nav-tiles">
                    <a href="users.php" class="adm-nav-tile">
                        <i data-lucide="users"></i> Manage Users
                    </a>
                    <a href="logs.php" class="adm-nav-tile">
                        <i data-lucide="scroll-text"></i> Audit Logs
                    </a>
                    <a href="<?= BASE_URL ?>pages/company_setup.php" class="adm-nav-tile">
                        <i data-lucide="building"></i> Company Setup
                    </a>
                    <a href="<?= BASE_URL ?>pages/chart_of_accounts.php" class="adm-nav-tile">
                        <i data-lucide="book-open"></i> Accounts Chart
                    </a>
                    <a href="assignments.php" class="adm-nav-tile">
                        <i data-lucide="link"></i> Assignments
                    </a>
                    <a href="system_notices.php" class="adm-nav-tile">
                        <i data-lucide="megaphone"></i> Notices
                    </a>
                    <a href="security_test.php" class="adm-nav-tile" title="Run Automated Security Verification Suite (Area H)">
                        <i data-lucide="shield-check"></i> Security Audit
                    </a>
                    <a href="backup.php?type=sql" class="adm-nav-tile" title="Download database SQL backup">
                        <i data-lucide="database"></i> Backup DB
                    </a>
                </div>
            </div>

            <!-- Role Distribution -->
            <div class="adm-section-box" style="margin-bottom: 0;">
                <div class="adm-section-head">
                    <div class="adm-section-title">
                        <i data-lucide="pie-chart" style="width: 15px; height: 15px; color: #475569;"></i>
                        User Distribution
                    </div>
                </div>
                <div class="adm-roles-box">
                    <?php
                    $roleList = [
                        ['label' => 'Administrators', 'count' => $admin_count],
                        ['label' => 'Faculty / Instructors', 'count' => $instructor_count],
                        ['label' => 'Students', 'count' => $student_count],
                    ];
                    foreach ($roleList as $rl):
                        $pct = $users_count > 0 ? round(($rl['count'] / $users_count) * 100) : 0;
                    ?>
                    <div class="adm-role-item">
                        <div class="adm-role-flex">
                            <span><?= $rl['label'] ?></span>
                            <span style="font-weight: 600;"><?= $rl['count'] ?> (<?= $pct ?>%)</span>
                        </div>
                        <div class="adm-track-bar">
                            <div class="adm-track-fill" style="width: <?= $pct ?>%;"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

        </div>

    </div>

    <!-- LATEST REGISTERED USERS -->
    <div class="adm-section-box">
        <div class="adm-section-head">
            <div class="adm-section-title">
                <i data-lucide="user-check" style="width: 15px; height: 15px; color: #475569;"></i>
                Latest Registered Users
            </div>
            <a href="users.php" style="font-size: 0.8rem; font-weight: 500; color: #166534; text-decoration: none;">
                Manage All &rarr;
            </a>
        </div>
        <div>
            <?php if (empty($latest_users)): ?>
            <div style="padding: 2rem; text-align: center; color: #94a3b8; font-size: 0.85rem;">No registered users found.</div>
            <?php else: foreach ($latest_users as $u):
                $init = strtoupper(substr($u['name'], 0, 1));
            ?>
            <div class="adm-list-row">
                <div class="adm-user-info">
                    <div class="adm-avatar-box"><?= $init ?></div>
                    <div>
                        <div class="adm-name-text"><?= htmlspecialchars($u['name']) ?></div>
                        <div class="adm-desc-text"><?= htmlspecialchars($u['email']) ?></div>
                    </div>
                </div>
                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <span class="adm-badge-soft-gray"><?= htmlspecialchars($u['role']) ?></span>
                    <span style="font-size: 0.72rem; color: #94a3b8;"><?= date('M d, Y', strtotime($u['created_at'])) ?></span>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

</div>

<script>
window.addEventListener('load', () => {
    if (window.lucide) {
        lucide.createIcons();
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>