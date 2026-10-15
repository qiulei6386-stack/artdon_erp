<?php
declare(strict_types=1);

namespace Artdon\Centers\Customer;

use Artdon\Centers\Base\Db;
use PDO;

/**
 * 客户规则配置：读正式库 crm_rule_configs（旧配置中心维护），没有时用与旧系统相同的默认值。只读。
 */
final class RuleConfig
{
    private const DEFAULTS = [
        'customer_admission' => ['mode' => 'lead_pool_first', 'enabled' => 1, 'external_sources_to_pool' => ['website', 'mail', 'whatsapp', 'linkedin', 'ads']],
        'lead_pool' => ['enabled' => 1],
        'duplicate_detection' => ['name_threshold' => 80, 'action' => 'lead_pool'],
        'default_values' => ['customer_level' => 'P3', 'customer_status' => 'lead', 'promotion_status' => 'not_promoted', 'source' => 'website', 'promotion_channels' => ['email']],
    ];

    /** 准入模式说明。 */
    public const ADMISSION_LABELS = [
        'direct' => '直接进入正式客户',
        'lead_pool_first' => '新建客户先进入暂存池',
        'duplicate_risk_only' => '有疑似重复时进入暂存池',
        'external_sources_only' => '外部来源的客户进入暂存池',
        'import_only' => '只有导入的客户进入暂存池',
    ];

    /** @var array<string, array<string, mixed>> */
    private static $cache = [];

    /** @return array<string, mixed> */
    public static function get(string $key): array
    {
        if (!array_key_exists($key, self::$cache)) {
            $config = self::DEFAULTS[$key] ?? [];
            if (Db::tableExists('crm_rule_configs')) {
                $stmt = Db::pdo()->prepare('SELECT config_json FROM crm_rule_configs WHERE rule_key = ? LIMIT 1');
                $stmt->execute([$key]);
                $json = $stmt->fetchColumn();
                if ($json !== false) {
                    $saved = json_decode((string) $json, true);
                    $config = is_array($saved) ? $saved : [];
                }
            }
            self::$cache[$key] = $config;
        }
        return self::$cache[$key];
    }

    public static function admissionMode(): string
    {
        $mode = (string) (self::get('customer_admission')['mode'] ?? 'lead_pool_first');
        return isset(self::ADMISSION_LABELS[$mode]) ? $mode : 'lead_pool_first';
    }

    public static function leadPoolEnabled(): bool
    {
        return !empty(self::get('lead_pool')['enabled']);
    }

    public static function nameThreshold(): int
    {
        return max(50, min(100, (int) (self::get('duplicate_detection')['name_threshold'] ?? 80)));
    }

    /** 高风险重复时的处理：block 阻止、lead_pool / admin_confirm 进暂存池、其他继续保存。 */
    public static function duplicateAction(): string
    {
        return (string) (self::get('duplicate_detection')['action'] ?? 'lead_pool');
    }

    /** @return mixed */
    public static function defaultValue(string $key, $fallback = null)
    {
        $values = self::get('default_values');
        return $values[$key] ?? $fallback;
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
