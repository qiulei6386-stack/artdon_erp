<?php
declare(strict_types=1);
// Original workflow and task-save functions, synthetic statements only.
// No application bootstrap, configuration, real database or notifications.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!class_exists('PDOException')) { class PDOException extends RuntimeException {} }
set_error_handler(static function ($severity, $message, $file, $line) {throw new ErrorException($message, 0, $severity, $file, $line);});
function expect(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function original(string $file, string $name): string {
    $source = file_get_contents(dirname(__DIR__) . '/' . $file);
    $start = strpos($source, 'function ' . $name . '(');
    expect($start !== false, 'Missing original function ' . $name);
    $end = strpos($source, "\nfunction ", $start + 1);
    return substr($source, $start, $end === false ? null : $end - $start);
}
class WorkflowDb {
    public array $tasks = []; public int $last = 0; public bool $race = false; public bool $active = true; public bool $contact = true;
    public function prepare(string $sql) { return new WorkflowStatement($this, $sql); }
    public function lastInsertId(): string {return (string)$this->last;}
}
class WorkflowStatement {
    private WorkflowDb $db; private string $sql; private array $params = [];
    public function __construct($db, $sql) {$this->db=$db;$this->sql=$sql;}
    public function execute(array $params = []): void {
        $this->params=$params;
        if (str_starts_with($this->sql, 'INSERT INTO crm_tasks')) {
            $id=++$this->db->last;
            $keys=['task_type','title','description','source_type','source_id','customer_id','contact_id','opportunity_id','quote_id','assigned_user_id','priority','status','due_at','reminder_at','request_token','created_by'];
            $row=array_combine($keys,$params);$row['id']=$id;$this->db->tasks[$id]=$row;
            if ($this->db->race) {$this->db->race=false;throw new PDOException('Simulated concurrent unique-key winner',23000);}
        } elseif (str_starts_with($this->sql,'UPDATE crm_tasks')) {
            $id=array_pop($params);
            $keys=['task_type','title','description','source_type','source_id','customer_id','contact_id','opportunity_id','quote_id','assigned_user_id','priority','status','due_at','reminder_at'];
            $this->db->tasks[$id]=array_replace($this->db->tasks[$id],array_combine($keys,$params));
        } elseif (!str_starts_with($this->sql,'SELECT id FROM crm_')) {
            throw new RuntimeException('Unexpected SQL: ' . $this->sql);
        }
    }
    public function fetchColumn() {
        if (str_contains($this->sql,'FROM crm_users')) return $this->db->active && in_array($this->params[0],[9,11],true) ? $this->params[0] : false;
        if (str_contains($this->sql,'FROM crm_contacts')) return $this->db->contact && $this->params === [101,10] ? 101 : false;
        if (str_contains($this->sql,'FROM crm_tasks')) {
            foreach ($this->db->tasks as $row) if ($row['created_by']===$this->params[0] && $row['request_token']===$this->params[1]) return $row['id'];
            return false;
        }
        throw new RuntimeException('Unexpected query');
    }
}
$db = new WorkflowDb(); $denied=''; $denyOpportunity=false; $denyCustomer=false; $events=[];
function db() {global $db;return $db;}
function current_user(): array {return ['id'=>9];}
function crm_require(string $permission): void {global $denied;if($permission===$denied)throw new RuntimeException('permission denied');}
function crm_opportunity_detail(int $id): array {global $denyOpportunity;if($denyOpportunity||!in_array($id,[5,6],true))throw new RuntimeException('opportunity denied');return ['opportunity'=>['id'=>$id,'customer_id'=>10]];}
function crm_customer_get(int $id): array {global $denyCustomer;if($denyCustomer||$id!==10)throw new RuntimeException('customer denied');return ['customer'=>['id'=>10]];}
function crm_task_center_ensure_tables(): void {}
function crm_opportunity_ensure_tables(): void {}
function crm_task_datetime($value): ?string {return $value ? $value . ':00' : null;}
function crm_task_row(int $id): array {return db()->tasks[$id];}
function crm_log_event(...$args): void {global $events;$events[]=$args;}
function crm_customer_timeline_add(...$args): void {global $events;$events[]=$args;}
function crm_task_type_map(): array {return ['sample_task'=>'样品任务','material_task'=>'资料任务'];}
function crm_opportunity_stages(): array {throw new RuntimeException('unexpected: reached save after validation');}
eval(original('crm_task_center.php','crm_task_save'));
eval(original('crm_opportunity.php','crm_opportunity_create_task'));
eval(original('crm_opportunity.php','crm_opportunity_save'));
$input=['opportunity_id'=>5,'task_type'=>'sample_task','request_token'=>'op-task-test-token-123456','title'=>'Fixture sample','description'=>'Fixture only','due_at'=>'2026-09-07T12:30'];
$first=crm_opportunity_create_task($input);
expect($first['task']['id']===1,'Real task identity returned');
expect($first['task']['source_type']==='opportunity' && $first['task']['source_id']==='5','Source is opportunity');
expect($first['task']['opportunity_id']===5 && $first['task']['customer_id']===10,'Canonical business IDs retained');
expect($first['task']['assigned_user_id']===9,'Default assignee is current user');
expect(count($events)===2,'Task create log and customer timeline recorded');
$again=crm_opportunity_create_task($input);
expect($again['reused'] && $again['task']['id']===1 && count($db->tasks)===1,'Retry reuses original task');
expect(count($events)===2,'Retry does not duplicate timeline');
$material=crm_opportunity_create_task(array_replace($input,['task_type'=>'material_task','assigned_user_id'=>11]));
expect($material['task']['id']===2 && $material['task']['assigned_user_id']===11,'Other type has separate token namespace');
$other=crm_opportunity_create_task(array_replace($input,['opportunity_id'=>6,'customer_id'=>999,'contact_id'=>999,'source_type'=>'quote','source_id'=>'999','task_id'=>1,'status'=>'done','quote_id'=>'SECRET']));
expect($other['task']['id']===3 && $other['task']['customer_id']===10 && $other['task']['opportunity_id']===6,'Caller cannot forge IDs or edit unrelated task');
expect($other['task']['contact_id']===null && $other['task']['quote_id']==='' && $other['task']['status']==='pending','Unverified contact, quote and completed status excluded');
$db->race=true;
$race=crm_opportunity_create_task(array_replace($input,['request_token'=>'op-task-race-token-123456']));
expect($race['reused'] && count($db->tasks)===4,'Original task save handles simulated insert race');
$db->tasks[1]['status']='done';
$edited=crm_task_save(['task_id'=>1,'task_type'=>'sample_task','title'=>'Edited fixture','description'=>'Adjusted description','assigned_user_id'=>9]);
expect($edited['task']['source_type']==='opportunity' && $edited['task']['source_id']==='5' && $edited['task']['opportunity_id']===5 && $edited['task']['customer_id']===10,'Ordinary edit preserves business linkage');
expect($edited['task']['status']==='done','Ordinary edit cannot reopen a completed task by omission');
function rejects(array $input, string $message): void {
    $count=count(db()->tasks);$thrown=false;
    try {crm_opportunity_create_task($input);} catch (RuntimeException $e) {$thrown=true;}
    expect($thrown && count(db()->tasks)===$count,$message);
}
foreach (['opportunity.view','customer.view','task.create','task.view'] as $permission) {$denied=$permission;rejects($input,'Denied ' . $permission . ' must not write');}$denied='';
$denyOpportunity=true;rejects($input,'Scoped opportunity required');$denyOpportunity=false;
$denyCustomer=true;rejects($input,'Scoped customer required');$denyCustomer=false;
$db->active=false;rejects($input,'Disabled assignee rejected');$db->active=true;
rejects(array_replace($input,['assigned_user_id'=>999]),'Unknown assignee rejected');
foreach (['','bad','2026-02-30T12:30','2026-09-07T25:00','2026-09-07T12:30extra'] as $date) rejects(array_replace($input,['due_at'=>$date]),'Invalid due date rejected');
foreach (['','short',str_repeat('a',101),'op-task-token!invalid'] as $token) rejects(array_replace($input,['request_token'=>$token]),'Invalid token rejected');
rejects(array_replace($input,['task_type'=>'sample_shipment']),'Shipment cannot be falsely created');
rejects(array_replace($input,['title'=>'']),'Title is required by actual task save');
// A foreign contact and denied follow-up fail before any opportunity write.
foreach ([['contact_id'=>999],['contact_id'=>101,'create_followup'=>1]] as $case) {
    $denied=isset($case['create_followup'])?'follow.create':'';$thrown='';
    try {crm_opportunity_save(array_merge(['customer_id'=>10,'opportunity_name'=>'Fixture'],$case));} catch (RuntimeException $e) {$thrown=$e->getMessage();}
    expect($thrown!=='' && !str_contains($thrown,'unexpected:'),'Save validation must stop before writes');
}
$taskSource=file_get_contents(dirname(__DIR__).'/crm_task_center.php');
expect(str_contains($taskSource,'UNIQUE KEY uk_task_request (created_by, request_token)'),'Database creator/token uniqueness remains installed');
echo "crm_opportunity_workflow_isolated: scoped linkage, retry, simulated race and validation OK\n";
