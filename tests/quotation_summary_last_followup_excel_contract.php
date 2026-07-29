<?php
declare(strict_types=1);

function contract_expect(bool $condition,string $message): void {
    if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}
    echo "PASS: {$message}\n";
}

$root=dirname(__DIR__);
$api=(string)file_get_contents($root.'/quote_api.php');
$page=(string)file_get_contents($root.'/quotation.php');

contract_expect(strpos($api,'function quote_summary_attach_last_followup')!==false,'summary API derives last follow-up');
contract_expect(strpos($api,'SELECT quote_id,contacted_at FROM crm_quote_followup_activities')!==false,'last follow-up is based on the recorded follow-up time');
contract_expect(strpos($api,'quote_summary_attach_next_followup')===false,'old next-follow-up attachment is removed');
contract_expect(strpos($api,'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')!==false,'export uses the XLSX MIME type');
contract_expect(strpos($api,"<sheet name=\"报价明细\"")!==false,'export contains only the quotation detail sheet');
contract_expect(strpos($page,'最后一次跟进时间')!==false,'page labels the last follow-up time');
contract_expect(strpos($page,'id="summaryDetailTable"')!==false,'detail table has a resize target');
contract_expect(strpos($page,'initSummaryColumnResize')!==false&&strpos($page,'SUMMARY_COLUMN_WIDTH_KEY')!==false,'detail columns support saved drag widths');
