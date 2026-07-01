<?php
// Shared "No Active Company" empty state
// Usage: include this file, then call footer and exit.
$pageCtxIcon = $noCompanyIcon ?? 'building-2';
$pageCtxLabel = $noCompanyLabel ?? 'this page';
?>
<div class="no-company-wrapper">
  <div class="no-company-card">

    <!-- Animated Icon -->
    <div class="no-company-icon-wrap">
      <i data-lucide="building-2" style="width:36px;height:36px;color:#3b82f6;"></i>
    </div>

    <h2 class="no-company-title">No Company Selected</h2>
    <p class="no-company-subtitle">
      You need to set up and activate a company before you can access <?= htmlspecialchars($pageCtxLabel) ?>.
      It only takes a minute!
    </p>

    <!-- Steps -->
    <div class="no-company-steps">
      <div class="no-company-step">
        <div class="no-company-step-num">1</div>
        <span>Go to <strong>Company Setup</strong> and create your business</span>
      </div>
      <div class="no-company-step">
        <div class="no-company-step-num">2</div>
        <span>Choose your <strong>Business Type</strong> (Service, Merchandising, or Manufacturing)</span>
      </div>
      <div class="no-company-step">
        <div class="no-company-step-num">3</div>
        <span>Your <strong>Chart of Accounts</strong> will be loaded automatically</span>
      </div>
    </div>

    <!-- Action Button -->
    <a href="<?= BASE_URL ?>pages/company_setup.php" class="btn btn-primary" style="width:100%; justify-content:center; padding: 0.75rem 1rem; font-size: 0.9375rem; gap: 0.625rem;">
      <i data-lucide="building-2" style="width:16px;height:16px;"></i>
      Go to Company Setup
    </a>

    <p style="font-size: 0.775rem; color: var(--text-muted); margin-top: 1rem;">
      Already have a company? Switch to it from the Company Setup page.
    </p>
  </div>
</div>
