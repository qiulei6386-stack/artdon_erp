<?php
declare(strict_types=1);

namespace Artdon\Centers\Customer;

use Artdon\Centers\Base\Db;
use Artdon\Centers\Base\NotFoundException;
use Artdon\Centers\Base\Permission;
use Artdon\Centers\Base\PermissionException;
use Artdon\Centers\Base\ValidationException;
use PDO;

/**
 * 客户联系人（crm_contacts）。规则沿用旧系统：
 *   姓名、邮箱、电话、WhatsApp、微信、LinkedIn 至少填一项；没填姓名时用邮箱前缀等代替；
 *   同一客户内邮箱、电话不能重复；每个客户只有一个主联系人。删除为软删除。
 * 查看要 contact.view，新增 contact.create，编辑 contact.edit，删除 contact.delete；客户必须在自己的范围内（公海客户先领取）。
 */
final class ContactService
{
    public const FIELDS = [
        'name' => ['姓名', 120],
        'name_en' => ['英文名', 120],
        'position' => ['职位', 120],
        'email' => ['邮箱', 190],
        'phone' => ['电话', 80],
        'whatsapp' => ['WhatsApp', 80],
        'wechat' => ['微信', 120],
        'linkedin' => ['LinkedIn', 255],
        'remark' => ['备注', 2000],
    ];

    /** @var PDO */
    private $pdo;

    /** @var array<string, mixed> */
    private $operator;

    public function __construct(array $operator, ?PDO $pdo = null)
    {
        $this->operator = $operator;
        $this->pdo = $pdo ?? Db::pdo();
    }

    public function can(string $permission): bool
    {
        return Permission::allows($this->operator, $permission);
    }

