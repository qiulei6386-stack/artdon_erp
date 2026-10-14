<?php
declare(strict_types=1);

namespace Artdon\PromotionCenter;

class GroupService
{
    /** @var \PDO */
    private $pdo;

    /** @var PmAccess */
    private $access;

    /** @var LegacyCustomerAdapter */
    private $customers;

    public function __construct(\PDO $pdo, PmAccess $access, LegacyCustomerAdapter $customers)
    {
        $this->pdo = $pdo;
        $this->access = $access;
        $this->customers = $customers;
        PmSchema::apply($pdo);
    }

    /** @return array<int,array<string,mixed>> */
    public function listGroups(): array
    {
        $stmt = $this->pdo->query('SELECT id, name, color, visibility, created_by, created_at, updated_at FROM pm_groups WHERE deleted_at IS NULL ORDER BY id DESC');
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            if (!$this->canSee($row)) {
                continue;
            }
            $rows[] = $this->present($row, false);
        }
        return $rows;
    }

    /** @return array<string,mixed> */
    public function getGroup(int $id, int $page = 1, int $pageSize = 50): array
    {
        $group = $this->requireVisible($id);
        $page = max(1, $page);
        $pageSize = max(1, min(100, $pageSize));
        $offset = ($page - 1) * $pageSize;
        $params = [];
        $scope = $this->customers->scopeSql($params);
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM pm_group_members m JOIN crm_customers c ON c.id = m.customer_id WHERE m.group_id = ? AND c.deleted_at IS NULL AND ({$scope})");
        $count->execute(array_merge([$id], $params));
        $visible = (int) $count->fetchColumn();
        $totalStmt = $this->pdo->prepare('SELECT COUNT(*) FROM pm_group_members WHERE group_id = ?');
        $totalStmt->execute([$id]);
        $total = (int) $totalStmt->fetchColumn();
        $memberParams = [];
        $memberScope = $this->customers->scopeSql($memberParams);
        $sql = "SELECT c.id, c.customer_code, c.customer_name, c.country, c.owner_user_id, m.created_at AS joined_at
            FROM pm_group_members m
            JOIN crm_customers c ON c.id = m.customer_id
            WHERE m.group_id = ? AND c.deleted_at IS NULL AND ({$memberScope})
            ORDER BY c.customer_name, c.id
            LIMIT {$pageSize} OFFSET {$offset}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge([$id], $memberParams));
        $members = [];
        $ids = [];
        foreach ($stmt->fetchAll() as $row) {
            $customerId = (int) $row['id'];
            $ids[] = $customerId;
            $members[] = [
                'id' => $customerId,
                'customer_code' => (string) ($row['customer_code'] ?? ''),
                'customer_name' => (string) ($row['customer_name'] ?? ''),
                'country' => (string) ($row['country'] ?? ''),
                'owner_user_id' => $row['owner_user_id'] !== null ? (int) $row['owner_user_id'] : null,
                'joined_at' => (string) ($row['joined_at'] ?? ''),
                'other_groups' => [],
            ];
        }
        $others = $this->otherGroups($ids, $id);
        foreach ($members as &$member) {
            $member['other_groups'] = $others[$member['id']] ?? [];
        }
        unset($member);
        $presented = $this->present($group, false);
        $presented['visible_count'] = $visible;
        $presented['total_count'] = $total;
        $presented['members'] = $members;
        $presented['page'] = $page;
        $presented['page_size'] = $pageSize;
        return $presented;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function create(array $input): array
    {
        $this->requirePermission('promotion.create_group');
        $name = $this->cleanName((string) ($input['name'] ?? ''));
        $visibility = $this->cleanVisibility((string) ($input['visibility'] ?? 'team'));
        $color = $this->cleanColor((string) ($input['color'] ?? ''));
        $this->assertNameFree($name);
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('INSERT INTO pm_groups (name, color, visibility, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())');
            $stmt->execute([$name, $color, $visibility, $this->access->userId()]);
            $id = (int) $this->pdo->lastInsertId();
            $token = $this->remember('group_create', ['group_id' => $id], $id, 0);
            $this->audit('group_create', $id, 0, ['name' => $name, 'visibility' => $visibility]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $this->wrap($e);
        }
        $group = $this->getGroup($id);
        $group['undo_token'] = $token;
        return $group;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function rename(array $input): array
    {
        $this->requirePermission('promotion.edit_group');
        $id = (int) ($input['id'] ?? $input['group_id'] ?? 0);
        $group = $this->requireEditable($id);
        $name = $this->cleanName((string) ($input['name'] ?? ''));
        if ($name === (string) $group['name']) {
            return $this->getGroup($id);
        }
        $this->assertNameFree($name, $id);
        $old = (string) $group['name'];
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('UPDATE pm_groups SET name = ?, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL');
            $stmt->execute([$name, $id]);
            $token = $this->remember('group_rename', ['group_id' => $id, 'name' => $old], $id, 0);
            $this->audit('group_rename', $id, 0, ['from' => $old, 'to' => $name]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $this->wrap($e);
        }
        $out = $this->getGroup($id);
        $out['undo_token'] = $token;
        return $out;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function delete(array $input): array
    {
        $this->requirePermission('promotion.delete_group');
        $id = (int) ($input['id'] ?? $input['group_id'] ?? 0);
        $group = $this->requireVisible($id);
        if (!$this->canDelete($group)) {
            throw new PmHttpException('只能删除自己创建的分组', 403, 'FORBIDDEN');
        }
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('UPDATE pm_groups SET deleted_at = NOW(), deleted_by = ?, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL');
            $stmt->execute([$this->access->userId(), $id]);
            $token = $this->remember('group_delete', ['group_id' => $id], $id, 0);
            $this->audit('group_delete', $id, 0, ['name' => $group['name']]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $this->wrap($e);
        }
        return ['id' => $id, 'deleted' => true, 'undo_token' => $token, 'undo_seconds' => 10];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function addMembers(array $input): array
    {
        $this->requirePermission('promotion.edit_group');
        $id = (int) ($input['group_id'] ?? $input['id'] ?? 0);
        $this->requireEditable($id);
        $ids = $this->requireVisibleCustomers($input['customer_ids'] ?? []);
        return $this->insertMembers($id, $ids, true);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function removeMembers(array $input): array
    {
        $this->requirePermission('promotion.edit_group');
        $id = (int) ($input['group_id'] ?? $input['id'] ?? 0);
        $this->requireEditable($id);
        $ids = $this->requireVisibleCustomers($input['customer_ids'] ?? []);
        $existing = $this->existingMembers($id, $ids);
        if (!$existing) {
            throw new PmHttpException('这些客户不在当前分组里', 400, 'VALIDATION');
        }
        $this->pdo->beginTransaction();
        try {
            $this->deleteMembers($id, $existing);
            $token = $this->remember('members_remove', ['group_id' => $id, 'customer_ids' => $existing], $id, count($existing));
            $this->audit('members_remove', $id, count($existing), ['customer_ids' => $existing]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $this->wrap($e);
        }
        $out = $this->getGroup($id);
        $out['removed'] = count($existing);
        $out['undo_token'] = $token;
        return $out;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function moveMembers(array $input): array
    {
        $this->requirePermission('promotion.move_customer');
        return $this->transfer($input, true);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function copyMembers(array $input): array
    {
        $this->requirePermission('promotion.move_customer');
        return $this->transfer($input, false);
    }

    public function undo(string $token): array
    {
        $token = trim($token);
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            throw new PmHttpException('撤销凭证无效', 400, 'VALIDATION');
        }
        $stmt = $this->pdo->prepare('SELECT * FROM pm_undo WHERE token = ? AND actor_id = ? LIMIT 1');
        $stmt->execute([$token, $this->access->userId()]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new PmHttpException('撤销凭证无效', 400, 'VALIDATION');
        }
        if ($row['used_at'] !== null) {
            throw new PmHttpException('这次操作已经撤销过了', 400, 'CONFLICT');
        }
        $valid = $this->pdo->prepare('SELECT expires_at >= NOW() FROM pm_undo WHERE id = ?');
        $valid->execute([(int) $row['id']]);
        if ((int) $valid->fetchColumn() !== 1) {
            throw new PmHttpException('已超过 10 秒，不能撤销', 400, 'CONFLICT');
        }
        $payload = json_decode((string) $row['payload_json'], true);
        if (!is_array($payload)) {
            throw new PmHttpException('撤销内容无法读取', 400, 'VALIDATION');
        }
        $this->pdo->beginTransaction();
        try {
            $this->applyUndo((string) $row['action'], $payload);
            $mark = $this->pdo->prepare('UPDATE pm_undo SET used_at = NOW() WHERE id = ? AND used_at IS NULL');
            $mark->execute([(int) $row['id']]);
            $this->audit('undo', isset($payload['group_id']) ? (int) $payload['group_id'] : null, 0, ['action' => $row['action']]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $this->wrap($e);
        }
        return ['undone' => true, 'action' => (string) $row['action']];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function transfer(array $input, bool $move): array
    {
        $fromId = (int) ($input['from_id'] ?? $input['group_id'] ?? 0);
        $toId = (int) ($input['to_id'] ?? $input['target_group_id'] ?? 0);
        if ($fromId <= 0 || $toId <= 0 || $fromId === $toId) {
            throw new PmHttpException('请选择两个不同的分组', 400, 'VALIDATION');
        }
        $this->requireEditable($fromId);
        $this->requireEditable($toId);
        $ids = $this->requireVisibleCustomers($input['customer_ids'] ?? []);
        $existing = $this->existingMembers($fromId, $ids);
        if (!$existing) {
            throw new PmHttpException('这些客户不在当前分组里', 400, 'VALIDATION');
        }
        $this->pdo->beginTransaction();
        try {
            $inserted = $this->insertMemberRows($toId, $existing);
            if ($move) {
                $this->deleteMembers($fromId, $existing);
            }
            $action = $move ? 'members_move' : 'members_copy';
            $token = $this->remember($action, [
                'from_id' => $fromId,
                'to_id' => $toId,
                'customer_ids' => $existing,
                'inserted_ids' => $inserted,
            ], $fromId, count($existing));
            $this->audit($action, $fromId, count($existing), ['to_id' => $toId, 'customer_ids' => $existing, 'inserted' => count($inserted)]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $this->wrap($e);
        }
        $out = $this->getGroup($move ? $toId : $fromId);
        $out['affected'] = count($existing);
        $out['inserted'] = count($inserted);
        $out['undo_token'] = $token;
        return $out;
    }

    /** @param array<int,int> $ids @return array<string,mixed> */
    private function insertMembers(int $groupId, array $ids, bool $withUndo): array
    {
        $this->pdo->beginTransaction();
        try {
            $inserted = $this->insertMemberRows($groupId, $ids);
            $token = '';
            if ($withUndo) {
                $token = $this->remember('members_add', ['group_id' => $groupId, 'customer_ids' => $inserted], $groupId, count($inserted));
            }
            $this->audit('members_add', $groupId, count($inserted), ['customer_ids' => $ids, 'inserted' => $inserted]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $this->wrap($e);
        }
        $out = $this->getGroup($groupId);
        $out['added'] = count($inserted);
        $out['skipped'] = count($ids) - count($inserted);
        if ($withUndo && $token !== '') {
            $out['undo_token'] = $token;
        }
        return $out;
    }

    /** @param array<int,int> $ids @return array<int,int> */
    private function insertMemberRows(int $groupId, array $ids): array
    {
        $existing = $this->existingMembers($groupId, $ids);
        $inserted = [];
        $stmt = $this->pdo->prepare('INSERT INTO pm_group_members (group_id, customer_id, added_by, created_at) VALUES (?, ?, ?, NOW())');
        foreach ($ids as $customerId) {
            if (in_array($customerId, $existing, true)) {
                continue;
            }
            $stmt->execute([$groupId, $customerId, $this->access->userId()]);
            $inserted[] = $customerId;
        }
        $touch = $this->pdo->prepare('UPDATE pm_groups SET updated_at = NOW() WHERE id = ?');
        $touch->execute([$groupId]);
        return $inserted;
    }

    /** @param array<int,int> $ids */
    private function deleteMembers(int $groupId, array $ids): void
    {
        if (!$ids) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("DELETE FROM pm_group_members WHERE group_id = ? AND customer_id IN ({$placeholders})");
        $stmt->execute(array_merge([$groupId], $ids));
        $touch = $this->pdo->prepare('UPDATE pm_groups SET updated_at = NOW() WHERE id = ?');
        $touch->execute([$groupId]);
    }

    /** @param array<int,int> $ids @return array<int,int> */
    private function existingMembers(int $groupId, array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT customer_id FROM pm_group_members WHERE group_id = ? AND customer_id IN ({$placeholders})");
        $stmt->execute(array_merge([$groupId], $ids));
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @param array<string,mixed> $payload */
    private function applyUndo(string $action, array $payload): void
    {
        if ($action === 'group_create') {
            $id = (int) ($payload['group_id'] ?? 0);
            $this->pdo->prepare('UPDATE pm_groups SET deleted_at = NOW(), deleted_by = ?, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL AND created_by = ?')
                ->execute([$this->access->userId(), $id, $this->access->userId()]);
            return;
        }
        if ($action === 'group_rename') {
            $id = (int) ($payload['group_id'] ?? 0);
            $name = $this->cleanName((string) ($payload['name'] ?? ''));
            $this->assertNameFree($name, $id);
            $this->pdo->prepare('UPDATE pm_groups SET name = ?, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL')
                ->execute([$name, $id]);
            return;
        }
        if ($action === 'group_delete') {
            $id = (int) ($payload['group_id'] ?? 0);
            $this->pdo->prepare('UPDATE pm_groups SET deleted_at = NULL, deleted_by = NULL, updated_at = NOW() WHERE id = ? AND deleted_by = ?')
                ->execute([$id, $this->access->userId()]);
            return;
        }
        if ($action === 'members_add') {
            $this->deleteMembers((int) ($payload['group_id'] ?? 0), $this->intList($payload['customer_ids'] ?? []));
            return;
        }
        if ($action === 'members_remove') {
            $ids = $this->requireVisibleCustomers($payload['customer_ids'] ?? []);
            $this->insertMemberRows((int) ($payload['group_id'] ?? 0), $ids);
            return;
        }
        if ($action === 'members_move') {
            $ids = $this->requireVisibleCustomers($payload['customer_ids'] ?? []);
            $inserted = $this->intList($payload['inserted_ids'] ?? []);
            $from = (int) ($payload['from_id'] ?? 0);
            $to = (int) ($payload['to_id'] ?? 0);
            $this->deleteMembers($to, $inserted);
            $this->insertMemberRows($from, $ids);
            return;
        }
        if ($action === 'members_copy') {
            $ids = $this->intList($payload['inserted_ids'] ?? []);
            $this->deleteMembers((int) ($payload['to_id'] ?? 0), $ids);
            return;
        }
        throw new PmHttpException('这种操作不能撤销', 400, 'VALIDATION');
    }

    /** @param array<string,mixed> $payload */
    private function remember(string $action, array $payload, ?int $groupId, int $count): string
    {
        $token = bin2hex(random_bytes(16));
        $stmt = $this->pdo->prepare('INSERT INTO pm_undo (token, actor_id, action, payload_json, expires_at, created_at) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 SECOND), NOW())');
        $stmt->execute([$token, $this->access->userId(), $action, json_encode($payload, JSON_UNESCAPED_UNICODE), ]);
        return $token;
    }

    /** @param array<string,mixed>|null $detail */
    private function audit(string $action, ?int $groupId, int $count, ?array $detail): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO pm_audit_logs (action, group_id, actor_id, customer_count, detail_json, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
        $stmt->execute([
            $action,
            $groupId,
            $this->access->userId(),
            $count,
            $detail === null ? null : json_encode($detail, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** @param array<int,mixed> $ids @return array<int,int> */
    private function requireVisibleCustomers(array $ids): array
    {
        $clean = $this->intList($ids);
        if (!$clean) {
            throw new PmHttpException('请选择客户', 400, 'VALIDATION');
        }
        if (count($clean) > 200) {
            throw new PmHttpException('一次最多处理 200 个客户', 400, 'VALIDATION');
        }
        $visible = $this->customers->filterVisibleIds($clean);
        if (count($visible) !== count($clean)) {
            throw new PmHttpException('有客户不在你的可见范围内，未写入分组', 403, 'FORBIDDEN');
        }
        return $clean;
    }

    /** @param array<int,mixed> $ids @return array<int,int> */
    private function intList(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0 && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function requireVisible(int $id): array
    {
        $group = $this->find($id);
        if (!$group || !$this->canSee($group)) {
            throw new PmHttpException('分组不存在', 404, 'NOT_FOUND');
        }
        return $group;
    }

    /** @return array<string,mixed> */
    private function requireEditable(int $id): array
    {
        $group = $this->requireVisible($id);
        if (!$this->canEdit($group)) {
            throw new PmHttpException('这个分组只有创建人可以修改', 403, 'FORBIDDEN');
        }
        return $group;
    }

    /** @return array<string,mixed>|null */
    private function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT id, name, color, visibility, created_by, deleted_at, created_at, updated_at FROM pm_groups WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @param array<string,mixed> $group */
    private function canSee(array $group): bool
    {
        if (!empty($group['deleted_at'])) {
            return false;
        }
        $scope = $this->access->scope('promotion');
        if (($scope['scope_type'] ?? '') === 'none') {
            return false;
        }
        if ((string) $group['visibility'] === 'private' && (int) $group['created_by'] !== $this->access->userId()) {
            return $this->access->isSuperAdmin() || ($scope['scope_type'] ?? '') === 'all';
        }
        return true;
    }

    /** @param array<string,mixed> $group */
    private function canEdit(array $group): bool
    {
        if ((string) $group['visibility'] !== 'private') {
            return true;
        }
        if ((int) $group['created_by'] === $this->access->userId()) {
            return true;
        }
        $scope = $this->access->scope('promotion');
        return $this->access->isSuperAdmin() || ($scope['scope_type'] ?? '') === 'all';
    }

    /** @param array<string,mixed> $group */
    private function canDelete(array $group): bool
    {
        if ((int) $group['created_by'] === $this->access->userId() || $this->access->isSuperAdmin()) {
            return true;
        }
        $scope = $this->access->scope('promotion');
        return ($scope['scope_type'] ?? '') === 'all';
    }

    /** @param array<string,mixed> $group @return array<string,mixed> */
    private function present(array $group, bool $withMembers): array
    {
        $id = (int) $group['id'];
        $creator = $this->pdo->prepare('SELECT COALESCE(NULLIF(real_name, \'\'), username) FROM crm_users WHERE id = ?');
        $creator->execute([(int) $group['created_by']]);
        $name = $creator->fetchColumn();
        $params = [];
        $scope = $this->customers->scopeSql($params);
        $visibleStmt = $this->pdo->prepare("SELECT COUNT(*) FROM pm_group_members m JOIN crm_customers c ON c.id = m.customer_id WHERE m.group_id = ? AND c.deleted_at IS NULL AND ({$scope})");
        $visibleStmt->execute(array_merge([$id], $params));
        $totalStmt = $this->pdo->prepare('SELECT COUNT(*) FROM pm_group_members WHERE group_id = ?');
        $totalStmt->execute([$id]);
        $out = [
            'id' => $id,
            'name' => (string) $group['name'],
            'color' => (string) $group['color'],
            'visibility' => (string) $group['visibility'],
            'visibility_label' => (string) $group['visibility'] === 'private' ? '仅自己可见' : '团队共用',
            'created_by' => (int) $group['created_by'],
            'created_by_name' => $name ? (string) $name : '',
            'visible_count' => (int) $visibleStmt->fetchColumn(),
            'total_count' => (int) $totalStmt->fetchColumn(),
            'mine' => (int) $group['created_by'] === $this->access->userId(),
            'can_edit' => $this->canEdit($group) && $this->access->hasPermission('promotion.edit_group'),
            'can_delete' => $this->canDelete($group) && $this->access->hasPermission('promotion.delete_group'),
            'can_move' => $this->canEdit($group) && $this->access->hasPermission('promotion.move_customer'),
            'created_at' => (string) ($group['created_at'] ?? ''),
        ];
        if (!$withMembers) {
            return $out;
        }
        return $out;
    }

    /** @param array<int,int> $customerIds @return array<int,array<int,array<string,mixed>>> */
    private function otherGroups(array $customerIds, int $exceptGroupId): array
    {
        if (!$customerIds) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
        $stmt = $this->pdo->prepare("SELECT m.customer_id, g.id, g.name, g.color, g.visibility, g.created_by
            FROM pm_group_members m
            JOIN pm_groups g ON g.id = m.group_id AND g.deleted_at IS NULL
            WHERE m.customer_id IN ({$placeholders}) AND g.id <> ?");
        $stmt->execute(array_merge($customerIds, [$exceptGroupId]));
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            if (!$this->canSee($row)) {
                continue;
            }
            $out[(int) $row['customer_id']][] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'color' => (string) $row['color'],
            ];
        }
        return $out;
    }

    private function requirePermission(string $permission): void
    {
        if (!$this->access->hasPermission($permission)) {
            throw new PmHttpException('没有权限：' . $permission, 403, 'FORBIDDEN');
        }
    }

    private function cleanName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        $length = mb_strlen($name);
        if ($length < 1 || $length > 80) {
            throw new PmHttpException('分组名称需要 1 到 80 个字', 400, 'VALIDATION');
        }
        return $name;
    }

    private function cleanVisibility(string $visibility): string
    {
        return $visibility === 'private' ? 'private' : 'team';
    }

    private function cleanColor(string $color): string
    {
        $color = trim($color);
        if ($color === '') {
            $palette = ['#3b4fd8', '#19794b', '#a35c00', '#b3261e', '#6b4ea3', '#0f6e8c'];
            return $palette[random_int(0, count($palette) - 1)];
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            throw new PmHttpException('颜色格式应为 #RRGGBB', 400, 'VALIDATION');
        }
        return strtolower($color);
    }

    private function assertNameFree(string $name, int $exceptId = 0): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM pm_groups WHERE deleted_at IS NULL AND name = ? AND id <> ? LIMIT 1');
        $stmt->execute([$name, $exceptId]);
        if ($stmt->fetchColumn()) {
            throw new PmHttpException('已经有同名分组', 409, 'CONFLICT');
        }
    }

    private function wrap(\Throwable $e): \Throwable
    {
        if ($e instanceof PmHttpException) {
            return $e;
        }
        $message = $e->getMessage();
        if (strpos($message, 'uq_pm_groups_active_name') !== false || strpos($message, '1062') !== false && strpos($message, 'pm_groups') !== false) {
            return new PmHttpException('已经有同名分组', 409, 'CONFLICT');
        }
        error_log('promotion_center_v1 group: ' . $message);
        return new PmHttpException('分组保存失败', 500, 'SERVER');
    }
}
