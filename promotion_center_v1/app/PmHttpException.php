<?php
declare(strict_types=1);

namespace Artdon\PromotionCenter;

class PmHttpException extends \RuntimeException
{
    public function __construct(string $message, int $status = 400, string $errorCode = 'ERROR')
    {
        parent::__construct($message);
        $this->status = $status;
        $this->errorCode = $errorCode;
    }

    /** @var int */
    public $status;

    /** @var string */
    public $errorCode;
}
