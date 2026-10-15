<?php
declare(strict_types=1);

namespace Artdon\Centers\Mail;

/** 邮件可能已经发出但没收到回执：提示用户核对，不能自动重发。 */
final class DeliveryUncertain extends \RuntimeException
{
}
