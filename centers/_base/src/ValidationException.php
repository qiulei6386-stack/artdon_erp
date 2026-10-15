<?php
declare(strict_types=1);

namespace Artdon\Centers\Base;

use RuntimeException;

/** 输入校验失败。带每个字段的错误信息，页面据此在对应输入框下显示。 */
final class ValidationException extends RuntimeException
{
    /** @var array<string, string> */
    private $fieldErrors;

    /** @param array<string, string> $fieldErrors 字段名 => 错误信息 */
    public function __construct(array $fieldErrors, string $message = '')
    {
        $this->fieldErrors = $fieldErrors;
        parent::__construct($message !== '' ? $message : (string) (reset($fieldErrors) ?: '输入有误'));
    }

    /** @return array<string, string> */
    public function fieldErrors(): array
    {
        return $this->fieldErrors;
    }
}
