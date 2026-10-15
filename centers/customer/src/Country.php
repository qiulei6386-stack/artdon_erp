<?php
declare(strict_types=1);

namespace Artdon\Centers\Customer;

use Artdon\Centers\Base\Dictionary;

/**
 * 国家：正式库里客户的国家有的存代码（IN），有的存中文（印度）或英文（India）。
 * 显示时尽量换成中文名；按国家查找时把代码、中文、英文都算上。
 */
final class Country
{
    /** 下拉选项：代码 => 中文名（英文名）。 @return array<string, string> */
    public static function options(): array
    {
        $out = [];
        foreach (Dictionary::items('country_region') as $item) {
            $out[$item['key']] = $item['label'] . ($item['label_en'] !== '' && $item['label_en'] !== $item['label'] ? '（' . $item['label_en'] . '）' : '');
        }
        return $out;
    }

    public static function label(string $value): string
    {
        $item = self::find($value);
        return $item ? $item['label'] : $value;
    }

    /** 同一个国家的各种写法。 @return list<string> */
    public static function variants(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }
        $item = self::find($value);
        if (!$item) {
            return [$value];
        }
        return array_values(array_unique(array_filter([$value, $item['key'], $item['label'], $item['label_en']], static function (string $v): bool {
            return $v !== '';
        })));
    }

    /** 统一存成字典代码（找不到时原样）。 */
    public static function normalize(string $value): string
    {
        $item = self::find($value);
        return $item ? $item['key'] : trim($value);
    }

    /** @return array{key: string, label: string, label_en: string}|null */
    private static function find(string $value): ?array
    {
        $needle = mb_strtolower(trim($value), 'UTF-8');
        if ($needle === '') {
            return null;
        }
        foreach (Dictionary::items('country_region') as $item) {
            if (mb_strtolower($item['key'], 'UTF-8') === $needle || mb_strtolower($item['label'], 'UTF-8') === $needle || mb_strtolower($item['label_en'], 'UTF-8') === $needle) {
                return $item;
            }
        }
        return null;
    }
}
