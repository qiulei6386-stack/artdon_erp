<?php
declare(strict_types=1);

namespace Artdon\Centers\Base;

use PDO;

/**
 * 业务字典（只读）。读正式库已有的 crm_dictionary_items：客户等级、来源、状态、生命周期、推广方式、国家等。
 * 维护功能以后放到新系统设置中心；现在新系统只读取、不修改。表不存在时返回空。
 */
final class Dictionary
{
    /** @var array<string, list<array<string, mixed>>> */
    private static $cache = [];

    /**
     * 启用的选项，按排序。
     *
     * @return list<array{key: string, label: string, label_en: string, color: string, is_default: bool, extra: array<string, mixed>}>
     */
    public static function items(string $type): array
    {
        if (!array_key_exists($type, self::$cache)) {
            self::$cache[$type] = [];
            if (Db::tableExists('crm_dictionary_items')) {
                $stmt = Db::pdo()->prepare('SELECT item_key, name_cn, name_en, color, is_default, extra_config_json FROM crm_dictionary_items WHERE type_key = ? AND is_enabled = 1 AND deleted_at IS NULL ORDER BY sort_order, id');
                $stmt->execute([$type]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $extra = json_decode((string) ($row['extra_config_json'] ?? ''), true);
                    self::$cache[$type][] = [
                        'key' => (string) $row['item_key'],
                        'label' => (string) ($row['name_cn'] !== '' && $row['name_cn'] !== null ? $row['name_cn'] : $row['item_key']),
                        'label_en' => (string) ($row['name_en'] ?? ''),
                        'color' => (string) ($row['color'] ?? ''),
                        'is_default' => (int) $row['is_default'] === 1,
                        'extra' => is_array($extra) ? $extra : [],
                    ];
                }
            }
        }
        return self::$cache[$type];
    }

    /** 键 => 中文名，用于下拉框。 @return array<string, string> */
    public static function options(string $type): array
    {
        $out = [];
        foreach (self::items($type) as $item) {
            $out[$item['key']] = $item['label'];
        }
        return $out;
    }

    public static function has(string $type, string $key): bool
    {
        return isset(self::options($type)[$key]);
    }

    /** 中文名；字典里没有（或已停用）时原样返回键。 */
    public static function label(string $type, string $key): string
    {
        return self::options($type)[$key] ?? $key;
    }

    /** 默认选项：标了默认的；没有则第一项；字典为空时用 $fallback。 */
    public static function defaultKey(string $type, string $fallback = ''): string
    {
        $items = self::items($type);
        foreach ($items as $item) {
            if ($item['is_default']) {
                return $item['key'];
            }
        }
        return $items[0]['key'] ?? $fallback;
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
