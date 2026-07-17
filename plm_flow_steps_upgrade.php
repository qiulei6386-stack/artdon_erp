<?php
require_once __DIR__.'/config.php';
header('Content-Type:text/html; charset=utf-8');
function table_exists2($t){ $st=db()->prepare('SHOW TABLES LIKE ?'); $st->execute([$t]); return (bool)$st->fetchColumn(); }
function col_exists2($t,$c){ if(!table_exists2($t)) return false; $st=db()->prepare("SHOW COLUMNS FROM `$t` LIKE ?"); $st->execute([$c]); return (bool)$st->fetchColumn(); }
function add_col2($t,$c,$def){ if(table_exists2($t) && !col_exists2($t,$c)){ db()->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def"); echo "<p>已增加字段：$t.$c</p>"; } }
try{
  db()->exec("CREATE TABLE IF NOT EXISTS plm_flow_steps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL DEFAULT 0,
    step_name VARCHAR(100) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    status VARCHAR(50) NOT NULL DEFAULT '未开始',
    operator VARCHAR(100) DEFAULT '',
    note TEXT NULL,
    planned_start DATETIME NULL,
    planned_end DATETIME NULL,
    plan_hours DECIMAL(10,2) NOT NULL DEFAULT 0,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    actual_hours DECIMAL(10,2) NOT NULL DEFAULT 0,
    rework_count INT NOT NULL DEFAULT 0,
    last_reason TEXT NULL,
    last_action VARCHAR(50) DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_project_step(project_id,step_name),
    KEY idx_project(project_id),
    KEY idx_sort(project_id,sort_order)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  foreach([
    'project_id'=>'INT NOT NULL DEFAULT 0','step_name'=>"VARCHAR(100) NOT NULL DEFAULT ''",'sort_order'=>'INT NOT NULL DEFAULT 0','status'=>"VARCHAR(50) NOT NULL DEFAULT '未开始'",'operator'=>"VARCHAR(100) DEFAULT ''",'note'=>'TEXT NULL','planned_start'=>'DATETIME NULL','planned_end'=>'DATETIME NULL','plan_hours'=>'DECIMAL(10,2) NOT NULL DEFAULT 0','started_at'=>'DATETIME NULL','finished_at'=>'DATETIME NULL','actual_hours'=>'DECIMAL(10,2) NOT NULL DEFAULT 0','rework_count'=>'INT NOT NULL DEFAULT 0','last_reason'=>'TEXT NULL','last_action'=>"VARCHAR(50) DEFAULT ''",'created_at'=>'DATETIME DEFAULT CURRENT_TIMESTAMP','updated_at'=>'DATETIME DEFAULT CURRENT_TIMESTAMP'
  ] as $c=>$def){ add_col2('plm_flow_steps',$c,$def); }
  db()->exec("CREATE TABLE IF NOT EXISTS plm_flow_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    step_id INT NOT NULL DEFAULT 0,
    project_id INT NOT NULL DEFAULT 0,
    action VARCHAR(50) NOT NULL DEFAULT '',
    reason TEXT NULL,
    operator VARCHAR(100) DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_step(step_id),
    KEY idx_project(project_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  foreach(['step_id'=>'INT NOT NULL DEFAULT 0','project_id'=>'INT NOT NULL DEFAULT 0','action'=>"VARCHAR(50) NOT NULL DEFAULT ''",'reason'=>'TEXT NULL','operator'=>"VARCHAR(100) DEFAULT ''",'created_at'=>'DATETIME DEFAULT CURRENT_TIMESTAMP'] as $c=>$def){ add_col2('plm_flow_logs',$c,$def); }
  echo "<h2>PLM 开发导航图日志版升级完成</h2>";
  echo "<p>已支持：计划时间、计划工时、实际用时、开始时间、通过时间、重来原因、重来次数、操作日志。</p>";
  echo "<p><a href='plm.php'>返回 PLM</a></p>";
}catch(Throwable $e){ echo '<h2>升级失败</h2><pre>'.htmlspecialchars($e->getMessage()).'</pre>'; }
?>
