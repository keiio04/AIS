<?php
$editTaxStatus = (($tx['tax_status'] ?? 'non_taxable') === 'taxable' || count($editLines) < count($lines))
    ? 'taxable'
    : 'non_taxable';
$editPayload = [
    'id' => (int)$tx['id'],
    'date' => $tx['date'],
    'reference_no' => $tx['reference_no'],
    'description' => $tx['description'],
    'entity_id' => $tx['entity_id'],
    'entity_type' => $tx['entity_type'],
    'entity_name' => $tx['entity_name'],
    'vendor_name' => $tx['vendor_name'] ?? '',
    'tax_status' => $editTaxStatus,
    'lines' => array_map(static function ($line) {
        return [
            'account_id' => (int)$line['account_id'],
            'debit' => (float)$line['debit'],
            'credit' => (float)$line['credit'],
        ];
    }, $editLines),
];
?>
<div class="flex gap-1" style="justify-content:center;">
    <button type="button" style="background:none; border:none; cursor:pointer; color:var(--primary-color);" title="Edit Entry" onclick='openEditModal(<?= htmlspecialchars(json_encode($editPayload), ENT_QUOTES, 'UTF-8') ?>)'>
        <i data-lucide="edit-2" style="width:15px;height:15px;"></i>
    </button>
    <form method="POST" style="display:inline;" onsubmit="return confirm('Move this entry to Trash Bin?');">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int)$tx['id'] ?>">
        <button type="submit" style="background:none; border:none; cursor:pointer; color:#ef4444;" title="Move to Trash">
            <i data-lucide="trash-2" style="width:15px;height:15px;"></i>
        </button>
    </form>
</div>
