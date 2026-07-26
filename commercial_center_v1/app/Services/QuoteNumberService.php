<?php
declare(strict_types=1);

namespace Artdon\CommercialCenter\Services;

use PDO;

final class QuoteNumberService
{
    private PDO $connection;

    public function __construct(?PDO $connection = null)
    {
        $this->connection = $connection ?? db();
    }

    public function next(): string
    {
        $prefix = 'CQ-' . date('ymd-His');
        $check = $this->connection->prepare('SELECT COUNT(*) FROM cc_quotes WHERE quote_no=?');
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $quoteNo = $prefix . '-' . strtoupper(bin2hex(random_bytes(2)));
            $check->execute([$quoteNo]);
            if ((int)$check->fetchColumn() === 0) {
                return $quoteNo;
            }
        }
        throw new \RuntimeException('无法生成唯一报价编号。');
    }
}
