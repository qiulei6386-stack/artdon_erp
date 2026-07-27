<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Artdon\CommercialCenter\Services\QuotePermissionService;
use Artdon\CommercialCenter\Services\QuoteWorkflowService;

if (($argv[1] ?? '') !== '--write-test') {
    echo "PASS: workflow test loaded; use --write-test after migration.\n";
    exit(0);
}

$connection = db();
$required = ['cc_quote_approvals', 'cc_quote_state_history', 'cc_quote_audit_logs'];
$placeholders = implode(',', array_fill(0, count($required), '?'));
$check = $connection->prepare(
    "SELECT TABLE_NAME FROM information_schema.TABLES
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ({$placeholders})"
);
$check->execute($required);
if (count($check->fetchAll(PDO::FETCH_COLUMN)) !== count($required)) {
    fwrite(STDERR, "Quote workflow migration has not been applied.\n");
    exit(1);
}

$workflow = new QuoteWorkflowService();
$actor = [
    'id' => 0,
    'username' => 'step4-test',
    'display_name' => 'STEP 4 TEST',
    'is_test_actor' => true,
    'test_permissions' => QuotePermissionService::ACTIONS,
];
$deniedActor = [
    'id' => 0,
    'username' => 'step4-denied',
    'is_test_actor' => true,
    'test_permissions' => ['view'],
];
$quoteIds = [];

