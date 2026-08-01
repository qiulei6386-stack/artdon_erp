<?php
declare(strict_types=1);
namespace Artdon\CommercialCenter\Adapters;
final class SingaporeChannelAdapter
{
    private const ENDPOINT = 'https://shop.artdonlighting.com/api/channel_product.php';

    public function status(): array
    {
        $ready = function_exists('curl_init') && is_readable(CC_STORAGE . '/channel_sync_secret');
        return ['status' => $ready ? 'connected' : 'not_configured', 'can_read' => false, 'can_write' => $ready,
            'message' => $ready ? '新加坡产品发布 API 已配置。' : '新加坡 API 密钥未配置。'];
    }

    public function publish(array $payload, string $idempotencyKey): array
    {
        $secret = trim((string)@file_get_contents(CC_STORAGE . '/channel_sync_secret'));
        if ($secret === '' || !function_exists('curl_init')) throw new \RuntimeException('新加坡发布接口尚未配置。');
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($body === false) throw new \RuntimeException('发布数据无法序列化。');
        $timestamp = (string)time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        $curl = curl_init(self::ENDPOINT);
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_HTTPHEADER => ['Content-Type: application/json',
                'X-Artdon-Timestamp: ' . $timestamp, 'X-Artdon-Signature: ' . $signature,
                'Idempotency-Key: ' . $idempotencyKey], CURLOPT_POSTFIELDS => $body]);
        $responseBody = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if (!is_string($responseBody)) throw new \RuntimeException('连接新加坡网站失败：' . $error);
        $response = json_decode($responseBody, true);
        if ($status < 200 || $status >= 300 || !is_array($response) || empty($response['ok'])) {
            throw new \RuntimeException('新加坡网站拒绝发布：' . (is_array($response) ? ($response['message'] ?? ('HTTP ' . $status)) : ('HTTP ' . $status)));
        }
        return $response;
    }
}