    /** @return list<array<string, mixed>> */
    public function list(int $customerId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, customer_id, name, name_en, position, department, email, phone, whatsapp, wechat, linkedin, is_primary, is_left, remark, updated_at
            FROM crm_contacts WHERE customer_id = ? AND deleted_at IS NULL ORDER BY is_primary DESC, is_left ASC, id ASC');
        $stmt->execute([$customerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(int $customerId, array $input): int
    {
        $this->requireCustomer($customerId, 'contact.create', '新增联系人');
        $contact = self::check(self::pickInput($input));
        $contact['is_primary'] = !empty($input['is_primary']) && (string) $input['is_primary'] !== '0' ? 1 : 0;
        $contact['is_left'] = !empty($input['is_left']) && (string) $input['is_left'] !== '0' ? 1 : 0;
        $this->assertUnique($customerId, $contact, 0);
        $this->pdo->beginTransaction();
        try {
            $id = $this->insert($customerId, $contact, true);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $id;
    }

    /** 写入一条联系人（调用方负责事务与权限）。 */
    public function insert(int $customerId, array $contact, bool $log): int
    {
        $hasContacts = $this->list($customerId) !== [];
        $isPrimary = !empty($contact['is_primary']) || !$hasContacts ? 1 : 0;
        if ($isPrimary) {
            $this->pdo->prepare('UPDATE crm_contacts SET is_primary = 0 WHERE customer_id = ? AND deleted_at IS NULL')->execute([$customerId]);
        }
        $now = Db::now();
        $me = (int) $this->operator['id'];
        $this->pdo->prepare('INSERT INTO crm_contacts (customer_id, name, name_en, position, email, phone, whatsapp, wechat, linkedin, is_primary, is_left, remark, created_by, updated_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $customerId, $contact['name'], $contact['name_en'] ?? '', $contact['position'] ?? '', $contact['email'] ?? '', $contact['phone'] ?? '', $contact['whatsapp'] ?? '',
                $contact['wechat'] ?? '', $contact['linkedin'] ?? '', $isPrimary, (int) ($contact['is_left'] ?? 0), $contact['remark'] ?? '', $me, $me, $now, $now,
            ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->touch($customerId);
        if ($log) {
            CustomerLog::write('contact_create', $customerId, '新增联系人', (string) $contact['name'], null, $contact, $this->operator, 'contact', (string) $id);
        }
        return $id;
    }

    public function update(int $contactId, array $input): void
    {
        $before = $this->find($contactId);
        $customerId = (int) $before['customer_id'];
        $this->requireCustomer($customerId, 'contact.edit', '编辑联系人');
        $contact = self::check(self::pickInput($input));
        $contact['is_primary'] = !empty($input['is_primary']) && (string) $input['is_primary'] !== '0' ? 1 : 0;
        $contact['is_left'] = !empty($input['is_left']) && (string) $input['is_left'] !== '0' ? 1 : 0;
        if ((int) $before['is_primary'] === 1 && !$contact['is_primary']) {
            $contact['is_primary'] = 1;
        }
        $this->assertUnique($customerId, $contact, $contactId);
        $this->pdo->beginTransaction();
        try {
            if ($contact['is_primary']) {
                $this->pdo->prepare('UPDATE crm_contacts SET is_primary = 0 WHERE customer_id = ? AND id <> ? AND deleted_at IS NULL')->execute([$customerId, $contactId]);
            }
            $this->pdo->prepare('UPDATE crm_contacts SET name = ?, name_en = ?, position = ?, email = ?, phone = ?, whatsapp = ?, wechat = ?, linkedin = ?, is_primary = ?, is_left = ?, remark = ?, updated_by = ?, updated_at = ? WHERE id = ?')
                ->execute([$contact['name'], $contact['name_en'], $contact['position'], $contact['email'], $contact['phone'], $contact['whatsapp'], $contact['wechat'], $contact['linkedin'],
                    $contact['is_primary'], $contact['is_left'], $contact['remark'], (int) $this->operator['id'], Db::now(), $contactId]);
            $this->touch($customerId);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $old = [];
        $new = [];
        foreach ($contact as $k => $v) {
            if ((string) ($before[$k] ?? '') !== (string) $v) {
                $old[$k] = $before[$k] ?? '';
                $new[$k] = $v;
            }
        }
        if ($new) {
            CustomerLog::write('contact_update', $customerId, '编辑联系人', $contact['name'], $old, $new, $this->operator, 'contact', (string) $contactId);
        }
    }

    public function delete(int $contactId): void
    {
        $before = $this->find($contactId);
        $customerId = (int) $before['customer_id'];
        $this->requireCustomer($customerId, 'contact.delete', '删除联系人');
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE crm_contacts SET deleted_at = ?, deleted_by = ?, is_primary = 0, updated_at = ? WHERE id = ?')->execute([Db::now(), (int) $this->operator['id'], Db::now(), $contactId]);
            if ((int) $before['is_primary'] === 1) {
                $stmt = $this->pdo->prepare('SELECT id FROM crm_contacts WHERE customer_id = ? AND deleted_at IS NULL ORDER BY is_left ASC, id ASC LIMIT 1');
                $stmt->execute([$customerId]);
                $next = (int) $stmt->fetchColumn();
                if ($next > 0) {
                    $this->pdo->prepare('UPDATE crm_contacts SET is_primary = 1 WHERE id = ?')->execute([$next]);
                }
            }
            $this->touch($customerId);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        CustomerLog::write('contact_delete', $customerId, '删除联系人', (string) $before['name'], ['name' => $before['name'], 'email' => $before['email'], 'phone' => $before['phone']], ['deleted' => true], $this->operator, 'contact', (string) $contactId);
    }

    /**
     * 校验并整理一个联系人。$prefix 用于错误字段名（新建客户时联系人字段带 contact_ 前缀）。
     *
     * @return array<string, string>
     */
    public static function check(array $c, string $prefix = ''): array
    {
        $errors = [];
        $out = [];
        foreach (self::FIELDS as $key => [$label, $max]) {
            $value = isset($c[$key]) && is_scalar($c[$key]) ? trim((string) $c[$key]) : '';
            if (mb_strlen($value, 'UTF-8') > $max) {
                $errors[$prefix . $key] = $label . '不能超过 ' . $max . ' 个字符';
            }
            $out[$key] = $value;
        }
        if ($out['email'] !== '' && !filter_var($out['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[$prefix . 'email'] = '邮箱格式不正确';
        }
        $useful = false;
        foreach (['name', 'email', 'phone', 'whatsapp', 'wechat', 'linkedin'] as $key) {
            if ($out[$key] !== '') {
                $useful = true;
            }
        }
        if (!$useful) {
            $errors[$prefix . 'name'] = '姓名、邮箱、电话、WhatsApp、微信、LinkedIn 至少填一项';
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        if ($out['name'] === '') {
            $out['name'] = $out['email'] !== '' ? (string) strstr($out['email'], '@', true) : ($out['phone'] ?: ($out['whatsapp'] ?: ($out['wechat'] ?: $out['linkedin'])));
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private static function pickInput(array $input): array
    {
        $out = [];
        foreach (array_keys(self::FIELDS) as $key) {
            $out[$key] = $input[$key] ?? '';
        }
        return $out;
    }

    private function assertUnique(int $customerId, array $contact, int $ignoreId): void
    {
        $errors = [];
        foreach (['email' => '这个客户已经有相同邮箱的联系人', 'phone' => '这个客户已经有相同电话的联系人'] as $field => $message) {
            if ($contact[$field] === '') {
                continue;
            }
            $stmt = $this->pdo->prepare("SELECT id FROM crm_contacts WHERE customer_id = ? AND {$field} = ? AND deleted_at IS NULL AND id <> ? LIMIT 1");
            $stmt->execute([$customerId, $contact[$field], $ignoreId]);
            if ($stmt->fetchColumn()) {
                $errors[$field] = $message;
            }
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
    }

    /** @return array<string, mixed> */
    private function find(int $contactId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM crm_contacts WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$contactId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new NotFoundException('联系人不存在');
        }
        return $row;
    }

    private function requireCustomer(int $customerId, string $permission, string $label): void
    {
        if (!$this->can($permission)) {
            throw new PermissionException('没有' . $label . '的权限（' . $permission . '）');
        }
        $params = [];
        $sql = CustomerScope::sql($this->operator, $params);
        array_unshift($params, $customerId);
        $stmt = $this->pdo->prepare('SELECT 1 FROM crm_customers c WHERE c.id = ? AND c.deleted_at IS NULL AND NOT ' . CustomerScope::publicSql() . ' AND ' . $sql);
        $stmt->execute($params);
        if (!$stmt->fetchColumn()) {
            throw new NotFoundException('客户不存在或不在你的范围内（公海客户请先领取）');
        }
    }

    private function touch(int $customerId): void
    {
        $this->pdo->prepare('UPDATE crm_customers SET updated_at = ?, updated_by = ? WHERE id = ?')->execute([Db::now(), (int) $this->operator['id'], $customerId]);
    }
}
