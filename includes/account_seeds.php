<?php
/**
 * includes/account_seeds.php
 * Returns default Chart of Accounts arrays per business type.
 * Each entry: [code, name, category, sub_category, description]
 */

function get_service_accounts(): array {
    return [
        // ASSETS
        ['1-100','Cash on Hand','Assets','Current Assets','Cash available on premises'],
        ['1-101','Cash in Bank','Assets','Current Assets','Cash deposited in bank'],
        ['1-110','Accounts Receivable','Assets','Current Assets','Amounts due from clients'],
        ['1-120','Notes Receivable','Assets','Current Assets','Formal written promises to receive'],
        ['1-130','Prepaid Expenses','Assets','Current Assets','Expenses paid in advance'],
        ['1-140','Office Supplies','Assets','Current Assets','Unused office supplies on hand'],
        ['1-200','Office Equipment','Assets','Non-Current Assets','Computers, printers, office equipment'],
        ['1-201','Accumulated Depreciation — Office Equipment','Assets','Non-Current Assets','Contra-asset: depreciation on office equipment'],
        ['1-210','Furniture and Fixtures','Assets','Non-Current Assets','Office furniture and built-in fixtures'],
        ['1-211','Accumulated Depreciation — Furniture and Fixtures','Assets','Non-Current Assets','Contra-asset: depreciation on furniture'],
        ['1-220','Service Vehicle','Assets','Non-Current Assets','Vehicles used for service delivery'],
        ['1-221','Accumulated Depreciation — Service Vehicle','Assets','Non-Current Assets','Contra-asset: depreciation on vehicles'],
        // LIABILITIES
        ['2-100','Accounts Payable','Liabilities','Current Liabilities','Amounts owed to suppliers/vendors'],
        ['2-110','Notes Payable','Liabilities','Current Liabilities','Short-term notes payable'],
        ['2-120','Accrued Expenses Payable','Liabilities','Current Liabilities','Expenses incurred but not yet paid'],
        ['2-130','Unearned Revenue','Liabilities','Current Liabilities','Advance payments received from clients'],
        ['2-140','SSS / PhilHealth / Pag-IBIG Payable','Liabilities','Current Liabilities','Government contributions payable'],
        ['2-150','Income Tax Payable','Liabilities','Current Liabilities','Estimated income tax owed'],
        ['2-200','Long-term Notes Payable','Liabilities','Non-Current Liabilities','Notes payable due beyond one year'],
        // EQUITY
        ['3-100',"Owner's Capital",'Equity',"Owner's Capital",'Initial and additional investments by owner'],
        ['3-200',"Owner's Withdrawals",'Equity','Withdrawals','Drawings by owner for personal use'],
        ['3-300','Retained Earnings','Equity','Retained Earnings','Accumulated earnings retained in the business'],
        // REVENUE
        ['4-100','Service Revenue','Revenue','Service Revenue','Revenue earned from rendering services'],
        ['4-110','Professional Fees','Revenue','Service Revenue','Fees for professional services rendered'],
        ['4-900','Other Income','Revenue','Service Revenue','Miscellaneous income'],
        // EXPENSES
        ['5-100','Salaries and Wages Expense','Expenses','Operating Expenses','Salaries paid to employees'],
        ['5-110','Rent Expense','Expenses','Operating Expenses','Monthly office/space rental'],
        ['5-120','Utilities Expense','Expenses','Operating Expenses','Electricity, water, internet'],
        ['5-130','Office Supplies Expense','Expenses','Operating Expenses','Office supplies used up'],
        ['5-140','Depreciation Expense','Expenses','Operating Expenses','Depreciation on property and equipment'],
        ['5-150','Insurance Expense','Expenses','Operating Expenses','Business insurance premiums'],
        ['5-160','Advertising Expense','Expenses','Operating Expenses','Marketing and advertising costs'],
        ['5-170','Communication Expense','Expenses','Operating Expenses','Phone, internet, postage'],
        ['5-180','Transportation Expense','Expenses','Operating Expenses','Fuel and transport costs'],
        ['5-190','SSS / PhilHealth / Pag-IBIG Expense','Expenses','Operating Expenses','Employer share of government contributions'],
        ['5-900','Miscellaneous Expense','Expenses','Other Expenses','Small/sundry expenses'],
    ];
}

