<?php
// ============================================================
// admin_nav.php — Shared sub-navigation tabs for the Admin Panel.
// Included by admin/dashboard.php, users.php, companies.php,
// assignments.php, and logs.php. Relies on $current_page, which
// is already set by includes/header.php (basename of the script).
// ============================================================
$adminNavItems = [
    'dashboard'   => ['icon' => 'layout-dashboard', 'label' => 'Dashboard'],
    'users'       => ['icon' => 'users',            'label' => 'Users'],
    'companies'   => ['icon' => 'building-2',       'label' => 'Companies'],
    'assignments'   => ['icon' => 'link',             'label' => 'Assignments'],
    'logs'          => ['icon' => 'scroll-text',      'label' => 'Activity Logs'],
    'security_test' => ['icon' => 'shield-check',     'label' => 'Security Audit & Evidence'],
];
?>
<div class="flex gap-1 mb-4 flex-wrap" style="background: #ffffff; padding: 0.35rem 0.5rem; border-radius: 10px; border: 1px solid #e2e8f0; width: fit-content;">
    <?php foreach ($adminNavItems as $key => $item): $isActive = ($current_page === $key); ?>
    <a href="<?= $key ?>.php"
       style="<?= $isActive ? 'background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0;' : 'background: transparent; color: #64748b; border: 1px solid transparent;' ?> padding: 0.4rem 0.85rem; border-radius: 7px; font-size: 0.82rem; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; transition: all 0.15s ease;">
        <i data-lucide="<?= $item['icon'] ?>" style="width: 14px; height: 14px; <?= $isActive ? 'color: #166534;' : 'color: #94a3b8;' ?>"></i>
        <span><?= $item['label'] ?></span>
    </a>
    <?php endforeach; ?>
</div>