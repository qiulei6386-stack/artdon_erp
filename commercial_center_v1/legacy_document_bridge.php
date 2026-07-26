<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
use Artdon\CommercialCenter\Adapters\LegacyAuthAdapter;
$auth=(new LegacyAuthAdapter())->currentUser();if(!$auth['authenticated']){http_response_code(401);exit('需要统一登录。');}
$orderId=max(0,(int)($_GET['order_id']??0));$type=(string)($_GET['type']??'pi');$format=(string)($_GET['format']??'html');
$s=db()->prepare('SELECT * FROM quote_sales_orders WHERE id=?');$s->execute([$orderId]);$order=$s->fetch(PDO::FETCH_ASSOC);if(!is_array($order)){http_response_code(404);exit('Order not found');}
$payload=json_decode((string)$order['snapshot_json'],true)?:[];$payload['order_no']=$order['order_no'];$payload['source_quote_no']=$order['quote_no'];
if($type!=='pi'){http_response_code(400);exit('Unsupported document');}
$target='../quote_order_pi_export.php?type='.($format==='excel'?'excel':'pdf');
?><!doctype html><meta charset="utf-8"><form id="legacy" method="post" action="<?=htmlspecialchars($target,ENT_QUOTES)?>">
<input type="hidden" name="payload" value="<?=htmlspecialchars(json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),ENT_QUOTES)?>"></form>
<script>document.getElementById('legacy').submit()</script>
