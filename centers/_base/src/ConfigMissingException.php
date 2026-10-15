<?php
declare(strict_types=1);

namespace Artdon\Centers\Base;

use RuntimeException;

/** 缺少 centers/config.php。页面据此显示配置说明，而不是报错。 */
final class ConfigMissingException extends RuntimeException
{
}
