<?php
declare(strict_types=1);

// Original list/timer functions plus an isolated API logging block only.
// No application bootstrap, real database, mail transport or log-file write.
if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only');
function timing_assert(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}
function timing_function(string $source, string $name): string
{
    timing_assert((bool)preg_match('/^function ' . preg_quote($name, '/') . '\(/m', $source, $match, PREG_OFFSET_CAPTURE), 'Missing original function');
    $start = $match[0][1];
    $end = strpos($source, "\nfunction ", $start + 1);
    return substr($source, $start, $end === false ? null : $end - $start);
}
$mailSource = file_get_contents(dirname(__DIR__) . '/crm_mail.php');
$apiSource = file_get_contents(dirname(__DIR__) . '/crm_api.php');
foreach (['crm_mail_list_perf_mark', 'crm_mail_list'] as $name) eval(timing_function($mailSource, $name));

class TimingMemoryDb
{
    public array $rows = [];
    public array $queries = [];
    public function prepare(string $sql): TimingMemoryStatement { return new TimingMemoryStatement($this, $sql); }
}
class TimingMemoryStatement
{
    private TimingMemoryDb $db;
    private string $sql;
    private array $rows = [];
    private int $total = 0;
    public function __construct(TimingMemoryDb $db, string $sql) { $this->db = $db; $this->sql = $sql; }
    public function execute(array $params): void
    {
        timing_assert(count($this->db->queries) < 4, 'Unbounded list query chain');
        timing_assert(strpos($this->sql, 'SELECT COUNT(*) FROM crm_mails m WHERE ') === 0 || strpos($this->sql, 'SELECT m.id, m.folder,') === 0, 'Unexpected query in isolated list');
        timing_assert($params[0] === 7 && $params[1] === 3, 'Account/user scope changed');
        $this->db->queries[] = ['sql' => $this->sql, 'params' => $params];
        $folder = strpos($this->sql, 'm.folder = ?') !== false ? $params[2] : null;
        if (strpos($this->sql, "m.folder = 'sent' AND m.is_unreplied = 1") !== false) $folder = 'sent';
        $this->rows = array_values(array_filter($this->db->rows, static fn($row) => $folder === null || $row['folder'] === $folder));
        if (preg_match('/m\.id NOT IN \(([0-9,]+)\)/', $this->sql, $match)) {
            $excluded = array_map('intval', explode(',', $match[1]));
            $this->rows = array_values(array_filter($this->rows, static fn($row) => !in_array($row['id'], $excluded, true)));
        }
        $this->total = count($this->rows);
        if (preg_match('/LIMIT ([0-9]+) OFFSET ([0-9]+)$/', $this->sql, $match)) {
            $this->rows = array_slice($this->rows, (int)$match[2], (int)$match[1]);
        }
    }
    public function fetchAll(): array { return $this->rows; }
    public function fetchColumn(): int { return $this->total; }
}
$timingDb = new TimingMemoryDb();
$timingAccount = null;
$timingCalls = [];
function db(): TimingMemoryDb { return $GLOBALS['timingDb']; }
function crm_require(string $permission): void
{
    timing_assert($permission === 'mail.view', 'List permission changed');
    $GLOBALS['timingCalls'][] = ['permission', $permission];
}
function crm_mail_current_account(bool $withSecret): ?array
{
    timing_assert(!$withSecret, 'List must not request decrypted credentials');
    return $GLOBALS['timingAccount'];
}
function crm_mail_visible_attachment_counts_for_rows(array $rows): array
{
    $GLOBALS['timingCalls'][] = ['attachments', array_column($rows, 'id')];
    return [101 => 0, 102 => 2, 103 => 1, 104 => 0];
}
function crm_mail_sent_duplicate_ids(array $account): array
{
    timing_assert($account['id'] === 3 && $account['user_id'] === 7, 'Visibility uses current authorized account');
    $GLOBALS['timingCalls'][] = ['sent_visibility', $account['id']];
    return [104];
}
function crm_mail_source_label(string $source, array $flags): string { return 'source:' . $source; }
function crm_mail_source_tags(string $source, array $flags, array $tags): array { return $tags; }
function crm_mail_category_detect(array $row): string { return 'normal'; }
function crm_mail_category_label(string $category): string { return 'Normal'; }
function crm_mail_repair_text(string $text): string { return $text; }
function crm_mail_folder_counts(array $account): array
{
    $GLOBALS['timingCalls'][] = ['folder_counts', $account['id']];
    return ['inbox' => 2, 'sent' => 2, 'unread' => 1];
}
function crm_mail_account_payload(array $account): array { return ['account' => $account]; }
function crm_mail_refresh_unreplied_flags(array $account, int $days): void { $GLOBALS['timingCalls'][] = ['unreplied', $days]; }
function crm_mail_scheduled_list(array $account, array $input): array
{
    $GLOBALS['timingCalls'][] = ['scheduled', $account['id'], $input];
    return ['branch' => 'scheduled', 'rows' => [['id' => 201]], 'total' => 1, 'page' => 2, 'page_size' => 7];
}
function crm_mail_draft_list(array $account, array $input): array
{
    $GLOBALS['timingCalls'][] = ['drafts', $account['id'], $input];
    return ['branch' => 'drafts', 'rows' => [['id' => 301]], 'total' => 1, 'page' => 2, 'page_size' => 7];
}
function timing_reset(): void
{
    $GLOBALS['timingDb'] = new TimingMemoryDb();
    $GLOBALS['timingAccount'] = ['id' => 3, 'user_id' => 7, 'email_address' => 'ACCOUNT_PRIVATE_MARKER@example.invalid'];
    $GLOBALS['timingCalls'] = [];
    $GLOBALS['crm_mail_list_segments'] = ['stale' => 123, 'subject' => 'STALE_PRIVATE_MARKER'];
    $base = ['id' => 101, 'folder' => 'inbox', 'message_uid' => 'imap_101', 'subject' => 'SUBJECT_PRIVATE_MARKER',
        'from_email' => 'sender@example.invalid', 'from_name' => 'Sender', 'to_emails' => 'recipient@example.invalid',
        'received_at' => '2026-09-06 10:00:00', 'sent_at' => null, 'body_text' => '<b>BODY_PRIVATE_MARKER</b>',
        'body_status' => 'parsed', 'has_body' => 1, 'has_attachment' => 1, 'attachment_count' => 99, 'visible_attachment_count' => 0,
        'is_read' => 0, 'is_replied' => 0, 'is_starred' => 0, 'is_unreplied' => 0, 'linked_customer_id' => null,
        'mail_source' => 'imap_inbox', 'source_flags_json' => '["flag"]', 'crm_send_id' => null, 'send_status' => null,
        'tags_json' => '["tag"]', 'linked_customer_name' => null];
    db()->rows = [$base,
        array_merge($base, ['id' => 102, 'message_uid' => 'imap_102', 'body_text' => '']),
        array_merge($base, ['id' => 103, 'folder' => 'sent', 'message_uid' => 'send_103', 'mail_source' => '']),
        array_merge($base, ['id' => 104, 'folder' => 'sent', 'message_uid' => 'imap_104', 'mail_source' => '', 'body_text' => str_repeat('x', 130)]),
    ];
}
function timing_expected_row(array $row, int $visibleAttachments, string $source, string $summary): array
{
    $row['source_flags'] = ['flag'];
    $row['source_label'] = 'source:' . $source;
    $row['tags'] = ['tag'];
    $row['mail_category'] = 'normal';
    $row['category_label'] = 'Normal';
    $row['has_attachment'] = $visibleAttachments > 0 ? 1 : 0;
    $row['attachment_count'] = $visibleAttachments;
    $row['summary'] = $summary;
    unset($row['tags_json'], $row['source_flags_json']);
    return $row;
}
$segmentNames = ['account_prepare','prepare_filters','count','list_query','attachment_counts','row_format','folder_counts','response_prepare'];
timing_reset();
$rows = db()->rows;
$result = crm_mail_list([]);
$expected = ['bound' => true, 'account' => $timingAccount,
    'rows' => [timing_expected_row($rows[0], 0, 'imap_inbox', 'BODY_PRIVATE_MARKER'), timing_expected_row($rows[1], 2, 'imap_inbox', '无正文 · 有附件')],
    'total' => 2, 'page' => 1, 'page_size' => 50, 'folder_counts' => null];
