<?php
declare(strict_types=1);
namespace Artdon\MaterialCenter\Adapters;
use PDO;

final class LegacyProductAdapter
{
    public function __construct(private ?PDO $db=null){$this->db??=\db();}
    public function search(string $term='',int $limit=100):array
    {
        $sql="SELECT id,model_no,category,item_name,product_name,status,remark,dim_length,dim_width,dim_height,COALESCE(NULLIF(series_name,''),NULLIF(web_series,''),category) series_name,COALESCE(NULLIF(web_image_url,''),NULLIF(source_image_url,''),NULLIF(image_path,'')) image_url FROM naming_models WHERE COALESCE(website_deleted,0)=0";$params=[];
        if($term!==''){$sql.=" AND (model_no LIKE ? OR item_name LIKE ? OR product_name LIKE ? OR series_name LIKE ? OR web_series LIKE ?)";$like='%'.$term.'%';$params=[$like,$like,$like,$like,$like];}
        $sql.=" ORDER BY updated_at DESC,id DESC LIMIT ".max(1,min(200,$limit));$stmt=$this->db->prepare($sql);$stmt->execute($params);return$stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function find(int $id):?array{$stmt=$this->db->prepare("SELECT id,model_no,category,item_name,product_name,status,remark,dim_length,dim_width,dim_height,COALESCE(NULLIF(series_name,''),NULLIF(web_series,''),category) series_name,COALESCE(NULLIF(web_image_url,''),NULLIF(source_image_url,''),NULLIF(image_path,'')) image_url FROM naming_models WHERE id=?");$stmt->execute([$id]);return$stmt->fetch(PDO::FETCH_ASSOC)?:null;}
    public function allAfter(int $afterId=0,int $limit=500):array{$stmt=$this->db->prepare("SELECT id,model_no,category,item_name,product_name,status,remark,dim_length,dim_width,dim_height,COALESCE(NULLIF(series_name,''),NULLIF(web_series,''),category) series_name,COALESCE(NULLIF(web_image_url,''),NULLIF(source_image_url,''),NULLIF(image_path,'')) image_url FROM naming_models WHERE id>? AND COALESCE(website_deleted,0)=0 ORDER BY id LIMIT ".max(1,min(1000,$limit)));$stmt->execute([$afterId]);return$stmt->fetchAll(PDO::FETCH_ASSOC);}
}