function get_merchandising_accounts(): array {
    return [
        // ASSETS
        ['1-100','Cash on Hand','Assets','Current Assets','Cash available on premises'],
        ['1-101','Cash in Bank','Assets','Current Assets','Cash deposited in bank'],
        ['1-110','Accounts Receivable','Assets','Current Assets','Amounts due from customers'],
        ['1-120','Notes Receivable','Assets','Current Assets','Written promises to receive payment'],
        ['1-130','Merchandise Inventory','Assets','Current Assets','Goods available for sale'],
        ['1-140','Prepaid Expenses','Assets','Current Assets','Expenses paid in advance'],
        ['1-150','Office Supplies','Assets','Current Assets','Unused office supplies on hand'],
        ['1-160','Store Supplies','Assets','Current Assets','Unused store/selling supplies'],
        ['1-200','Store Equipment','Assets','Non-Current Assets','Cash registers, display units'],
        ['1-201','Accumulated Depreciation — Store Equipment','Assets','Non-Current Assets','Contra-asset for store equipment'],
        ['1-210','Office Equipment','Assets','Non-Current Assets','Computers and office machinery'],
        ['1-211','Accumulated Depreciation — Office Equipment','Assets','Non-Current Assets','Contra-asset for office equipment'],
        ['1-220','Delivery Equipment','Assets','Non-Current Assets','Vehicles used for delivery'],
        ['1-221','Accumulated Depreciation — Delivery Equipment','Assets','Non-Current Assets','Contra-asset for delivery equipment'],
        // LIABILITIES
        ['2-100','Accounts Payable','Liabilities','Current Liabilities','Amounts owed to suppliers'],
        ['2-110','Notes Payable','Liabilities','Current Liabilities','Short-term written debt obligations'],
        ['2-120','Accrued Expenses Payable','Liabilities','Current Liabilities','Expenses incurred but unpaid'],
        ['2-130','Unearned Revenue','Liabilities','Current Liabilities','Advance payments received'],
        ['2-140','SSS / PhilHealth / Pag-IBIG Payable','Liabilities','Current Liabilities','Government contributions owed'],
        ['2-150','Income Tax Payable','Liabilities','Current Liabilities','Income tax owed'],
        ['2-200','Long-term Notes Payable','Liabilities','Non-Current Liabilities','Debt due in more than one year'],
        // EQUITY
        ['3-100',"Owner's Capital",'Equity',"Owner's Capital",'Owner investment in the business'],
        ['3-200',"Owner's Withdrawals",'Equity','Withdrawals','Drawings by owner'],
        ['3-300','Retained Earnings','Equity','Retained Earnings','Accumulated earnings retained'],
        // REVENUE
        ['4-100','Sales','Revenue','Sales Revenue','Revenue from selling merchandise'],
        ['4-110','Sales Returns and Allowances','Revenue','Sales Revenue','Contra-revenue: goods returned by customers'],
        ['4-120','Sales Discounts','Revenue','Sales Revenue','Contra-revenue: discounts given to customers'],
        ['4-900','Other Income','Revenue','Sales Revenue','Miscellaneous income'],
        // COST OF GOODS / EXPENSES
        ['5-100','Purchases','Expenses','Operating Expenses','Cost of merchandise purchased'],
        ['5-110','Purchase Returns and Allowances','Expenses','Operating Expenses','Contra: goods returned to suppliers'],
        ['5-120','Purchase Discounts','Expenses','Operating Expenses','Contra: discounts received from suppliers'],
        ['5-130','Freight-in','Expenses','Operating Expenses','Transportation cost on purchases'],
        ['5-140','Cost of Goods Sold','Expenses','Operating Expenses','Cost of merchandise sold to customers'],
        ['5-200','Salaries and Wages Expense','Expenses','Operating Expenses','Salaries of all employees'],
        ['5-210','Rent Expense','Expenses','Operating Expenses','Store and office rental'],
        ['5-220','Utilities Expense','Expenses','Operating Expenses','Electricity, water, internet'],
        ['5-230','Depreciation Expense','Expenses','Operating Expenses','Depreciation on all fixed assets'],
        ['5-240','Advertising and Promotion Expense','Expenses','Operating Expenses','Marketing and promotions'],
        ['5-250','Store Supplies Expense','Expenses','Operating Expenses','Store supplies used'],
        ['5-260','Office Supplies Expense','Expenses','Operating Expenses','Office supplies used'],
        ['5-270','Insurance Expense','Expenses','Operating Expenses','Insurance premiums expensed'],
        ['5-280','Transportation and Delivery Expense','Expenses','Operating Expenses','Delivery and freight-out costs'],
        ['5-290','SSS / PhilHealth / Pag-IBIG Expense','Expenses','Operating Expenses','Employer government contributions'],
        ['5-900','Miscellaneous Expense','Expenses','Other Expenses','Sundry and miscellaneous expenses'],
    ];
}