timing_assert($result === $expected, 'Timing changed ordinary list response');
timing_assert(count(db()->queries) === 2, 'Timing added a database query');
timing_assert(array_keys($GLOBALS['crm_mail_list_segments']) === $segmentNames, 'Segment set/order changed or retained stale values');
foreach ($GLOBALS['crm_mail_list_segments'] as $value) timing_assert(is_numeric($value) && is_finite((float)$value) && $value >= 0, 'Segment must be a finite nonnegative number');
timing_assert(!in_array('folder_counts', array_column($timingCalls, 0), true), 'Default list unexpectedly requested folder counts');
timing_assert(!array_key_exists('segments_ms', $result) && !array_key_exists('crm_mail_list_segments', $result), 'Internal timing leaked into list response');

timing_reset();
$page = crm_mail_list(['page' => 2, 'page_size' => 1, 'include_counts' => '1']);
timing_assert($page['page'] === 2 && $page['page_size'] === 1 && $page['total'] === 2 && array_column($page['rows'], 'id') === [102], 'Paging response changed');
timing_assert(strpos(db()->queries[1]['sql'], 'LIMIT 1 OFFSET 1') !== false && db()->queries[0]['params'] === db()->queries[1]['params'], 'Count/list paging or filters diverged');
timing_assert($page['folder_counts'] === ['inbox' => 2, 'sent' => 2, 'unread' => 1] && count(array_filter($timingCalls, static fn($c) => $c[0] === 'folder_counts')) === 1, 'include_counts must run once only when enabled');
timing_assert(in_array(['attachments', [102]], $timingCalls, true), 'Attachment counts must receive only current page');
timing_reset();
$capped = crm_mail_list(['folder' => 'all', 'page_size' => 1000, 'page' => 0, 'include_counts' => '0']);
timing_assert($capped['page_size'] === 200 && $capped['page'] === 1 && $capped['total'] === 4 && $capped['folder_counts'] === null, 'Upper paging bounds changed');
timing_assert($capped['rows'][2]['source_label'] === 'source:crm_sent' && $capped['rows'][3]['source_label'] === 'source:imap_sent' && $capped['rows'][3]['summary'] === str_repeat('x', 120), 'Sent source or summary formatting changed');
timing_reset();
$minimum = crm_mail_list(['page_size' => 0, 'page' => -3]);
timing_assert($minimum['page_size'] === 1 && $minimum['page'] === 1 && count($minimum['rows']) === 1, 'Lower paging bounds changed');
timing_reset();
crm_mail_list(['q' => 'QUERY_PRIVATE_MARKER']);
timing_assert(array_slice(db()->queries[0]['params'], 3) === array_fill(0, 6, '%QUERY_PRIVATE_MARKER%'), 'Search parameter binding changed');
timing_reset();
$sent = crm_mail_list(['folder' => 'sent']);
timing_assert($sent['total'] === 1 && array_column($sent['rows'], 'id') === [103], 'Same exclusions must apply to count and page');
timing_assert(count(array_filter($timingCalls, static fn($c) => $c[0] === 'sent_visibility')) === 1, 'Compute expensive sent visibility once per request');
timing_assert(isset($GLOBALS['crm_mail_list_segments']['sent_visibility']), 'Sent visibility has its own timing');
foreach (db()->queries as $query) timing_assert(str_contains($query['sql'], 'm.id NOT IN (104)') && !str_contains($query['sql'], 'SELECT 1 FROM crm_mails m2'), 'Count/page must reuse bounded ID exclusions instead of repeating legacy scan');

