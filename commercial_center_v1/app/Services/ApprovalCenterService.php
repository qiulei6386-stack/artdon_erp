<?php
declare(strict_types=1);
namespace Artdon\CommercialCenter\Services;
use Artdon\CommercialCenter\Repositories\QuoteRepository;
final class ApprovalCenterService{
 private \PDO $db;private QuotePermissionService $permissions;private QuoteWorkflowService $workflow;private QuoteRepository $quotes;
 public function __construct(){ $this->db=db();$this->permissions=new QuotePermissionService($this->db);$this->quotes=new QuoteRepository($this->db);$this->workflow=new QuoteWorkflowService(null,$this->quotes,null,$this->permissions);}
 public function queue(array $filters,array $actor):array{$this->permissions->assert($actor,'view');$where=["q.status IN ('pending_approval','approved','rejected')"];$p=[];
  foreach(['quote_type','status'] as $f)if(trim((string)($filters[$f]??''))!==''){$where[]="q.$f=?";$p[]=$filters[$f];}
  if(trim((string)($filters['customer']??''))!==''){$where[]='q.customer_snapshot LIKE ?';$p[]='%'.$filters['customer'].'%';}
  if(trim((string)($filters['owner']??''))!==''){$where[]='d.owner_name LIKE ?';$p[]='%'.$filters['owner'].'%';}
  $s=$this->db->prepare('SELECT q.id,q.quote_no,q.quote_type,q.status,q.total_amount,q.total_cost,q.currency,q.current_version,q.customer_snapshot,q.updated_at,d.owner_name,d.commission_amount,d.gross_margin FROM cc_quotes q LEFT JOIN cc_quote_details d ON d.quote_id=q.id WHERE '.implode(' AND ',$where).' ORDER BY q.updated_at DESC LIMIT 200');$s->execute($p);$rows=$s->fetchAll(\PDO::FETCH_ASSOC);
  foreach($rows as &$r){$q=$this->quotes->find((int)$r['id']);$r['customer']=json_decode((string)$r['customer_snapshot'],true)?:[];$r['risk']=$this->risk($q?:[]);unset($r['customer_snapshot']);}unset($r);return $rows;}
 public function detail(int $id,array $actor):array{$this->permissions->assert($actor,'view');$q=$this->workflow->open($id,$actor)??throw new \RuntimeException('报价不存在。');$q['risk']=$this->risk($q);$s=$this->db->prepare('SELECT * FROM cc_quote_review_actions WHERE quote_id=? ORDER BY id DESC');$s->execute([$id]);$q['review_actions']=$s->fetchAll(\PDO::FETCH_ASSOC);return $q;}
 public function act(int $id,string $action,string $opinion,string $target,array $actor):array{$q=$this->detail($id,$actor);$risk=$q['risk'];$this->permissions->assert($actor,'approve');
  if(in_array($action,['reject','request_changes'],true)&&trim($opinion)==='')throw new \InvalidArgumentException('驳回或要求修改必须填写意见。');
  if($action==='approve')$result=$this->workflow->transition($id,'approved',$actor,$opinion?:'审核通过');
  elseif(in_array($action,['reject','request_changes'],true))$result=$this->workflow->transition($id,'rejected',$actor,($action==='request_changes'?'要求修改：':'驳回：').$opinion);
  elseif($action==='escalate'){$result=$q;if(trim($target)==='')throw new \InvalidArgumentException('请选择上级审核人。');}
  else throw new \InvalidArgumentException('审核操作无效。');
  $s=$this->db->prepare('INSERT INTO cc_quote_review_actions(quote_id,quote_version_id,action_code,risk_level,risk_snapshot,opinion,target_reviewer,actor_legacy_user_id,actor_name,created_at) VALUES(?,?,?,?,?,?,?,?,?,NOW())');
  $s->execute([$id,(int)($q['version']['id']??0)?:null,$action,$risk['level'],json_encode($risk,JSON_UNESCAPED_UNICODE),$opinion?:null,$target?:null,(int)($actor['id']??0)?:null,$actor['display_name']??$actor['username']??null]);return $result;}
 private function risk(array $q):array{$reasons=[];$margin=(float)($q['gross_margin']??0);if($margin>0&&$margin<.2)$reasons[]='低毛利';if((float)($q['discount_amount']??0)>0)$reasons[]='特殊折扣';if((float)($q['commission_amount']??0)>=(float)($q['total_amount']??0)*.1&&($q['total_amount']??0)>0)$reasons[]='高佣金';
  foreach($q['items']??[] as $i){$c=$i['custom_fields']??[];if(!empty($c['below_moq']))$reasons[]='低于 MOQ';if(!empty($c['below_floor_price']))$reasons[]='低于策略底价';if(!empty($i['unlock_reason']))$reasons[]='网站锁定字段修改';}
  $reasons=array_values(array_unique($reasons));return ['level'=>count($reasons)>=2?'high':($reasons?'medium':'low'),'reasons'=>$reasons,'gross_margin'=>$margin];}
}
