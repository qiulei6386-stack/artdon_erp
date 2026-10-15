<?php
declare(strict_types=1);

namespace Artdon\Centers\Base;

use PDO;

/** 人员目录：给“选择负责人”等下拉框用，只列正常状态的账号。 */
final class UserDirectory
{
    /** @var list<array<string, mixed>>|null */
    private static $active;

    /**
     * @return list<array{id: int, name: string, username: string, department_id: int, department_name: string}>
     */
    public static function active(): array
    {
        if (self::$active === null) {
            $rows = Db::pdo()->query("SELECT u.id, u.username, u.real_name, u.english_name, u.department_id, d.name AS department_name
                FROM crm_users u LEFT JOIN crm_departments d ON d.id = u.department_id
                WHERE u.status = 'active'
                ORDER BY d.sort_order, d.id, u.real_name, u.username")->fetchAll(PDO::FETCH_ASSOC);
            self::$active = [];
            foreach ($rows as $row) {
                self::$active[] = [
                    'id' => (int) $row['id'],
                    'name' => Auth::displayName($row),
                    'username' => (string) $row['username'],
                    'department_id' => (int) ($row['department_id'] ?? 0),
                    'department_name' => (string) ($row['department_name'] ?? ''),
                ];
            }
        }
        return self::$active;
    }

    /** 按部门分组的下拉选项：部门名 => [id => 姓名]。 @return array<string, array<int, string>> */
    public static function grouped(): array
    {
        $out = [];
        foreach (self::active() as $u) {
            $out[$u['department_name'] !== '' ? $u['department_name'] : '未分配部门'][$u['id']] = $u['name'];
        }
        return $out;
    }

    public static function isActive(int $userId): bool
    {
        foreach (self::active() as $u) {
            if ($u['id'] === $userId) {
                return true;
            }
        }
        return false;
    }

    public static function clearCache(): void
    {
        self::$active = null;
    }
}
