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
    'assignments' => ['icon' => 'link',             'label' => 'Assignments'],
    'logs'        => ['icon' => 'scroll-text',      'label' => 'Activity Logs'],
];
?>
<div class="flex gap-1 mb-4 flex-wrap" style="background: var(--bg-secondary); padding: 0.35rem; border-radius: 12px; border: 1px solid var(--border-color);">
    <?php foreach ($adminNavItems as $key => $item): $isActive = ($current_page === $key); ?>
    <a href="<?= $key ?>.php" class="btn btn-sm <?= $isActive ? 'btn-primary' : '' ?>"
       style="<?= $isActive ? 'border-radius: 8px;' : 'background: transparent; border: none; color: var(--text-muted); border-radius: 8px;' ?> display: inline-flex; align-items: center; gap: 6px; font-weight: 600; text-decoration: none;">
        <i data-lucide="<?= $item['icon'] ?>" style="width: 14px; height: 14px;"></i>
        <span><?= $item['label'] ?></span>
    </a>
    <?php endforeach; ?>
</div>