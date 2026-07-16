<?php if ($companyIsTaxRegistered): ?>
<div class="form-group" style="margin-bottom: 1.5rem; max-width: 320px;">
    <label class="form-label" for="taxStatus">Tax Type</label>
    <select name="tax_status" id="taxStatus" class="form-control">
        <option value="taxable" selected>Taxable</option>
        <option value="non_taxable">Non-Taxable</option>
    </select>
</div>
<?php else: ?>
<input type="hidden" name="tax_status" value="non_taxable">
<?php endif; ?>
