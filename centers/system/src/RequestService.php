<?php
declare(strict_types=1);

namespace Artdon\Centers\System;

use Artdon\Centers\Base\Audit;
use Artdon\Centers\Base\Db;
use Artdon\Centers\Base\NotFoundException;
use Artdon\Centers\Base\Permission;
use Artdon\Centers\Base\PermissionException;
use Artdon\Centers\Base\ValidationException;
use Artdon\Centers\Base\Validator;
use PDO;
use RuntimeException;
use Throwable;

/**
 * 权限申请与临时授权。读写 crm_permission_requests、crm_permission_grants、crm_permission_request_logs。
 *
 * 谁能申请：所有正常状态的账号。不能申请自己已经有的权限，同一项权限同时只能有一个待审批的申请。
 * 谁能审批（按权限判断，不按角色名称）：
 *   - 不能审批自己的申请；
 *   - 超级管理员：全部；
 *   - 有 dangerous.permission_admin（权限管理员）：全部；
 *   - 有 permission_request.approve_all：全部普通权限，且只能批自己也有的权限；
 *   - 有 permission_request.approve_department：本部门的普通权限，且只能批自己也有的权限；
 *   - 高风险权限（风险等级 high / dangerous，或编码以 dangerous. 开头）只能由超管或权限管理员审批，且必须写审批备注。
 * 授权期限：1 至 90 天；永久授权只有超级管理员能给。
 * 撤销和延长临时授权：超级管理员、权限管理员或有 permission_request.revoke 的人。
 * 过期按时间判断，不改数据。
 */
final class RequestService
{
    public const STATUS_LABELS = ['pending' => '待审批', 'approved' => '已批准', 'rejected' => '已拒绝', 'cancelled' => '已取消', 'expired' => '已过期'];
    public const URGENCY_LABELS = ['normal' => '普通', 'urgent' => '紧急', 'critical' => '非常紧急'];
    public const MAX_DAYS = 90;

    /** @var PDO */
    private $pdo;

    /** @var array<string, mixed> */
    private $operator;

    public function __construct(array $operator, ?PDO $pdo = null)
    {
        $this->operator = $operator;
        $this->pdo = $pdo ?? Db::pdo();
    }

    // ---------------------------------------------------------------- 能力判断

    public function isSuper(): bool
    {
        return (int) ($this->operator['is_super_admin'] ?? 0) === 1;
    }

    public function can(string $permission): bool
    {
        return Permission::allows($this->operator, $permission);
    }

    /** 能不能审批申请（任意一种审批权限）。 */
    public function isApprover(): bool
    {
        return $this->isSuper() || $this->can('dangerous.permission_admin') || $this->can('permission_request.approve_all') || $this->can('permission_request.approve_department');
    }

    /** 能不能看全部申请记录和全部临时授权。 */
    public function canSeeAll(): bool
    {
        return $this->isSuper() || $this->can('dangerous.permission_admin') || $this->can('permission_request.approve_all') || $this->can('permission_request.revoke');
    }

    public function canRevoke(): bool
    {
        return $this->isSuper() || $this->can('dangerous.permission_admin') || $this->can('permission_request.revoke');
    }

    /** 高风险权限。 */
    public function isDangerous(string $permission): bool
    {
        if (strpos($permission, 'dangerous.') === 0) {
            return true;
        }
        $stmt = $this->pdo->prepare('SELECT risk_level FROM crm_permissions WHERE permission_key = ? LIMIT 1');
        $stmt->execute([$permission]);
        return in_array((string) $stmt->fetchColumn(), ['high', 'dangerous'], true);
    }

