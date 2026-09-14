<?php
// Private CLI renderer: stdin is supplied only by the authenticated attachment service.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!in_array($argv[1] ?? '', ['pdf','excel'], true)) exit(2);
define('QUOTE_MAIL_RENDER', true);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['payload'] = stream_get_contents(STDIN, 32 * 1024 * 1024 + 1);
if (strlen($_POST['payload']) > 32 * 1024 * 1024) exit(3);
$data = json_decode($_POST['payload'], true, 64, JSON_THROW_ON_ERROR);
if (empty($data['items']) || empty($data['quote_id'])) exit(4);
require dirname(__DIR__) . (($argv[1] === 'pdf') ? '/crm_quote_pdf.php' : '/crm_quote_excel.php');
