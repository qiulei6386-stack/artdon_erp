<?php
declare(strict_types=1);
namespace Artdon\CommercialCenter\Adapters;
final class SingaporeChannelAdapter
{
    public function status(): array
    {
        return ['status' => 'not_configured', 'can_read' => false, 'can_write' => false, 'message' => '新加坡 API 尚未配置，未执行真实同步。'];
    }
}
