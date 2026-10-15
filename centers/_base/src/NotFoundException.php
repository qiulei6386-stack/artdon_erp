<?php
declare(strict_types=1);

namespace Artdon\Centers\Base;

use RuntimeException;

/** 要操作的数据不存在。接口返回 404。 */
final class NotFoundException extends RuntimeException
{
}