timing_reset(); $timingAccount = null;
timing_assert(crm_mail_list([]) === ['bound' => false, 'rows' => [], 'total' => 0, 'account' => null, 'folder_counts' => []], 'Unbound response changed');
timing_assert(db()->queries === [] && array_keys($GLOBALS['crm_mail_list_segments']) === ['account_prepare'], 'Unbound branch queried mail or retained stale timings');
foreach (['drafts' => 301, 'scheduled' => 201] as $branch => $id) {
    timing_reset();
    $input = ['folder' => $branch, 'page' => 2, 'page_size' => 7, 'include_counts' => '1'];
    $branchResult = crm_mail_list($input);
    timing_assert($branchResult === ['branch' => $branch, 'rows' => [['id' => $id]], 'total' => 1, 'page' => 2, 'page_size' => 7], 'Early folder branch response changed');
    timing_assert(in_array([$branch, 3, $input], $timingCalls, true) && db()->queries === [], 'Early branch arguments changed or normal list ran');
    timing_assert(array_keys($GLOBALS['crm_mail_list_segments']) === ['account_prepare'] && count($timingCalls) === 2, 'Early branch must not run formatting/counts');
}
$future = microtime(true) + 60;
$before = microtime(true);
crm_mail_list_perf_mark('test_clock', $future);
timing_assert($GLOBALS['crm_mail_list_segments']['test_clock'] === 0.0 && $future >= $before && $future <= microtime(true), 'Timer must clamp negative deltas and advance its reference');