    /**
     * 能否审批这条申请。返回空字符串表示可以，否则返回原因。
     *
     * @param array<string, mixed> $request
     */
    public function approveBlockedReason(array $request): string
    {
        if ((int) $request['requester_id'] === (int) ($this->operator['id'] ?? 0)) {
            return '不能审批自己的申请';
        }
        if ($this->isSuper() || $this->can('dangerous.permission_admin')) {
            return '';
        }
        $key = (string) $request['permission_key'];
        if ($this->isDangerous($key)) {
            return '高风险权限只能由超级管理员或权限管理员审批';
        }
        if (!$this->can($key)) {
            return '不能审批超出自己权限范围的申请';
        }
        if ($this->can('permission_request.approve_all')) {
            return '';
        }
        if ($this->can('permission_request.approve_department')) {
            if ((int) ($request['department_id'] ?? 0) === 0 || (int) $request['department_id'] !== (int) ($this->operator['department_id'] ?? 0)) {
                return '只能审批本部门的申请';
            }
            return '';
        }
        return '没有审批权限';
    }

    // ---------------------------------------------------------------- 查询

    /** 可以申请的权限（排除自己已经有的）。 @return list<array{module: string, label: string, permissions: list<array<string, string>>}> */
    public function requestable(): array
    {
        $groups = (new RoleService($this->operator, $this->pdo))->catalog();
        $out = [];
        foreach ($groups as $group) {
            $items = array_values(array_filter($group['permissions'], function (array $p): bool {
                return !$this->can($p['key']);
            }));
            if ($items) {
                $group['permissions'] = $items;
                $out[] = $group;
            }
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function myRequests(): array
    {
        return $this->requests('pr.requester_id = ?', [(int) ($this->operator['id'] ?? 0)], 100);
    }

    /** 待我审批：所有待审批申请中我能批的。 @return list<array<string, mixed>> */
    public function pendingForMe(): array
    {
        if (!$this->isApprover()) {
            return [];
        }
        return array_values(array_filter($this->requests("pr.status = 'pending'", [], 500), function (array $r): bool {
            return $this->approveBlockedReason($r) === '';
        }));
    }

    /** @return list<array<string, mixed>> */
    public function allRequests(string $status = ''): array
    {
        if (!$this->canSeeAll()) {
            throw new PermissionException('没有查看全部申请的权限');
        }
        if (isset(self::STATUS_LABELS[$status])) {
            return $this->requests('pr.status = ?', [$status], 300);
        }
        return $this->requests('1 = 1', [], 300);
    }

    /**
     * 有效的临时授权。$all 为 false 时只看自己的。
     *
     * @return list<array<string, mixed>>
     */
    public function activeGrants(bool $all): array
    {
        $now = Db::now();
        $where = "g.status = 'active' AND g.effect = 'allow' AND (g.expires_at IS NULL OR g.expires_at > ?)";
        $params = [$now];
        if (!$all) {
            $where .= ' AND g.user_id = ?';
            $params[] = (int) ($this->operator['id'] ?? 0);
        }
        $stmt = $this->pdo->prepare(
            'SELECT g.id, g.request_id, g.user_id, g.permission_key, g.starts_at, g.expires_at, g.is_temporary, g.granted_by_name, g.granted_at,'
            . ' u.real_name, u.username, p.description AS permission_label, p.risk_level'
            . ' FROM crm_permission_grants g LEFT JOIN crm_users u ON u.id = g.user_id LEFT JOIN crm_permissions p ON p.permission_key = g.permission_key'
            . ' WHERE ' . $where . ' ORDER BY g.expires_at IS NULL, g.expires_at ASC LIMIT 500'
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findRequest(int $id): ?array
    {
        $rows = $this->requests('pr.id = ?', [$id], 1);
        return $rows[0] ?? null;
    }

    // ---------------------------------------------------------------- 申请

    public function submit(array $input): int
    {
        $v = new Validator($input);
        $key = $v->text('permission_key', '申请的权限', ['required' => true, 'max' => 120]);
        $reason = $v->text('reason', '申请原因', ['required' => true, 'max' => 2000]);
        $urgency = $v->text('urgency', '紧急程度', ['default' => 'normal']);
        $days = $v->id('expected_days', '需要天数');
        $meta = null;
        if ($key !== '') {
            $stmt = $this->pdo->prepare('SELECT permission_key, module, action FROM crm_permissions WHERE permission_key = ? LIMIT 1');
            $stmt->execute([$key]);
            $meta = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$meta) {
                $v->fail('permission_key', '权限不存在');
            } elseif ($this->can($key)) {
                $v->fail('permission_key', '你已经有这项权限了');
            } else {
                $stmt = $this->pdo->prepare("SELECT id FROM crm_permission_requests WHERE requester_id = ? AND permission_key = ? AND status = 'pending' LIMIT 1");
                $stmt->execute([(int) $this->operator['id'], $key]);
                if ($stmt->fetchColumn()) {
                    $v->fail('permission_key', '这项权限已经有一个待审批的申请');
                }
            }
        }
        if (!isset(self::URGENCY_LABELS[$urgency])) {
            $v->fail('urgency', '紧急程度不正确');
        }
        if ($days < 1 || $days > self::MAX_DAYS) {
            $v->fail('expected_days', '需要天数为 1 至 ' . self::MAX_DAYS . ' 天');
        }
        $v->check();
        $now = Db::now();
        $this->pdo->prepare(
            'INSERT INTO crm_permission_requests (requester_id, requester_name, department_id, role_id, permission_key, module, action, request_scope_type, reason, urgency, expected_days, status, created_at, updated_at)'
            . " VALUES (?, ?, ?, ?, ?, ?, ?, 'self', ?, ?, ?, 'pending', ?, ?)"
        )->execute([
            (int) $this->operator['id'], (string) $this->operator['username'],
            ((int) ($this->operator['department_id'] ?? 0)) ?: null, ((int) ($this->operator['role_id'] ?? 0)) ?: null,
            $key, (string) $meta['module'], (string) $meta['action'], $reason, $urgency, $days, $now, $now,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $after = ['permission_key' => $key, 'reason' => $reason, 'urgency' => $urgency, 'expected_days' => $days];
        $this->log($id, (int) $this->operator['id'], 'submit', null, $after, $reason);
        Audit::log('permission_request_submit', 'permission', 'request', $id, null, $after, $this->operator);
        return $id;
    }

    public function cancel(int $id): void
    {
        $request = $this->pending($id);
        if ((int) $request['requester_id'] !== (int) $this->operator['id']) {
            throw new PermissionException('只能取消自己的申请');
        }
        $this->pdo->prepare("UPDATE crm_permission_requests SET status = 'cancelled', updated_at = ? WHERE id = ?")->execute([Db::now(), $id]);
        $this->log($id, (int) $request['requester_id'], 'cancel', ['status' => 'pending'], ['status' => 'cancelled'], '申请人取消');
        Audit::log('permission_request_cancel', 'permission', 'request', $id, ['status' => 'pending'], ['status' => 'cancelled'], $this->operator);
    }

    /**
     * 批准申请并生成授权。input：days（1-90）或 permanent=1（仅超管），note（高风险必填）。
     *
     * @return int 授权编号
     */
    public function approve(int $id, array $input): int
    {
        $request = $this->pending($id);
        $reason = $this->approveBlockedReason($request);
        if ($reason !== '') {
            throw new PermissionException($reason);
        }
        $key = (string) $request['permission_key'];
        $v = new Validator($input);
        $note = $v->text('note', '审批备注', ['max' => 2000]);
        $permanent = !empty($input['permanent']) && (string) $input['permanent'] !== '0';
        $days = $v->id('days', '授权天数');
        if ($permanent && !$this->isSuper()) {
            $v->fail('days', '永久授权只有超级管理员可以给');
        } elseif (!$permanent && ($days < 1 || $days > self::MAX_DAYS)) {
            $v->fail('days', '授权天数为 1 至 ' . self::MAX_DAYS . ' 天');
        }
        if ($this->isDangerous($key) && $note === '') {
            $v->fail('note', '高风险权限必须写审批备注');
        }
        $v->check();

        $now = Db::now();
        $expires = $permanent ? null : date('Y-m-d H:i:s', time() + $days * 86400);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("UPDATE crm_permission_requests SET status = 'approved', approved_by = ?, approved_by_name = ?, approved_at = ?, approval_note = ?, updated_at = ? WHERE id = ? AND status = 'pending'")
                ->execute([(int) $this->operator['id'], (string) $this->operator['username'], $now, $note, $now, $id]);
            $this->pdo->prepare(
                'INSERT INTO crm_permission_grants (request_id, user_id, permission_key, module, action, effect, scope_type, granted_by, granted_by_name, granted_at, starts_at, expires_at, is_temporary, status, created_at, updated_at)'
                . " VALUES (?, ?, ?, ?, ?, 'allow', 'self', ?, ?, ?, ?, ?, ?, 'active', ?, ?)"
            )->execute([
                $id, (int) $request['requester_id'], $key, (string) $request['module'], (string) $request['action'],
                (int) $this->operator['id'], (string) $this->operator['username'], $now, $now, $expires, $permanent ? 0 : 1, $now, $now,
            ]);
            $grantId = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        Permission::clearCache();
        $after = ['grant_id' => $grantId, 'expires_at' => $expires, 'permanent' => $permanent];
        $this->log($id, (int) $request['requester_id'], 'approve', ['status' => 'pending'], $after, $note);
        Audit::log('permission_request_approve', 'permission', 'request', $id, ['status' => 'pending'], $after, $this->operator);
        return $grantId;
    }

    public function reject(int $id, array $input): void
    {
        $request = $this->pending($id);
        $reason = $this->approveBlockedReason($request);
        if ($reason !== '') {
            throw new PermissionException($reason);
        }
        $v = new Validator($input);
        $why = $v->text('reason', '拒绝原因', ['required' => true, 'max' => 500]);
        $v->check();
        $now = Db::now();
        $this->pdo->prepare("UPDATE crm_permission_requests SET status = 'rejected', rejected_by = ?, rejected_by_name = ?, rejected_at = ?, reject_reason = ?, updated_at = ? WHERE id = ?")
            ->execute([(int) $this->operator['id'], (string) $this->operator['username'], $now, $why, $now, $id]);
        $this->log($id, (int) $request['requester_id'], 'reject', ['status' => 'pending'], ['status' => 'rejected', 'reason' => $why], $why);
        Audit::log('permission_request_reject', 'permission', 'request', $id, ['status' => 'pending'], ['status' => 'rejected', 'reason' => $why], $this->operator);
    }

    // ---------------------------------------------------------------- 授权

    public function revoke(int $grantId, array $input): void
    {
        $grant = $this->activeGrant($grantId);
        $v = new Validator($input);
        $why = $v->text('reason', '撤销原因', ['required' => true, 'max' => 500]);
        $v->check();
        $now = Db::now();
        $this->pdo->prepare("UPDATE crm_permission_grants SET status = 'revoked', revoked_by = ?, revoked_by_name = ?, revoked_at = ?, revoke_reason = ?, updated_at = ? WHERE id = ?")
            ->execute([(int) $this->operator['id'], (string) $this->operator['username'], $now, $why, $now, $grantId]);
        if (!empty($grant['request_id'])) {
            $this->pdo->prepare("UPDATE crm_permission_requests SET status = 'cancelled', updated_at = ? WHERE id = ? AND status = 'approved'")->execute([$now, (int) $grant['request_id']]);
        }
        Permission::clearCache();
        $this->log(((int) $grant['request_id']) ?: null, (int) $grant['user_id'], 'revoke', ['status' => 'active'], ['status' => 'revoked', 'reason' => $why], $why);
        Audit::log('permission_grant_revoke', 'permission', 'grant', $grantId, ['status' => 'active'], ['status' => 'revoked', 'reason' => $why], $this->operator);
    }

    /** 延长到指定日期（当天 23:59:59），不能超过从今天起 90 天；永久授权不需要延长。 */
    public function extend(int $grantId, array $input): void
    {
        $grant = $this->activeGrant($grantId);
        if ($grant['expires_at'] === null) {
            throw new RuntimeException('这是永久授权，不需要延长');
        }
        $v = new Validator($input);
        $date = $v->text('expires_on', '新的到期日', ['required' => true, 'pattern' => '/^\d{4}-\d{2}-\d{2}$/', 'pattern_message' => '日期格式应为 年-月-日']);
        $note = $v->text('note', '备注', ['max' => 500]);
        $expires = $date !== '' ? $date . ' 23:59:59' : '';
        if ($expires !== '' && !isset($v->errors()['expires_on'])) {
            $ts = strtotime($expires);
            if ($ts === false || $ts <= strtotime((string) $grant['expires_at'])) {
                $v->fail('expires_on', '新的到期日要晚于现在的到期时间');
            } elseif ($ts > time() + self::MAX_DAYS * 86400 && !$this->isSuper()) {
                $v->fail('expires_on', '最多延长到今天起 ' . self::MAX_DAYS . ' 天内');
            }
        }
        $v->check();
        $this->pdo->prepare('UPDATE crm_permission_grants SET expires_at = ?, updated_at = ? WHERE id = ?')->execute([$expires, Db::now(), $grantId]);
        $this->log(((int) $grant['request_id']) ?: null, (int) $grant['user_id'], 'extend', ['expires_at' => $grant['expires_at']], ['expires_at' => $expires], $note);
        Audit::log('permission_grant_extend', 'permission', 'grant', $grantId, ['expires_at' => $grant['expires_at']], ['expires_at' => $expires], $this->operator);
    }

    // ---------------------------------------------------------------- 内部

    /** @return list<array<string, mixed>> */
    private function requests(string $where, array $params, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT pr.*, u.real_name AS requester_real_name, d.name AS department_name, p.description AS permission_label, p.risk_level'
            . ' FROM crm_permission_requests pr'
            . ' LEFT JOIN crm_users u ON u.id = pr.requester_id'
            . ' LEFT JOIN crm_departments d ON d.id = pr.department_id'
            . ' LEFT JOIN crm_permissions p ON p.permission_key = pr.permission_key'
            . ' WHERE ' . $where
            . " ORDER BY CASE WHEN pr.status = 'pending' THEN 0 ELSE 1 END, CASE pr.urgency WHEN 'critical' THEN 0 WHEN 'urgent' THEN 1 ELSE 2 END, pr.id DESC"
            . ' LIMIT ' . max(1, $limit)
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function pending(int $id): array
    {
        $request = $this->findRequest($id);
        if (!$request) {
            throw new NotFoundException('申请不存在');
        }
        if ((string) $request['status'] !== 'pending') {
            throw new RuntimeException('这个申请已经处理过了');
        }
        return $request;
    }

    private function activeGrant(int $grantId): array
    {
        if (!$this->canRevoke()) {
            throw new PermissionException('没有管理临时授权的权限');
        }
        $stmt = $this->pdo->prepare('SELECT * FROM crm_permission_grants WHERE id = ? LIMIT 1');
        $stmt->execute([$grantId]);
        $grant = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$grant) {
            throw new NotFoundException('授权不存在');
        }
        $expired = $grant['expires_at'] !== null && strtotime((string) $grant['expires_at']) <= time();
        if ((string) $grant['status'] !== 'active' || $expired) {
            throw new RuntimeException('这个授权已经失效');
        }
        return $grant;
    }

    private function log(?int $requestId, int $userId, string $action, ?array $before, ?array $after, string $note): void
    {
        try {
            $this->pdo->prepare('INSERT INTO crm_permission_request_logs (request_id, user_id, operator_id, operator_name, action, before_json, after_json, note, ip, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    $requestId, $userId, (int) ($this->operator['id'] ?? 0), (string) ($this->operator['username'] ?? ''), $action,
                    $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
                    $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
                    $note, Audit::ip(), Audit::userAgent(), Db::now(),
                ]);
        } catch (Throwable $e) {
            error_log('centers permission request log failed: ' . $e->getMessage());
        }
    }
}