try {
    $quote = $workflow->createDraft([
        'quote_type' => 'standard_product',
        'currency' => 'USD',
        'customer_snapshot' => ['company' => 'STEP 4 TEST CUSTOMER'],
        'quote_date' => date('Y-m-d'),
        'is_test' => 1,
        'items' => [[
            'description' => 'Workflow test product',
            'model_no' => 'STEP4-WORKFLOW',
            'quantity' => 5,
            'unit_price' => 30,
            'unit_cost' => 12,
            'discount_rate' => 0.1,
            'configuration_snapshot' => ['driver' => 'TEST DRIVER'],
        ]],
        'shipping_amount' => 8,
        'tax_amount' => 2,
        'commission_amount' => 3,
        'payment_terms' => '30% deposit',
        'trade_terms' => 'EXW',
        'request_context' => ['ip' => '127.0.0.1', 'user_agent' => 'step4-smoke'],
    ], $actor);
    $quoteId = (int)$quote['id'];
    $quoteIds[] = $quoteId;

    $denied = false;
    try {
        $workflow->transition($quoteId, 'pricing', $deniedActor);
    } catch (Throwable) {
        $denied = true;
    }
    if (!$denied) {
        throw new RuntimeException('Permission denial was not enforced.');
    }

    $workflow->transition($quoteId, 'pricing', $actor);
    $workflow->transition($quoteId, 'pending_approval', $actor);
    $approved = $workflow->transition($quoteId, 'approved', $actor, 'Margin verified');
    $approvedVersion = (int)$approved['current_version'];
    if ($approvedVersion < 3) {
        throw new RuntimeException('Submission and approval did not generate versions.');
    }

    $revised = $workflow->reviseApproved($quoteId, [
        'quote_type' => 'standard_product',
        'currency' => 'USD',
        'customer_snapshot' => ['company' => 'STEP 4 TEST CUSTOMER'],
        'quote_date' => date('Y-m-d'),
        'is_test' => 1,
        'items' => [[
            'description' => 'Workflow test product revised',
            'model_no' => 'STEP4-WORKFLOW',
            'quantity' => 6,
            'unit_price' => 31,
            'unit_cost' => 12,
            'configuration_snapshot' => ['driver' => 'TEST DRIVER'],
        ]],
        'shipping_amount' => 8,
        'tax_amount' => 2,
        'commission_amount' => 3,
        'payment_terms' => '30% deposit',
        'trade_terms' => 'EXW',
    ], $actor, 'Customer quantity changed');
    if (($revised['status'] ?? '') !== 'draft' || (int)$revised['current_version'] <= $approvedVersion) {
        throw new RuntimeException('Approved quotation revision did not create a new draft version.');
    }

    $workflow->transition($quoteId, 'pending_approval', $actor);
    $workflow->transition($quoteId, 'approved', $actor, 'Revision verified');
    $workflow->transition($quoteId, 'sent', $actor);
    $workflow->transition($quoteId, 'customer_confirmed', $actor);
    $converted = $workflow->transition($quoteId, 'converted', $actor);
    if (($converted['status'] ?? '') !== 'converted') {
        throw new RuntimeException('Full workflow did not reach converted.');
    }

    $history = $workflow->history($quoteId, $actor);
    if (count($history) < 6) {
        throw new RuntimeException('Historical quotation versions are incomplete.');
    }
    $counts = [];
    foreach ([
        'cc_quote_state_history' => 'state',
        'cc_quote_approvals' => 'approval',
        'cc_quote_audit_logs' => 'audit',
        'cc_quote_snapshots' => 'snapshot',
    ] as $table => $key) {
        $statement = $connection->prepare("SELECT COUNT(*) FROM `{$table}` WHERE quote_id=?");
        $statement->execute([$quoteId]);
        $counts[$key] = (int)$statement->fetchColumn();
    }
    if ($counts['state'] < 8 || $counts['approval'] < 4 || $counts['audit'] < 9 || $counts['snapshot'] < 9) {
        throw new RuntimeException('Workflow logs, approvals or snapshots are incomplete.');
    }
    $formal = $connection->prepare(
        "SELECT snapshot_json FROM cc_quote_snapshots
         WHERE quote_id=? AND snapshot_type='approved' ORDER BY id DESC LIMIT 1"
    );
    $formal->execute([$quoteId]);
    $snapshot = json_decode((string)$formal->fetchColumn(), true);
    $requiredSnapshot = [
        $snapshot['customer_snapshot'] ?? null,
        $snapshot['items'][0]['configuration_snapshot'] ?? null,
        $snapshot['items'][0]['quantity'] ?? null,
        $snapshot['items'][0]['unit_price'] ?? null,
        $snapshot['shipping_amount'] ?? null,
        $snapshot['exchange_rate_snapshot'] ?? null,
        $snapshot['version']['terms_snapshot'] ?? null,
        $snapshot['total_amount'] ?? null,
        $snapshot['total_cost'] ?? null,
        $snapshot['gross_profit'] ?? null,
    ];
    if (in_array(null, $requiredSnapshot, true)) {
        throw new RuntimeException('Formal approved snapshot is missing frozen fields.');
    }

    echo "PASS: permissions, state transitions, approvals, audit logs, formal snapshots and version history verified.\n";
} finally {
    if ($quoteIds !== []) {
        $ids = implode(',', array_map('intval', $quoteIds));
        $versionIds = $connection->query("SELECT id FROM cc_quote_versions WHERE quote_id IN ({$ids})")
            ->fetchAll(PDO::FETCH_COLUMN);
        if ($versionIds !== []) {
            $versions = implode(',', array_map('intval', $versionIds));
            $itemIds = $connection->query("SELECT id FROM cc_quote_items WHERE quote_version_id IN ({$versions})")
                ->fetchAll(PDO::FETCH_COLUMN);
            if ($itemIds !== []) {
                $items = implode(',', array_map('intval', $itemIds));
                $connection->exec("DELETE FROM cc_quote_item_files WHERE quote_item_id IN ({$items})");
                $connection->exec("DELETE FROM cc_quote_item_snapshots WHERE quote_item_id IN ({$items})");
                $connection->exec("DELETE FROM cc_quote_item_adaptation_refs WHERE quote_item_id IN ({$items})");
                $connection->exec("DELETE FROM cc_quote_item_details WHERE quote_item_id IN ({$items})");
            }
            $connection->exec("DELETE FROM cc_quote_items WHERE quote_version_id IN ({$versions})");
        }
        $connection->exec("DELETE FROM cc_quote_approvals WHERE quote_id IN ({$ids})");
        $connection->exec("DELETE FROM cc_quote_state_history WHERE quote_id IN ({$ids})");
        $connection->exec("DELETE FROM cc_quote_audit_logs WHERE quote_id IN ({$ids})");
        $connection->exec("DELETE FROM cc_quote_snapshots WHERE quote_id IN ({$ids})");
        $connection->exec("DELETE FROM cc_quote_files WHERE quote_id IN ({$ids})");
        $connection->exec("DELETE FROM cc_quote_legacy_links WHERE quote_id IN ({$ids})");
        $connection->exec("DELETE FROM cc_quotation_logs WHERE quote_id IN ({$ids})");
        $connection->exec("DELETE FROM cc_quote_versions WHERE quote_id IN ({$ids})");
        $connection->exec("DELETE FROM cc_quote_channel_context WHERE quote_id IN ({$ids})");
        $connection->exec("DELETE FROM cc_quote_details WHERE quote_id IN ({$ids})");
        $connection->exec("DELETE FROM cc_quotes WHERE id IN ({$ids}) AND is_test=1");
    }
}
