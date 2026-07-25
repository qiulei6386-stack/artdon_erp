<?php
require_once dirname(__DIR__).'/bootstrap.php';
$pages=require MC_ROOT.'/config/material_pages.php';$demo=require MC_ROOT.'/demo/materials.php';$config=$pages['connector'];$rows=$demo['connector'];$pageTitle=$config['title'];$pageDescription=$config['description'];$activeMenu='connector';include MC_ROOT.'/components/layout_top.php';require_once MC_ROOT.'/components/material_workspace.php';render_material_workspace($config,$rows);include MC_ROOT.'/components/layout_bottom.php';
