<?php
require_once __DIR__ . '/../database/DBConnection.php';
require_once __DIR__ . '/../api/AccountingEngine.php';
require_once __DIR__ . '/../api/reference_helper.php';

$db = db();
$engine = AccountingEngine::getInstance();

$jvs_data = [
    174 => [
        'memo' => 'Opening receivable and payable',
        'lines' => [
            ['account_id' => 6, 'debit' => 429015.00, 'credit' => 0.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1],
            ['account_id' => 38, 'debit' => 0.00, 'credit' => 429015.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1]
        ]
    ],
    30 => [
        'memo' => 'Loan taken from credit card to buy goods',
        'lines' => [
            ['account_id' => 7, 'debit' => 33448.00, 'credit' => 0.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1],
            ['account_id' => 43, 'debit' => 0.00, 'credit' => 33448.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1]
        ]
    ],
    80 => [
        'memo' => 'Opening Stock & Valuation Balance',
        'lines' => [
            ['account_id' => 7, 'debit' => 276129.37, 'credit' => 0.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1],
            ['account_id' => 38, 'debit' => 0.00, 'credit' => 276129.37, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1]
        ]
    ],
    99 => [
        'memo' => 'Salary got and invested to bring mustang, highlander, gorkha, mustang',
        'lines' => [
            ['account_id' => 2, 'debit' => 118000.00, 'credit' => 0.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1],
            ['account_id' => 21, 'debit' => 0.00, 'credit' => 118000.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1]
        ]
    ],
    100 => [
        'memo' => 'CA Audit Adjustment: Statutory Annual Depreciation on Fixed Assets (10% p.a.)',
        'lines' => [
            ['account_id' => 29, 'debit' => 18740.00, 'credit' => 0.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1],
            ['account_id' => 10, 'debit' => 0.00, 'credit' => 18740.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1]
        ]
    ],
    108 => [
        'memo' => 'Adjustment of cash and bank',
        'lines' => [
            ['account_id' => 3, 'debit' => 6806.00, 'credit' => 0.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1],
            ['account_id' => 2, 'debit' => 0.00, 'credit' => 6806.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1]
        ]
    ],
    114 => [
        'memo' => 'for fixed deposit transfer',
        'lines' => [
            ['account_id' => 5, 'debit' => 3000.00, 'credit' => 0.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1],
            ['account_id' => 3, 'debit' => 0.00, 'credit' => 3000.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1]
        ]
    ],
    122 => [
        'memo' => 'recharge done',
        'lines' => [
            ['account_id' => 32, 'debit' => 102.25, 'credit' => 0.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1],
            ['account_id' => 4, 'debit' => 0.00, 'credit' => 102.25, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1]
        ]
    ],
    140 => [
        'memo' => 'Rent and Electricity Expenses 11000 rent and 1000 Electricity',
        'lines' => [
            ['account_id' => 30, 'debit' => 11000.00, 'credit' => 0.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1],
            ['account_id' => 32, 'debit' => 1000.00, 'credit' => 0.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1],
            ['account_id' => 2, 'debit' => 0.00, 'credit' => 12000.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1]
        ]
    ],
    163 => [
        'memo' => 'CA Audit Adjustment: Clear Opening Balance Suspense Account to Retained Earnings',
        'lines' => [
            ['account_id' => 38, 'debit' => 374671.00, 'credit' => 0.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1],
            ['account_id' => 22, 'debit' => 0.00, 'credit' => 374671.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1]
        ]
    ],
    177 => [
        'memo' => 'For fridge bought',
        'lines' => [
            ['account_id' => 8, 'debit' => 7700.00, 'credit' => 0.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1],
            ['account_id' => 2, 'debit' => 0.00, 'credit' => 7700.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1]
        ]
    ],
    231 => [
        'memo' => 'Initial investment: Loans from Sahakari, Mamu, Sharmila & Sanjay, Shutter Cost, and Operating Expenses',
        'lines' => [
            ['account_id' => 9, 'debit' => 400000.00, 'credit' => 0.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1],
            ['account_id' => 2, 'debit' => 496792.00, 'credit' => 0.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1],
            ['account_id' => 16, 'debit' => 0.00, 'credit' => 300000.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1],
            ['account_id' => 17, 'debit' => 0.00, 'credit' => 200000.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1],
            ['account_id' => 18, 'debit' => 0.00, 'credit' => 200000.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1],
            ['account_id' => 19, 'debit' => 0.00, 'credit' => 196792.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1]
        ]
    ],
    236 => [
        'memo' => 'System Opening Balances',
        'lines' => [
            ['account_id' => 2, 'debit' => 120000.00, 'credit' => 0.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1],
            ['account_id' => 3, 'debit' => 254671.00, 'credit' => 0.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1],
            ['account_id' => 38, 'debit' => 0.00, 'credit' => 374671.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1]
        ]
    ],
    143134 => [
        'memo' => 'Bank Loan Settlement & Working Capital',
        'lines' => [
            ['account_id' => 42, 'debit' => 53385.00, 'credit' => 0.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1],
            ['account_id' => 3, 'debit' => 0.00, 'credit' => 53385.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1]
        ]
    ],
    158622 => [
        'memo' => 'credit card loan payment',
        'lines' => [
            ['account_id' => 43, 'debit' => 10941.00, 'credit' => 0.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1],
            ['account_id' => 3, 'debit' => 0.00, 'credit' => 10941.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1]
        ]
    ],
    228767 => [
        'memo' => 'Cash & eSewa Transfer Settlement',
        'lines' => [
            ['account_id' => 3, 'debit' => 12680.00, 'credit' => 0.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1],
            ['account_id' => 4, 'debit' => 0.00, 'credit' => 12680.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1]
        ]
    ],
    643775 => [
        'memo' => 'Capital Investment in Bank',
        'lines' => [
            ['account_id' => 3, 'debit' => 20100.00, 'credit' => 0.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1],
            ['account_id' => 21, 'debit' => 0.00, 'credit' => 20100.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1]
        ]
    ],
    708565 => [
        'memo' => 'for balance maintanence',
        'lines' => [
            ['account_id' => 3, 'debit' => 6625.01, 'credit' => 0.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1],
            ['account_id' => 24, 'debit' => 0.00, 'credit' => 6625.01, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1]
        ]
    ],
    797608 => [
        'memo' => 'Recharge used on mobile',
        'lines' => [
            ['account_id' => 32, 'debit' => 650.00, 'credit' => 0.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1],
            ['account_id' => 4, 'debit' => 0.00, 'credit' => 650.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1]
        ]
    ],
    798903 => [
        'memo' => 'Recharge bonus',
        'lines' => [
            ['account_id' => 4, 'debit' => 204.00, 'credit' => 0.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1],
            ['account_id' => 24, 'debit' => 0.00, 'credit' => 204.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1]
        ]
    ],
    964351 => [
        'memo' => 'Inventory & Freight Adjustment',
        'lines' => [
            ['account_id' => 41, 'debit' => 7705.00, 'credit' => 0.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1],
            ['account_id' => 2, 'debit' => 0.00, 'credit' => 7705.00, 'entity_type' => 'NONE', 'entity_id' => null, 'location_id' => 1]
        ]
    ]
];

foreach ($jvs_data as $id => $info) {
    $hdr = $db->fetchOne("SELECT * FROM transaction_headers WHERE id = ?", [$id]);
    if (!$hdr) {
        echo "Txn ID $id not found, skipping.\n";
        continue;
    }
    
    // Delete existing if any
    $engine->deleteJournalForTransaction($id);
    
    // Post lines
    $engine->postJournalEntry($id, 'JOURNAL', $info['lines'], $hdr['txn_date'], $info['memo']);
    
    // Calculate total debit
    $tot_dr = array_sum(array_column($info['lines'], 'debit'));
    $db->execute("UPDATE transaction_headers SET net_amount = ?, memo = ?, status = 'posted' WHERE id = ?", [$tot_dr, $info['memo'], $id]);
    echo "[SUCCESS] Populated lines for ID {$id} ({$hdr['txn_number']}) - Net Amount: Rs. " . number_format($tot_dr, 2) . "\n";
}

echo "\nAll journal entries have been populated with balanced double-entry lines!\n";
