<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
use Artdon\MaterialCenter\Services\PowerStandardizationService;
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
echo json_encode((new PowerStandardizationService())->stagePilot(),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
