<?php
declare(strict_types=1);

namespace Artdon\CommercialCenter\Services;

use Artdon\CommercialCenter\Adapters\LegacyPermissionAdapter;
use PDO;

final class QuotePermissionService
{
    public const ACTIONS = [
        'view', 'create', 'edit', 'delete', 'approve', 'reject', 'export', 'print',
        'send', 'convert', 'view_cost', 'view_profit', 'edit_price', 'edit_locked',
    ];

    private const LEGACY_COLUMNS = [
        'view' => 'can_access',
        'create' => 'quote_create',
        'edit' => 'quote_edit',
        'delete' => 'quote_delete',
        'approve' => 'quote_approve',
        'reject' => 'quote_approve',
        'export' => 'export_pdf_excel',
        'print' => 'export_pdf_excel',
        'send' => 'quote_edit',
        'convert' => 'order_convert',
        'view_cost' => 'product_view',
        'view_profit' => 'quote_review_view',
        'edit_price' => 'quote_approve',
        'edit_locked' => 'quote_approve',
    ];

    private PDO $connection;

    public function __construct(?PDO $connection = null)
    {
        $this->connection = $connection ?? db();
    }

    public function assert(array $actor, string $action): void
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException('未知报价权限。');
        }
        if (!empty($actor['is_super_admin'])) {
            return;
        }
        if (!empty($actor['is_test_actor']) && in_array($action, $actor['test_permissions'] ?? [], true)) {
            return;
        }

        $unified = (new LegacyPermissionAdapter())->check('commercial.quote.' . $action);
        if (!empty($unified['allowed'])) {
            return;
        }

        $column = self::LEGACY_COLUMNS[$action];
        $columns = $this->tableColumns('quote_user_permissions');
        if (!in_array($column, $columns, true)) {
            throw new \RuntimeException('报价权限结构缺少：' . $column);
        }
        $username = trim((string)($actor['username'] ?? ''));
        $userId = (string)($actor['id'] ?? $actor['user_id'] ?? '');
        $statement = $this->connection->prepare(
            "SELECT `{$column}` FROM quote_user_permissions
             WHERE (username=? AND username<>'') OR (CAST(user_id AS CHAR)=? AND ?<>'')
             ORDER BY id DESC LIMIT 1"
        );
        $statement->execute([$username, $userId, $userId]);
        if ((int)$statement->fetchColumn() === 1) {
            return;
        }
        throw new \RuntimeException('当前账号没有报价权限：' . $action);
    }

    public function allows(array $actor, string $action): bool
    {
        try {
            $this->assert($actor, $action);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function tableColumns(string $table): array
    {
        $statement = $this->connection->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
        );
        $statement->execute([$table]);
        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }
}
