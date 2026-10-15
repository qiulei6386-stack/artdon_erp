<?php
declare(strict_types=1);

namespace Artdon\Centers\Mail;

use Artdon\Centers\Base\Config;

/**
 * 邮箱授权码加解密。与旧系统完全相同的算法（AES-256-CBC，随机 IV 放在前 16 字节，整体 base64），
 * 钥匙 = sha256(种子)。种子读新系统配置 mail.key_seed；没有配置时按旧系统的兜底规则用程序根目录路径。
 * 服务器上运行 php centers/_base/tools/mail_setup.php 会核对种子能否解开已有邮箱，并写入配置。
 */
final class MailCrypto
{
    public static function seed(): string
    {
        $seed = (string) Config::get('mail.key_seed', '');
        return $seed !== '' ? $seed : (string) ARTDON_APP_ROOT;
    }

    /**
     * 从几个候选种子里找出能解开已有授权码的那个（setup 工具用）。
     * 解开的结果必须是可打印字符；返回解开最多的种子和数量，全部解不开时返回 null。
     *
     * @param list<string> $samples 已加密的授权码
     * @param list<string> $candidates
     * @return array{seed: string, ok: int}|null
     */
    public static function detectSeed(array $samples, array $candidates): ?array
    {
        $best = null;
        foreach (array_values(array_unique(array_filter($candidates, 'strlen'))) as $seed) {
            $ok = 0;
            foreach ($samples as $s) {
                $plain = self::decrypt($s, $seed);
                if ($plain !== '' && preg_match('/^[\x20-\x7E]+$/', $plain)) {
                    $ok++;
                }
            }
            if ($ok > 0 && ($best === null || $ok > $best['ok'])) {
                $best = ['seed' => $seed, 'ok' => $ok];
            }
        }
        return $best;
    }

    public static function encrypt(string $value, ?string $seed = null): string
    {
        if ($value === '') {
            return '';
        }
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($value, 'AES-256-CBC', hash('sha256', $seed ?? self::seed(), true), OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . ($cipher ?: ''));
    }

    public static function decrypt(?string $value, ?string $seed = null): string
    {
        $value = (string) $value;
        if ($value === '') {
            return '';
        }
        $raw = base64_decode($value, true);
        if ($raw === false || strlen($raw) <= 16) {
            return '';
        }
        $plain = openssl_decrypt(substr($raw, 16), 'AES-256-CBC', hash('sha256', $seed ?? self::seed(), true), OPENSSL_RAW_DATA, substr($raw, 0, 16));
        return is_string($plain) ? $plain : '';
    }
}
