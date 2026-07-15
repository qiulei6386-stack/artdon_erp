<?php
// Compatibility bridge: old links can call this file, but the real PL/CI export engine is quote_order_doc.php.
$type = $_GET['type'] ?? ($_POST['type'] ?? 'pl');
$format = $_GET['format'] ?? ($_POST['format'] ?? 'html');
if (!isset($_GET['shipment_id']) && isset($_GET['id'])) $_GET['shipment_id'] = $_GET['id'];
$_GET['type'] = $type;
$_GET['format'] = $format;
require __DIR__.'/quote_order_doc.php';
