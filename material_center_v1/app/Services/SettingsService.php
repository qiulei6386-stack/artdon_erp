<?php
declare(strict_types=1);
namespace Artdon\MaterialCenter\Services;

use Artdon\MaterialCenter\Security\MaterialCenterUserContext;
use PDO;
use RuntimeException;

final class SettingsService
{
    public function __construct(private ?PDO $db = null) { $this->db ??= \db(); }

    public function resolved(?MaterialCenterUserContext $user): array
    {
        if (!\mc_table_exists('mc_ui_settings')) return [];
        $rows = $this->db->query('SELECT setting_key,default_json,validation_json FROM mc_ui_settings ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $result=[];$rules=[];
        foreach ($rows as $row) {
            $result[$row['setting_key']] = json_decode($row['default_json'], true);
            $rules[$row['setting_key']] = json_decode((string)$row['validation_json'], true) ?: [];
        }
        $scopes=[['global','global']];
        if ($user && $user->roleKey !== '') $scopes[]=['role',$user->roleKey];
        if ($user) $scopes[]=['user',(string)$user->id];
        foreach ($scopes as [$type,$id]) {
            $stmt=$this->db->prepare('SELECT setting_key,value_json FROM mc_ui_setting_scopes WHERE scope_type=? AND scope_id=?');
            $stmt->execute([$type,$id]);
            foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $result[$row['setting_key']]=json_decode($row['value_json'],true);
        }
        return ['values'=>$result,'rules'=>$rules];
    }

    public function save(MaterialCenterUserContext $user,string $scopeType,string $scopeId,array $values): array
    {
        if (!in_array($scopeType,['global','role','user'],true)) throw new RuntimeException('设置范围无效。');
        if ($scopeType==='user' && $scopeId!==(string)$user->id && !$user->isSuperAdmin) throw new RuntimeException('不能修改其他用户设置。');
        $definitions=$this->resolved($user);$rules=$definitions['rules']??[];
        $this->db->beginTransaction();
        try {
            foreach ($values as $key=>$value) {
                if (!array_key_exists($key,$rules)) throw new RuntimeException("未知设置：{$key}");
                $value=$this->validate($value,$rules[$key]);
                $old=$this->db->prepare('SELECT value_json,is_locked FROM mc_ui_setting_scopes WHERE scope_type=? AND scope_id=? AND setting_key=? FOR UPDATE');
                $old->execute([$scopeType,$scopeId,$key]);$before=$old->fetch(PDO::FETCH_ASSOC);
                if ($before && (int)$before['is_locked']===1 && !$user->isSuperAdmin) throw new RuntimeException("设置 {$key} 已锁定。");
                $json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                $stmt=$this->db->prepare('INSERT INTO mc_ui_setting_scopes(scope_type,scope_id,setting_key,value_json,updated_by,created_at,updated_at) VALUES(?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),version=version+1,updated_by=VALUES(updated_by),updated_at=NOW()');
                $stmt->execute([$scopeType,$scopeId,$key,$json,$user->id]);
                $audit=$this->db->prepare('INSERT INTO mc_setting_audit_logs(scope_type,scope_id,setting_key,before_json,after_json,actor_id,created_at) VALUES(?,?,?,?,?,?,NOW())');
                $audit->execute([$scopeType,$scopeId,$key,$before['value_json']??null,$json,$user->id]);
            }
            $this->db->commit();
            return $this->resolved($user);
        } catch (\Throwable $e) {
            if($this->db->inTransaction())$this->db->rollBack();
            throw $e;
        }
    }

    public function reset(MaterialCenterUserContext $user,string $scopeType,string $scopeId): void
    {
        if ($scopeType==='user' && $scopeId!==(string)$user->id && !$user->isSuperAdmin) throw new RuntimeException('不能重置其他用户设置。');
        $stmt=$this->db->prepare('DELETE FROM mc_ui_setting_scopes WHERE scope_type=? AND scope_id=? AND is_locked=0');
        $stmt->execute([$scopeType,$scopeId]);
        $audit=$this->db->prepare("INSERT INTO mc_setting_audit_logs(scope_type,scope_id,setting_key,after_json,actor_id,created_at) VALUES(?,?,'*','\"reset\"',?,NOW())");
        $audit->execute([$scopeType,$scopeId,$user->id]);
    }

    private function validate(mixed $value,array $rule): mixed
    {
        return match($rule['type']??'string') {
            'enum' => in_array((string)$value,$rule['values']??[],true) ? (string)$value : throw new RuntimeException('设置选项无效。'),
            'number' => is_numeric($value) && (float)$value>=($rule['min']??-INF) && (float)$value<=($rule['max']??INF) ? (float)$value : throw new RuntimeException('设置数值超出范围。'),
            'boolean' => filter_var($value,FILTER_VALIDATE_BOOL,FILTER_NULL_ON_FAILURE) ?? throw new RuntimeException('布尔设置无效。'),
            'color' => preg_match('/^#[0-9a-fA-F]{6}$/',(string)$value) ? strtolower((string)$value) : throw new RuntimeException('颜色格式无效。'),
            default => mb_substr(trim((string)$value),0,255),
        };
    }
}
