<?php
declare(strict_types=1);

namespace Artdon\Centers\System;

use Artdon\Centers\Base\Db;
use Artdon\Centers\Base\Permission;
use Artdon\Centers\Base\PermissionException;
use PDO;

/**
 * 日志查看：登录日志（crm_login_logs）、操作日志（crm_audit_logs）。只读。
 * 有 logs.view_all 看全部；只有 logs.view_own 时只看自己的记录。
 * 新系统写的登录日志内容以“[新系统]”开头，可以按来源筛选。
 */
final class LogService
{
    public const PER_PAGE = 50;
    public const LOGIN_STATUS = ['success' => '登录成功', 'fail' => '登录失败', 'logout' => '退出'];

    /** @var PDO */
    private $pdo;

    /** @var array<string, mixed> */
    private $operator;

    public function __construct(array $operator, ?PDO $pdo = null)
    {
        $this->operator = $operator;
        $this->pdo = $pdo ?? Db::pdo();
        if (!Permission::allows($operator, 'logs.view_own') && !Permission::allows($operator, 'logs.view_all')) {
            throw new PermissionException('没有查看日志的权限');
        }
    }

    public function seesAll(): bool
    {
        return Permission::allows($this->operator, 'logs.view_all');
    }

    /**
     * @param array<string, mixed> $f q、status、source（new / old）、from、to（Y-m-d）
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function logins(array $f, int $page = 1): array
    {
        $where = [];
        $params = [];
        $this->scope($where, $params, 'user_id');
        if (trim((string) ($f['q'] ?? '')) !== '') {
            $where[] = 'username LIKE ?';
            $params[] = '%' . addcslashes(trim((string) $f['q']), '\\%_') . '%';
        }
        if (isset(self::LOGIN_STATUS[(string) ($f['status'] ?? '')])) {
            $where[] = 'status = ?';
            $params[] = (string) $f['status'];
        }
        if (($f['source'] ?? '') === 'new') {
            $where[] = "message LIKE '[新系统]%'";
        } elseif (($f['source'] ?? '') === 'old') {
            $where[] = "(message IS NULL OR message NOT LIKE '[新系统]%')";
        }
        $this->dateRange($where, $params, $f);
        return $this->page('crm_login_logs', 'id, user_id, username, status, ip, user_agent, message, created_at', $where, $params, $page);
    }

    /**
     * @param array<string, mixed> $f q（操作人）、module、action、from、to
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function audits(array $f, int $page = 1): array
    {
        $where = [];
        $params = [];
        $this->scope($where, $params, 'user_id');
        if (trim((string) ($f['q'] ?? '')) !== '') {
            $where[] = 'operator_name LIKE ?';
            $params[] = '%' . addcslashes(trim((string) $f['q']), '\\%_') . '%';
        }
        if (trim((string) ($f['module'] ?? '')) !== '') {
            $where[] = 'module = ?';
            $params[] = trim((string) $f['module']);
        }
        if (trim((string) ($f['action'] ?? '')) !== '') {
            $where[] = 'action LIKE ?';
            $params[] = '%' . addcslashes(trim((string) $f['action']), '\\%_') . '%';
        }
        $this->dateRange($where, $params, $f);
        return $this->page('crm_audit_logs', 'id, user_id, operator_name, action, module, target_type, target_id, before_json, after_json, ip, created_at', $where, $params, $page);
    }

    /** 操作日志里出现过的模块（用于筛选下拉）。 @return list<string> */
    public function auditModules(): array
    {
        $where = [];
        $params = [];
        $this->scope($where, $params, 'user_id');
        $stmt = $this->pdo->prepare('SELECT DISTINCT module FROM crm_audit_logs' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY module LIMIT 100');
        $stmt->execute($params);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param list<string> $where @param list<mixed> $params */
    private function scope(array &$where, array &$params, string $column): void
    {
        if (!$this->seesAll()) {
            $where[] = $column . ' = ?';
            $params[] = (int) ($this->operator['id'] ?? 0);
        }
    }

    /** @param list<string> $where @param list<mixed> $params */
    private function dateRange(array &$where, array &$params, array $f): void
    {
        $from = (string) ($f['from'] ?? '');
        $to = (string) ($f['to'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $where[] = 'created_at >= ?';
            $params[] = $from . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $where[] = 'created_at <= ?';
            $params[] = $to . ' 23:59:59';
        }
    }

    /**
     * @param list<string> $where
     * @param list<mixed> $params
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    private function page(string $table, string $columns, array $where, array $params, int $page): array
    {
        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $table . $sqlWhere);
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($page, $pages));
        $stmt = $this->pdo->prepare('SELECT ' . $columns . ' FROM ' . $table . $sqlWhere . ' ORDER BY id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE));
        $stmt->execute($params);
        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }
}
