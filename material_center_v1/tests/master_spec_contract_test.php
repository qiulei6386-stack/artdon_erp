<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$required=[
 'database/migrations/20260726_005_master_data_full_domain.php',
 'database/migrations/20260726_006_categories_and_fields.php',
 'database/migrations/20260726_007_supplier_profiles.php',
 'app/Services/SourceSyncService.php','app/Services/ImportService.php','app/Services/SupplierService.php',
 'app/Services/AdaptationService.php','app/Services/SubstitutionService.php','app/Services/DocumentService.php',
 'app/Services/PermissionAdminService.php','docs/DATABASE_SCHEMA.md','docs/API_REFERENCE.md',
 'docs/DEPLOYMENT_AND_ROLLBACK.md','docs/TEST_REPORT.md','docs/KNOWN_ISSUES.md',
];
foreach($required as$file)if(!is_file($root.'/'.$file)){fwrite(STDERR,"missing $file\n");exit(1);}
foreach(glob($root.'/database/migrations/*.php') as$file){
 $text=file_get_contents($file);
 if(preg_match('/CREATE TABLE IF NOT EXISTS\\s+(?!mc_)/i',$text)){fwrite(STDERR,"non-mc table in migration: $file\n");exit(1);}
 if(!str_contains($text,"'down'")){fwrite(STDERR,"migration has no rollback: $file\n");exit(1);}
}
$source=file_get_contents($root.'/app/Adapters/LegacyBomMaterialAdapter.php');
if(preg_match('/\\b(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP)\\b/i',$source)){fwrite(STDERR,"legacy BOM adapter is not read-only\n");exit(1);}
foreach(['index.php','components/layout_top.php'] as$file){
 $text=file_get_contents($root.'/'.$file);
 foreach(['赵立伟','LF-GIR020YS'] as$fake)if(str_contains($text,$fake)){fwrite(STDERR,"demo marker remains: $fake\n");exit(1);}
}
if(is_file($root.'/demo/materials.php')){fwrite(STDERR,"demo material source remains\n");exit(1);}
$workspace=file_get_contents($root.'/assets/js/material-workspace-actions.js');
foreach(['data-material-edit','data-material-copy','data-material-revision','data-material-reference','data-material-transition','batch_preview','batch_execute'] as$marker)if(!str_contains($workspace,$marker)){fwrite(STDERR,"workspace action missing: $marker\n");exit(1);}
echo "master spec contract: OK\n";