function get_manufacturing_accounts(): array {
    return [
        // ASSETS
        ['1-100','Cash on Hand','Assets','Current Assets','Cash available on premises'],
        ['1-101','Cash in Bank','Assets','Current Assets','Cash deposited in bank'],
        ['1-110','Accounts Receivable','Assets','Current Assets','Amounts due from customers'],
        ['1-120','Raw Materials Inventory','Assets','Current Assets','Materials to be used in production'],
        ['1-130','Work in Process Inventory','Assets','Current Assets','Partially completed units in production'],
        ['1-140','Finished Goods Inventory','Assets','Current Assets','Completed units ready for sale'],
        ['1-150','Factory Supplies','Assets','Current Assets','Supplies used on the factory floor'],
        ['1-160','Prepaid Expenses','Assets','Current Assets','Expenses paid in advance'],
        ['1-200','Factory Land','Assets','Non-Current Assets','Land where the factory is located'],
        ['1-210','Factory Building','Assets','Non-Current Assets','The manufacturing plant building'],
        ['1-211','Accumulated Depreciation — Factory Building','Assets','Non-Current Assets','Contra-asset for factory building'],
        ['1-220','Factory Machinery and Equipment','Assets','Non-Current Assets','Production equipment and machinery'],
        ['1-221','Accumulated Depreciation — Machinery','Assets','Non-Current Assets','Contra-asset for machinery'],
        ['1-230','Office Equipment','Assets','Non-Current Assets','Computers, printers etc.'],
        ['1-231','Accumulated Depreciation — Office Equipment','Assets','Non-Current Assets','Contra-asset for office equipment'],
        // LIABILITIES
        ['2-100','Accounts Payable','Liabilities','Current Liabilities','Amounts owed to suppliers'],
        ['2-110','Accrued Factory Payroll','Liabilities','Current Liabilities','Unpaid wages of factory workers'],
        ['2-120','Accrued Expenses Payable','Liabilities','Current Liabilities','Other accrued expenses'],
        ['2-130','SSS / PhilHealth / Pag-IBIG Payable','Liabilities','Current Liabilities','Government contributions owed'],
        ['2-140','Income Tax Payable','Liabilities','Current Liabilities','Income tax owed'],
        ['2-200','Notes Payable — Long-term','Liabilities','Non-Current Liabilities','Long-term debt obligations'],
        ['2-210','Mortgage Payable','Liabilities','Non-Current Liabilities','Factory building mortgage'],
        // EQUITY
        ['3-100',"Owner's Capital",'Equity',"Owner's Capital",'Owner investment in the business'],
        ['3-200',"Owner's Withdrawals",'Equity','Withdrawals','Drawings by owner'],
        ['3-300','Retained Earnings','Equity','Retained Earnings','Accumulated earnings retained'],
        // REVENUE
        ['4-100','Sales','Revenue','Sales Revenue','Revenue from selling manufactured products'],
        ['4-110','Sales Returns and Allowances','Revenue','Sales Revenue','Contra: goods returned by customers'],
        ['4-120','Sales Discounts','Revenue','Sales Revenue','Contra: discounts to customers'],
        // MANUFACTURING COSTS
        ['5-100','Raw Materials Purchases','Expenses','Operating Expenses','Cost of raw materials bought'],
        ['5-110','Direct Labor','Expenses','Operating Expenses','Wages of workers directly on production'],
        ['5-120','Factory Overhead — Indirect Labor','Expenses','Operating Expenses','Wages of indirect factory workers'],
        ['5-130','Factory Overhead — Depreciation','Expenses','Operating Expenses','Depreciation of factory assets'],
        ['5-140','Factory Overhead — Utilities','Expenses','Operating Expenses','Factory electricity, water, gas'],
        ['5-150','Factory Overhead — Supplies','Expenses','Operating Expenses','Factory supplies used'],
        ['5-160','Factory Overhead — Insurance','Expenses','Operating Expenses','Insurance on factory assets'],
        ['5-170','Cost of Goods Sold','Expenses','Operating Expenses','Total cost of goods manufactured and sold'],
        // OPERATING EXPENSES
        ['5-200','Selling Expenses — Salaries','Expenses','Operating Expenses','Sales staff salaries'],
        ['5-210','Selling Expenses — Advertising','Expenses','Operating Expenses','Advertising and promotions'],
        ['5-220','Selling Expenses — Delivery','Expenses','Operating Expenses','Delivery costs to customers'],
        ['5-300','Admin Expenses — Salaries','Expenses','Operating Expenses','Administrative salaries'],
        ['5-310','Admin Expenses — Office Supplies','Expenses','Operating Expenses','Office supplies consumed'],
        ['5-320','Admin Expenses — Depreciation','Expenses','Operating Expenses','Depreciation on admin assets'],
        ['5-330','Admin Expenses — Utilities','Expenses','Operating Expenses','Office utilities'],
        ['5-340','Admin Expenses — Rent','Expenses','Operating Expenses','Admin office rent'],
        ['5-900','Miscellaneous Expense','Expenses','Other Expenses','Other miscellaneous expenses'],
    ];
}

function get_accounts_by_type(string $type): array {
    return match($type) {
        'Service'       => get_service_accounts(),
        'Merchandising' => get_merchandising_accounts(),
        'Manufacturing' => get_manufacturing_accounts(),
        default         => get_service_accounts(),
    };
}

function seed_accounts(mysqli $db, int $company_id, string $business_type): void {
    $accounts = get_accounts_by_type($business_type);
    $stmt = $db->prepare("
        INSERT INTO accounts (company_id, code, name, category, sub_category, description, opening_balance)
        VALUES (?, ?, ?, ?, ?, ?, 0)
    ");
    foreach ($accounts as [$code, $name, $cat, $sub, $desc]) {
        $stmt->bind_param('isssss', $company_id, $code, $name, $cat, $sub, $desc);
        $stmt->execute();
    }
}
