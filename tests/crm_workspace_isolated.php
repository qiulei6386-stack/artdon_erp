<?php
declare(strict_types=1);
// Original functions, in-memory statements. No bootstrap, configuration or DB.
if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only');
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function original(string $file, string $name): string {
    $source = file_get_contents(dirname(__DIR__) . '/' . $file);
    check((bool)preg_match('/^function ' . preg_quote($name, '/') . '\(/m', $source, $match, PREG_OFFSET_CAPTURE), 'Missing ' . $name);
    $start = $match[0][1]; $end = strpos($source, "\nfunction ", $start + 1);
    return substr($source, $start, $end === false ? null : $end - $start);
}
class WorkspaceDb {
    public array $queries = []; public array $rows = []; public array $fixtures = [];
    public function prepare(string $sql) { return new WorkspaceStatement($this, $sql); }
    public function exec(string $sql) { throw new RuntimeException('Unexpected write'); }
}
class WorkspaceStatement {
    public WorkspaceDb $db; public string $sql;
    public function __construct($db,$sql) {$this->db=$db;$this->sql=$sql;}
    public function execute(array $params=[]): void { $this->db->queries[] = [$this->sql,$params]; }
    public function fetchAll(): array {
        foreach ($this->db->fixtures as $match => $rows) if (str_contains($this->sql, $match)) return $rows;
        return $this->db->rows;
    }
    public function fetchColumn() { return 4; }
}
$GLOBALS['workspaceDb'] = new WorkspaceDb();
function db() { return $GLOBALS['workspaceDb']; }
function crm_require(string $permission): void { $GLOBALS['permissions'][]=$permission; }
function current_user(): array {return ['id'=>9];}
function has_permission($permission): bool { return in_array($permission,$GLOBALS['granted'] ?? [],true); }
function crm_mail_current_account($required): ?array { return $GLOBALS['fixtureAccount'] ?? null; }
function crm_task_scope_sql($alias): string {return 't.assigned_user_id=9';}
function crm_sample_scope_sql($alias): string {return 's.owner_user_id=9';}
function crm_visit_scope_sql($alias): string {return 'v.owner_user_id=9';}
function crm_customer_scope_sql(array &$params): string {$params[]=9;return 'c.owner_user_id=?';}
function crm_task_center_stats(): array {return [];}
function crm_task_center_options(): array {return [];}
function crm_visit_stats(): array {return [];}
function db_table_exists($table): bool {return false;}
function crm_table_exists_safe($table): bool {return true;}
function crm_task_quote_flow_summary($search): array {return [];}
foreach (['crm_task_center.php'=>'crm_task_center_ensure_tables','crm_visit.php'=>'crm_visit_ensure_tables','crm_mail.php'=>'crm_mail_ensure_tables','crm_customer.php'=>'crm_customer_ensure_tables','crm_opportunity.php'=>'crm_opportunity_ensure_tables','crm_marketing.php'=>'crm_marketing_ensure_tables','crm_ai.php'=>'crm_ai_ensure_tables','crm_settings_config.php'=>'crm_settings_ensure_tables','crm_ui.php'=>'crm_ui_ensure_tables'] as $file=>$name) {
    eval(original($file,$name)); $GLOBALS['crm_schema_ready']=true; $name();
}
check(db()->queries===[], 'Validated schema must not execute maintenance again');
foreach (['crm_task_center_list','crm_sample_shipments','crm_task_customer_options','crm_task_customer_contacts'] as $name) eval(original('crm_task_center.php',$name));
eval(original('crm_visit.php','crm_visit_list'));
foreach (['crm_customer_shipment_items','crm_customer_shipment_cartons'] as $name) eval(original('crm_customer.php',$name));
foreach (['crm_task_center_list','crm_visit_list','crm_sample_shipments'] as $name) {
    db()->rows=array_fill(0,51,['id'=>1]); db()->queries=[];
    $data=$name(['page'=>7,'page_size'=>50,'view'=>'all']);
    check(count($data['rows'])===50 && $data['has_more'] && $data['page']===7,'Pagination lost row 301: '.$name);
    check(str_contains(db()->queries[0][0],'LIMIT 51 OFFSET 300'),'Bounded look-ahead/offset missing');
    db()->rows=[];$data=$name(['page'=>1,'page_size'=>50,'view'=>'all']);check(!$data['has_more'],'Empty page has_more');
}
db()->queries=[];crm_task_customer_options(['q'=>'a']);
check(str_contains(db()->queries[0][0],'c.owner_user_id=?') && db()->queries[0][1][0]===9,'Search scope lost');
db()->queries=[];crm_task_customer_contacts(202);
check(db()->queries[0][1]===[202,9] && str_contains(db()->queries[0][0],'c.deleted_at IS NULL'),'Contact scope lost');
foreach (['crm_customer_shipment_items','crm_customer_shipment_cartons'] as $name) {
    db()->queries=[];$name(12,34);
    check(!str_contains(db()->queries[0][0],'OR order_id') && db()->queries[0][1]===[12],'Different shipment rows can mix');
    db()->queries=[];$name(0,34);check(db()->queries===[],'Missing shipment must not load order-wide data');
}
eval(original('crm_ai.php','crm_ai_confirm_interface_task'));
try {crm_ai_confirm_interface_task(['task_type'=>'quote_draft']);throw new LogicException('Unimplemented draft confirmed');}
catch(RuntimeException $e) {check(str_contains($e->getMessage(),'未生成'),'Wrong draft failure');}
eval(original('crm_customer.php','crm_contact_list'));
db()->queries=[];
db()->fixtures=[
    'SELECT * FROM crm_contacts' => [['id'=>1],['id'=>2]],
    'FROM crm_contact_role_tags' => [['contact_id'=>2,'role_key'=>'buyer']],
    'FROM crm_contact_sources' => [['contact_id'=>1,'source_key'=>'web']],
    'FROM crm_contact_promotions' => [['contact_id'=>1,'channel'=>'email','status'=>'active'],['contact_id'=>2,'channel'=>'phone','status'=>'paused']],
];
$contacts=crm_contact_list(['customer_id'=>8])['rows'];
check(count(db()->queries)===4,'Contact graph query count must not grow per contact');
check($contacts[0]['role_tags']===[] && $contacts[1]['role_tags']===['buyer'],'Contact roles mixed');
check($contacts[0]['source_tags']===['web'] && $contacts[1]['source_tags']===[],'Contact sources mixed');
check($contacts[0]['promotion_channels']===['email'] && $contacts[1]['promotion_channels']===[],'Active-channel semantics changed');
check($contacts[1]['promotions'][0]['status']==='paused' && $contacts[1]['promote']===0,'Paused promotion lost');
db()->fixtures=['SELECT * FROM crm_contacts'=>[]];db()->queries=[];
crm_contact_list(['customer_id'=>8]);check(count(db()->queries)===1,'Empty contact graph must not query');
eval(original('crm_ui.php','crm_workspace_pending_summary'));
db()->queries=[];$granted=[];
check(crm_workspace_pending_summary()===[] && db()->queries===[], 'No module permission means no pending-data queries');
$granted=['task.view','mail.view'];$fixtureAccount=['id'=>23,'user_id'=>9];db()->queries=[];
$pending=crm_workspace_pending_summary();
check(array_column($pending,'key')===['tasks','mail_issues','mail_unreplied'], 'Only supported pending routes');
check(db()->queries[0][1]===[9,9] && str_contains(db()->queries[0][0],'t.assigned_user_id=9') && str_contains(db()->queries[0][0],"NOT IN ('done','closed','cancelled')"), 'Pending tasks retain ownership and visibility scope');
check(db()->queries[1][1]===[9,23] && db()->queries[2][1]===[9,23], 'Mail pending data stays in current account');
$fixtureAccount=['id'=>24,'user_id'=>10];db()->queries=[];
check(count(crm_workspace_pending_summary())===1 && count(db()->queries)===1, 'Foreign account cannot expose mail counts');
db()->rows=[];db()->queries=[];crm_task_center_list(['view'=>'my_pending']);
check(str_contains(db()->queries[0][0],"NOT IN ('done','closed','cancelled')") && str_contains(db()->queries[0][0],'t.assigned_user_id=9'), 'Pending card destination preserves same completion and permission filter');
echo "CRM workspace pagination, pending counts, permissions, scope, shipment and unavailable-result safety: OK\n";
