<?php
declare(strict_types=1);

namespace Artdon\Centers\Base;

/**
 * 表单控件。统一输出 label、输入框、说明和错误提示，错误时自动关联 aria-describedby。
 */
final class Form
{
    /**
     * @param array<string, string> $errors 字段名 => 错误
     * @param array<string, mixed> $attrs type、required、autocomplete、hint、readonly、maxlength、placeholder、autofocus、
     *                                    prefix（同一页面多个表单有同名字段时，用来区分 id）
     */
    public static function input(string $name, string $label, string $value, array $errors = [], array $attrs = []): string
    {
        $id = (string) ($attrs['prefix'] ?? 'f') . '-' . $name;
        $error = $errors[$name] ?? '';
        $hint = (string) ($attrs['hint'] ?? '');
        $describedBy = [];
        if ($hint !== '') {
            $describedBy[] = $id . '-hint';
        }
        if ($error !== '') {
            $describedBy[] = $id . '-error';
        }
        $type = (string) ($attrs['type'] ?? 'text');
        $html = '<div class="field' . ($error !== '' ? ' has-error' : '') . '">';
        $html .= '<label class="field-label" for="' . Layout::e($id) . '">' . Layout::e($label) . (!empty($attrs['required']) ? '<span class="field-req" aria-hidden="true">*</span>' : '') . '</label>';
        $html .= '<input class="input" id="' . Layout::e($id) . '" name="' . Layout::e($name) . '" type="' . Layout::e($type) . '"';
        if ($type !== 'password') {
            $html .= ' value="' . Layout::e($value) . '"';
        }
        foreach (['autocomplete', 'maxlength', 'placeholder', 'inputmode', 'minlength'] as $key) {
            if (isset($attrs[$key]) && $attrs[$key] !== '') {
                $html .= ' ' . $key . '="' . Layout::e($attrs[$key]) . '"';
            }
        }
        foreach (['required', 'readonly', 'autofocus', 'disabled'] as $flag) {
            if (!empty($attrs[$flag])) {
                $html .= ' ' . $flag;
            }
        }
        if ($error !== '') {
            $html .= ' aria-invalid="true"';
        }
        if ($describedBy) {
            $html .= ' aria-describedby="' . Layout::e(implode(' ', $describedBy)) . '"';
        }
        $html .= '>';
        if ($hint !== '') {
            $html .= '<p class="field-hint" id="' . Layout::e($id) . '-hint">' . Layout::e($hint) . '</p>';
        }
        if ($error !== '') {
            $html .= '<p class="field-error" id="' . Layout::e($id) . '-error">' . Layout::e($error) . '</p>';
        }
        return $html . '</div>';
    }

    /**
     * @param array<string|int, string> $options 值 => 显示文字
     * @param array<string, string> $errors
     * @param array<string, mixed> $attrs required、hint、placeholder（第一项空选项的文字）
     */
    public static function select(string $name, string $label, array $options, string $selected, array $errors = [], array $attrs = []): string
    {
        $id = (string) ($attrs['prefix'] ?? 'f') . '-' . $name;
        $error = $errors[$name] ?? '';
        $hint = (string) ($attrs['hint'] ?? '');
        $html = '<div class="field' . ($error !== '' ? ' has-error' : '') . '">';
        $html .= '<label class="field-label" for="' . Layout::e($id) . '">' . Layout::e($label) . (!empty($attrs['required']) ? '<span class="field-req" aria-hidden="true">*</span>' : '') . '</label>';
        $html .= '<select class="input select" id="' . Layout::e($id) . '" name="' . Layout::e($name) . '"' . (!empty($attrs['required']) ? ' required' : '') . ($error !== '' ? ' aria-invalid="true" aria-describedby="' . Layout::e($id) . '-error"' : '') . '>';
        if (isset($attrs['placeholder'])) {
            $html .= '<option value="">' . Layout::e((string) $attrs['placeholder']) . '</option>';
        }
        foreach ($options as $value => $text) {
            $value = (string) $value;
            $html .= '<option value="' . Layout::e($value) . '"' . ($value === $selected ? ' selected' : '') . '>' . Layout::e($text) . '</option>';
        }
        $html .= '</select>';
        if ($hint !== '') {
            $html .= '<p class="field-hint">' . Layout::e($hint) . '</p>';
        }
        if ($error !== '') {
            $html .= '<p class="field-error" id="' . Layout::e($id) . '-error">' . Layout::e($error) . '</p>';
        }
        return $html . '</div>';
    }

    /** 多行文字。 @param array<string, mixed> $attrs required、hint、maxlength、placeholder、prefix */
    public static function textarea(string $name, string $label, string $value, array $errors = [], array $attrs = []): string
    {
        $id = (string) ($attrs['prefix'] ?? 'f') . '-' . $name;
        $error = $errors[$name] ?? '';
        $html = '<div class="field' . ($error !== '' ? ' has-error' : '') . '">';
        $html .= '<label class="field-label" for="' . Layout::e($id) . '">' . Layout::e($label) . (!empty($attrs['required']) ? '<span class="field-req" aria-hidden="true">*</span>' : '') . '</label>';
        $html .= '<textarea class="input" id="' . Layout::e($id) . '" name="' . Layout::e($name) . '"' . (!empty($attrs['required']) ? ' required' : '')
            . (isset($attrs['maxlength']) ? ' maxlength="' . Layout::e($attrs['maxlength']) . '"' : '')
            . (isset($attrs['placeholder']) ? ' placeholder="' . Layout::e($attrs['placeholder']) . '"' : '') . '>' . Layout::e($value) . '</textarea>';
        if (!empty($attrs['hint'])) {
            $html .= '<p class="field-hint">' . Layout::e((string) $attrs['hint']) . '</p>';
        }
        if ($error !== '') {
            $html .= '<p class="field-error">' . Layout::e($error) . '</p>';
        }
        return $html . '</div>';
    }

    /** 只读展示一项（用于不能自己修改的字段，如角色）。 */
    public static function readonly(string $label, string $value, string $hint = ''): string
    {
        $html = '<div class="field"><span class="field-label">' . Layout::e($label) . '</span><div class="field-static">' . Layout::e($value !== '' ? $value : '—') . '</div>';
        if ($hint !== '') {
            $html .= '<p class="field-hint">' . Layout::e($hint) . '</p>';
        }
        return $html . '</div>';
    }
}
