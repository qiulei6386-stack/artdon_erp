<?php
// Credentials-free test, refuses the live database and requires a fresh sandbox.
$socket=(string)getenv('CRM_PHASE1_MYSQL_SOCKET');$schema=(string)getenv('CRM_PHASE1_MYSQL_SCHEMA');
if(PHP_SAPI!=='cli'||getenv('CRM_PHASE1_MYSQL_TEST')!=='1'||!preg_match('#^/tmp/crm-phase1-mysql-20260906-[A-Za-z0-9]{8}/mysql.sock$#D',$socket)||!preg_match('/^quote_money_[a-f0-9]{12}$/D',$schema))throw new RuntimeException('Sandbox required');
$pdo=new PDO('mysql:unix_socket='.$socket.';dbname='.$schema.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$identity=$pdo->query('SELECT @@socket socket,@@datadir datadir,@@skip_networking isolated')->fetch();
if($identity['socket']!==$socket||$identity['datadir']!==dirname($socket).'/data/'||(int)$identity['isolated']!==1||(int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()!==0)throw new RuntimeException('Fresh isolated schema required');
require __DIR__.'/crm_customer_channel_filter_contract.php';
$pdo->exec('CREATE TABLE crm_customers(id INT PRIMARY KEY, owner_id INT, customer_name VARCHAR(80),wechat VARCHAR(80),whatsapp VARCHAR(80),do_not_contact INT DEFAULT 0, deleted_at DATETIME NULL)');
$pdo->exec('CREATE TABLE crm_contacts(id INT PRIMARY KEY,customer_id INT,name VARCHAR(80),wechat VARCHAR(80),whatsapp VARCHAR(80),is_left INT DEFAULT 0,do_not_contact INT DEFAULT 0,no_whatsapp INT DEFAULT 0,deleted_at DATETIME NULL)');
$pdo->exec('CREATE TABLE crm_customer_promotion_channels(customer_id INT,channel_key VARCHAR(30))');
$pdo->exec('CREATE TABLE crm_contact_promotions(contact_id INT,channel VARCHAR(30),status VARCHAR(30))');
$pdo->exec('CREATE TABLE crm_customer_chat_groups(customer_id INT,group_platform VARCHAR(30),status VARCHAR(30),use_for_promotion INT,deleted_at DATETIME NULL)');
for($id=1;$id<=12;$id++)$pdo->exec("INSERT INTO crm_customers(id,owner_id,customer_name,wechat,whatsapp) VALUES($id,1,'测试$id','','')");
$pdo->exec("UPDATE crm_customers SET wechat='number-only',whatsapp='number-only' WHERE id=1");
$pdo->exec("UPDATE crm_customers SET owner_id=2 WHERE id=10");
$pdo->exec("UPDATE crm_customers SET deleted_at=NOW() WHERE id=11");
foreach(['wechat','whatsapp','wechat_group','whatsapp_group'] as $ch){
 $stmt=$pdo->prepare('INSERT INTO crm_customer_promotion_channels VALUES(2,?),(10,?),(11,?)');$stmt->execute([$ch,$ch,$ch]);
}
for($id=3;$id<=8;$id++)$pdo->exec("INSERT INTO crm_contacts(id,customer_id,name,wechat,whatsapp) VALUES($id,$id,'联系人$id','','')");
$pdo->exec("INSERT INTO crm_contacts(id,customer_id,name,wechat,whatsapp) VALUES(30,3,'第二联系人','','')");
$pdo->exec("INSERT INTO crm_contact_promotions VALUES(3,'wechat','active'),(30,'wechat','active'),(4,'whatsapp','active'),(5,'wechat_group','active'),(6,'whatsapp_group','active'),(7,'wechat','stopped'),(8,'wechat','active')");
$pdo->exec('UPDATE crm_contacts SET deleted_at=NOW() WHERE id=8');
$pdo->exec("INSERT INTO crm_customer_chat_groups VALUES(1,'wechat_group','active',1,NULL),(1,'whatsapp_group','active',1,NULL),(2,'wechat_group','paused',1,NULL),(2,'whatsapp_group','invalid',0,NULL),(5,'wechat_group','active',0,NULL),(6,'whatsapp_group','active',1,NULL)");
$before=[];foreach(['crm_customers','crm_contacts','crm_customer_promotion_channels','crm_contact_promotions','crm_customer_chat_groups'] as $table)$before[$table]=$pdo->query("SELECT * FROM $table")->fetchAll();
$pdo->exec('START TRANSACTION READ ONLY');
foreach(['wechat'=>3,'whatsapp'=>4,'wechat_group'=>5,'whatsapp_group'=>6] as $ch=>$expected){
 $params=[];$where=crm_customer_channel_condition($ch,$params);
 $sql=" FROM crm_customers c WHERE c.deleted_at IS NULL AND c.owner_id=1 AND $where";
 $stmt=$pdo->prepare('SELECT COUNT(*)'.$sql);$stmt->execute($params);cfc_assert((int)$stmt->fetchColumn()===2,'Counts, scope and deleted checks');
 $seen=[];for($page=0;$page<2;$page++){
  $stmt=$pdo->prepare('SELECT c.id,c.customer_name'.$sql.' ORDER BY c.id LIMIT 1 OFFSET '.$page);$stmt->execute($params);
  $rows=crm_customer_channel_annotate($pdo,$stmt->fetchAll(),$ch);cfc_assert(count($rows)===1 && isset($rows[0]['channel_match']),'Page-only annotations');$seen[]=(int)$rows[0]['id'];
 }
 cfc_assert($seen===[2,$expected],'Four distinct channels, no duplicate multi-contact or data-only customers');
 $stmt=$pdo->prepare('SELECT c.id'.$sql.' AND c.customer_name LIKE ?');$stmt->execute(array_merge($params,['%测试2%']));cfc_assert(count($stmt->fetchAll())===1,'Combined search');
}
foreach($before as $table=>$rows)cfc_assert($pdo->query("SELECT * FROM $table")->fetchAll()===$rows,'No data mutation');
$pdo->rollBack();
echo "MySQL channel matrix: four classes, missing data, stopped/deleted contacts, groups, permissions, duplicates, pagination and unchanged source passed\n";