// Evaluate just the numeric allowlist block, not the shutdown callback or writer.
$blockStart = strpos($apiSource, "    if (\$safeAction === 'mail_list') {");
$blockEnd = $blockStart === false ? false : strpos($apiSource, "    if (\$safeAction === 'customer_list'", $blockStart);
timing_assert($blockStart !== false && $blockEnd !== false, 'Mail logging block boundaries changed');
$logBlock = substr($apiSource, $blockStart, $blockEnd - $blockStart);
timing_assert((bool)preg_match('/foreach \(\[([^\]]+)\] as \$segment\)/', $logBlock, $whitelistMatch), 'Logging must use a literal field allowlist');
preg_match_all("/'([^']+)'/", $whitelistMatch[1], $keys);
timing_assert($keys[1] === array_merge(['account_prepare','sent_visibility'], array_slice($segmentNames, 1)), 'Logging allowlist must contain only the reviewed numeric segments');
foreach (['$_POST', '$_GET', '$_SESSION', 'file_put_contents', 'register_shutdown_function', 'email_address', 'subject', 'body_text', 'body_html', 'mail_account_id'] as $forbidden) {
    timing_assert(strpos($logBlock, $forbidden) === false, 'Mail timing log block reads or writes non-metric content');
}
function timing_log_payload(string $block, string $action, array $segments): array
{
    $GLOBALS['crm_mail_list_segments'] = $segments;
    $safeAction = $action;
    $payload = [];
    ob_start();
    try { eval($block); } finally { $output = ob_get_clean(); }
    timing_assert($output === '', 'Logging block must not print private input');
    return $payload;
}
$metrics = ['account_prepare' => '17.1234', 'prepare_filters' => -4, 'count' => INF, 'list_query' => NAN,
    'attachment_counts' => 'BODY_PRIVATE_MARKER', 'row_format' => ['SUBJECT_PRIVATE_MARKER'],
    'folder_counts' => 9.5, 'response_prepare' => null,
    'subject' => 'SUBJECT_PRIVATE_MARKER', 'email_address' => 'ACCOUNT_PRIVATE_MARKER@example.invalid',
    'body_html' => 'BODY_PRIVATE_MARKER', 'mail_account_id' => 123];
$logged = timing_log_payload($logBlock, 'mail_list', $metrics);
timing_assert($logged === ['segments_ms' => ['account_prepare' => 17.12, 'prepare_filters' => 0.0, 'folder_counts' => 9.5]], 'Numeric allowlist failed to reject strings/arrays/nonfinite values or extra fields');
timing_assert(strpos(json_encode($logged), 'PRIVATE_MARKER') === false, 'Private content leaked into metrics');
timing_assert(timing_log_payload($logBlock, 'customer_list', $metrics) === [], 'Mail metrics attached to another action');
timing_assert(timing_log_payload($logBlock, 'mail_list', []) === ['segments_ms' => []], 'Missing timing values fabricated metrics');
echo "crm_mail_list_timing_isolated: OK\n";
