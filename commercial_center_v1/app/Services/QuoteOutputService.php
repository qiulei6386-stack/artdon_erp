<?php
declare(strict_types=1);

namespace Artdon\CommercialCenter\Services;

use Artdon\CommercialCenter\Repositories\QuoteOutputRepository;
use Artdon\CommercialCenter\Repositories\QuoteRepository;

final class QuoteOutputService
{
    private QuoteOutputRepository $outputs;
    private QuoteRepository $quotes;
    private QuotePermissionService $permissions;
    private QuoteWorkflowService $workflow;
    private QuoteOutputRenderer $renderer;
    private $mailer;
    public function __construct(?QuoteOutputRepository $outputs=null,?callable $mailer=null)
    {
        $this->outputs=$outputs??new QuoteOutputRepository();$db=$this->outputs->connection();
        $this->quotes=new QuoteRepository($db);$this->permissions=new QuotePermissionService($db);
        $this->workflow=new QuoteWorkflowService(null,$this->quotes,null,$this->permissions);
        $this->renderer=new QuoteOutputRenderer();
        $this->mailer=$mailer;
    }

    public function snapshotForQuote(int $quoteId,array $actor): array
    {
        $this->permissions->assert($actor,'view');
        $quote=$this->quotes->find($quoteId)??throw new \RuntimeException('报价不存在。');
        return $this->outputs->saveSnapshot($quote,(int)($actor['id']??0));
    }
    public function snapshot(int $snapshotId,array $actor): array
    {
        $this->permissions->assert($actor,'view');
        return $this->outputs->snapshot($snapshotId)??throw new \RuntimeException('输出快照不存在。');
    }
    public function html(int $snapshotId,array $actor,bool $print=false): string{return $this->renderer->html($this->snapshot($snapshotId,$actor),$print);}

    public function artifact(int $snapshotId,string $type,array $actor): array
    {
        $this->permissions->assert($actor,'export');
        if(!in_array($type,['pdf','excel'],true))throw new \InvalidArgumentException('输出格式无效。');
        $record=$this->snapshot($snapshotId,$actor);$existing=$this->outputs->artifact($snapshotId,$type);
        if($existing!==null&&is_file(dirname(__DIR__,2).'/'.$existing['storage_path'])
            && hash_file('sha256',dirname(__DIR__,2).'/'.$existing['storage_path'])===$existing['file_hash'])return $existing;
        $directory=dirname(__DIR__,2).'/uploads/quote_outputs/'.$snapshotId;
        if(!is_dir($directory)&&!mkdir($directory,0750,true)&&!is_dir($directory))throw new \RuntimeException('输出目录创建失败。');
        $safe=preg_replace('/[^A-Za-z0-9._-]+/','_',(string)($record['snapshot']['quote_no']??'quotation'))?:'quotation';
        if($type==='excel'){
            $path=$directory.'/'.$safe.'.xls';file_put_contents($path,$this->renderer->excelXml($record),LOCK_EX);
            $mime='application/vnd.ms-excel';
        }else{
            $htmlPath=$directory.'/'.$safe.'.html';$path=$directory.'/'.$safe.'.pdf';
            file_put_contents($htmlPath,$this->renderer->html($record,false),LOCK_EX);
            $this->chromePdf($htmlPath,$path);
            $mime='application/pdf';
        }
        if(!is_file($path)||filesize($path)<=0)throw new \RuntimeException('输出文件生成失败。');
        return $this->outputs->saveArtifact($snapshotId,$type,[
            'path'=>str_replace(dirname(__DIR__,2).'/','',$path),'name'=>basename($path),'mime'=>$mime,
            'size'=>filesize($path),'hash'=>hash_file('sha256',$path),
        ],(int)($actor['id']??0));
    }

    public function send(int $snapshotId,string $to,string $cc,string $subject,string $body,array $actor): array
    {
        $this->permissions->assert($actor,'send');
        if(!filter_var($to,FILTER_VALIDATE_EMAIL))throw new \InvalidArgumentException('收件人邮箱无效。');
        $record=$this->snapshot($snapshotId,$actor);$artifact=$this->artifact($snapshotId,'pdf',$actor);
        $path=dirname(__DIR__,2).'/'.$artifact['storage_path'];$boundary='cc_'.bin2hex(random_bytes(12));
        $headers=["MIME-Version: 1.0","Content-Type: multipart/mixed; boundary=\"{$boundary}\""];
        if(trim($cc)!=='')$headers[]='Cc: '.$cc;
        $message="--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n{$body}\r\n";
        $message.="--{$boundary}\r\nContent-Type: application/pdf; name=\"{$artifact['file_name']}\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"{$artifact['file_name']}\"\r\n\r\n";
        $message.=chunk_split(base64_encode((string)file_get_contents($path)))."\r\n--{$boundary}--";
        $sent=$this->mailer!==null
            ? (bool)($this->mailer)($to,$subject,$message,implode("\r\n",$headers))
            : @mail($to,$subject,$message,implode("\r\n",$headers));
        $deliveryId=$this->outputs->saveDelivery([
            'quote_id'=>(int)$record['quote_id'],'snapshot_id'=>$snapshotId,'artifact_id'=>(int)$artifact['id'],
            'to'=>$to,'cc'=>$cc,'subject'=>$subject,'body'=>$body,'status'=>$sent?'sent':'failed',
            'error'=>$sent?'':'邮件传输服务返回失败','actor_id'=>(int)($actor['id']??0),
            'actor_name'=>$actor['display_name']??$actor['username']??'',
        ]);
        if(!$sent)throw new \RuntimeException('邮件发送失败，失败记录已保存。');
        $quote=$this->quotes->find((int)$record['quote_id']);
        if(($quote['status']??'')==='approved')$this->workflow->transition((int)$record['quote_id'],'sent',$actor,'报价邮件发送成功');
        return ['delivery_id'=>$deliveryId,'status'=>'sent'];
    }

    private function chromePdf(string $htmlPath,string $pdfPath): void
    {
        $profile=sys_get_temp_dir().'/cc_chrome_'.bin2hex(random_bytes(6));
        $command=['/usr/bin/google-chrome','--headless','--no-sandbox','--disable-gpu','--disable-dev-shm-usage',
            '--user-data-dir='.$profile,'--print-to-pdf='.$pdfPath,'file://'.$htmlPath];
        $pipes=[];$process=proc_open($command,[1=>['pipe','w'],2=>['pipe','w']],$pipes,null,['HOME'=>sys_get_temp_dir()]);
        if(!is_resource($process))throw new \RuntimeException('PDF 生成器无法启动。');
        foreach($pipes as $pipe){stream_get_contents($pipe);fclose($pipe);}
        $code=proc_close($process);
        if($code!==0)throw new \RuntimeException('PDF 生成失败。');
    }
}
