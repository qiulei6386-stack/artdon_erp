<?php
declare(strict_types=1);

namespace Artdon\Centers\Base;

/**
 * 参数校验。逐个字段取值并检查，最后一次性抛出所有错误：
 *
 *   $v = new Validator($input);
 *   $name  = $v->text('real_name', '姓名', ['required' => true, 'max' => 120]);
 *   $email = $v->email('email', '邮箱');
 *   $v->check();   // 有错误时抛出 ValidationException
 */
final class Validator
{
    /** @var array<string, mixed> */
    private $input;

    /** @var array<string, string> */
    private $errors = [];

    /** @param array<string, mixed> $input */
    public function __construct(array $input)
    {
        $this->input = $input;
    }

    /**
     * 文本字段，去掉首尾空格。
     * 选项：required（必填）、max（最多字符数）、pattern（正则）、pattern_message（不匹配时的提示）、default
     *
     * @param array<string, mixed> $rules
     */
    public function text(string $field, string $label, array $rules = []): string
    {
        $raw = $this->input[$field] ?? ($rules['default'] ?? '');
        if (is_array($raw)) {
            $this->fail($field, $label . '格式不正确');
            return '';
        }
        $value = trim((string) $raw);
        if ($value === '') {
            if (!empty($rules['required'])) {
                $this->fail($field, $label . '不能为空');
            }
            return '';
        }
        $max = (int) ($rules['max'] ?? 0);
        if ($max > 0 && mb_strlen($value, 'UTF-8') > $max) {
            $this->fail($field, $label . '不能超过 ' . $max . ' 个字符');
        }
        if (!empty($rules['pattern']) && !preg_match((string) $rules['pattern'], $value)) {
            $this->fail($field, (string) ($rules['pattern_message'] ?? ($label . '格式不正确')));
        }
        return $value;
    }

    /** 邮箱，可以为空（除非 required）。 */
    public function email(string $field, string $label, bool $required = false): string
    {
        $value = $this->text($field, $label, ['required' => $required, 'max' => 190]);
        if ($value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->fail($field, $label . '格式不正确');
        }
        return $value;
    }

    /** 非负整数，空值返回 0。 */
    public function id(string $field, string $label, bool $required = false): int
    {
        $raw = $this->input[$field] ?? '';
        if ($raw === '' || $raw === null) {
            if ($required) {
                $this->fail($field, '请选择' . $label);
            }
            return 0;
        }
        if (!is_scalar($raw) || !preg_match('/^\d+$/', (string) $raw)) {
            $this->fail($field, $label . '格式不正确');
            return 0;
        }
        return (int) $raw;
    }

    /** 原样取出（不去空格），用于密码。 */
    public function raw(string $field): string
    {
        $raw = $this->input[$field] ?? '';
        return is_scalar($raw) ? (string) $raw : '';
    }

    /** 记录一个错误（同一字段只保留第一个）。 */
    public function fail(string $field, string $message): void
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = $message;
        }
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** 有错误时抛出。 */
    public function check(): void
    {
        if ($this->errors) {
            throw new ValidationException($this->errors);
        }
    }
}
