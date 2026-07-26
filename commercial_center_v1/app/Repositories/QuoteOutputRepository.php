<?php
declare(strict_types=1);

namespace Artdon\CommercialCenter\Repositories;

use PDO;

final class QuoteOutputRepository
{
    private PDO $db;
    public function __construct(?PDO $db=null){$this->db=$db??db();}
    public function connection(): PDO{return $this->db;}

    public function saveSnapshot(array $quote,int $actorId): array
    {
        $json=json_encode($quote,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);
        if($json===false)throw new \RuntimeException('输出快照无法序列化。');
        $hash=hash('sha256',$json);
        $watermark=match((string)$quote['status']){'draft'=>'DRAFT / 草稿','pending_approval'=>'待审核',default=>null};
        $find=$this->db->prepare('SELECT * FROM cc_quote_output_snapshots WHERE quote_id=? AND quote_version_id=? AND quote_status=? AND snapshot_hash=? LIMIT 1');
        $find->execute([(int)$quote['id'],(int)$quote['version']['id'],(string)$quote['status'],$hash]);
        $row=$find->fetch(PDO::FETCH_ASSOC);
        if(is_array($row))return $this->decodeSnapshot($row);
        $insert=$this->db->prepare(
            'INSERT INTO cc_quote_output_snapshots
             (quote_id,quote_version_id,version_no,quote_status,watermark,snapshot_json,snapshot_hash,created_by_legacy_user_id,created_at)
             VALUES (?,?,?,?,?,?,?,?,NOW())'
        );
        $insert->execute([(int)$quote['id'],(int)$quote['version']['id'],(int)$quote['current_version'],(string)$quote['status'],$watermark,$json,$hash,$actorId?:null]);
        return $this->snapshot((int)$this->db->lastInsertId())??throw new \RuntimeException('输出快照保存失败。');
    }

    public function snapshot(int $id): ?array
    {
        $s=$this->db->prepare('SELECT * FROM cc_quote_output_snapshots WHERE id=? LIMIT 1');
        $s->execute([$id]);$row=$s->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$this->decodeSnapshot($row):null;
    }

    public function artifact(int $snapshotId,string $type): ?array
    {
        $s=$this->db->prepare('SELECT * FROM cc_quote_output_artifacts WHERE output_snapshot_id=? AND artifact_type=? LIMIT 1');
        $s->execute([$snapshotId,$type]);$row=$s->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$row:null;
    }

    public function saveArtifact(int $snapshotId,string $type,array $file,int $actorId): array
    {
        $s=$this->db->prepare(
            'INSERT INTO cc_quote_output_artifacts
             (output_snapshot_id,artifact_type,storage_path,file_name,mime_type,file_size,file_hash,generated_by_legacy_user_id,generated_at)
             VALUES (?,?,?,?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE storage_path=VALUES(storage_path),file_name=VALUES(file_name),mime_type=VALUES(mime_type),
             file_size=VALUES(file_size),file_hash=VALUES(file_hash),generated_by_legacy_user_id=VALUES(generated_by_legacy_user_id),generated_at=NOW()'
        );
        $s->execute([$snapshotId,$type,$file['path'],$file['name'],$file['mime'],$file['size'],$file['hash'],$actorId?:null]);
        return $this->artifact($snapshotId,$type)??throw new \RuntimeException('输出文件登记失败。');
    }

    public function saveDelivery(array $data): int
    {
        $s=$this->db->prepare(
            'INSERT INTO cc_quote_deliveries
             (quote_id,output_snapshot_id,artifact_id,recipient_email,cc_email,subject,message_body,delivery_status,
              error_message,sent_by_legacy_user_id,sent_by_name,sent_at,created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())'
        );
        $s->execute([$data['quote_id'],$data['snapshot_id'],$data['artifact_id'],$data['to'],$data['cc']?:null,$data['subject'],
            $data['body']?:null,$data['status'],$data['error']?:null,$data['actor_id']?:null,$data['actor_name']?:null,
            $data['status']==='sent'?date('Y-m-d H:i:s'):null]);
        return (int)$this->db->lastInsertId();
    }

    private function decodeSnapshot(array $row): array
    {
        $row['snapshot']=json_decode((string)$row['snapshot_json'],true)?:[];
        return $row;
    }
}
