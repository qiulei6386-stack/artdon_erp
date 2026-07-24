<?php
declare(strict_types=1);
namespace Artdon\CommercialCenter\Services;
use Artdon\CommercialCenter\Adapters\SingaporeChannelAdapter;
use Artdon\CommercialCenter\Repositories\UnifiedOrderRepository;
use Artdon\CommercialCenter\Support\Logger;
final class UnifiedOrderService
{
    public function load(array $auth): array
    {
        $empty=['status'=>'unauthenticated','rows'=>[],'counts'=>['total'=>0,'singapore'=>0,'pending_review'=>0,'sync_failed'=>0],'channel'=>(new SingaporeChannelAdapter())->status()];
        if (empty($auth['authenticated'])) return $empty;
        try {$repo=new UnifiedOrderRepository();return ['status'=>'available','rows'=>$repo->list(),'counts'=>$repo->counts(),'channel'=>(new SingaporeChannelAdapter())->status()];}
        catch (\Throwable $e){Logger::error('Unified orders unavailable',['message'=>$e->getMessage()]);$empty['status']='unavailable';return $empty;}
    }
}
