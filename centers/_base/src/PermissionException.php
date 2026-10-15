<?php
declare(strict_types=1);

namespace Artdon\Centers\Base;

use RuntimeException;

/** 没有权限做这个操作。接口返回 403。 */
final class PermissionException extends RuntimeException
{
}
