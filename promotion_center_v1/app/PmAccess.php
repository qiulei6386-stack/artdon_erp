<?php
declare(strict_types=1);

namespace Artdon\PromotionCenter;

/**
 * 登录用户与统一权限。生产实现必须调用 has_permission / get_user_scope。
 */
interface PmAccess
{
    public function userId(): int;

    public function displayName(): string;

    public function roleName(): string;

    public function departmentName(): string;

    public function isSuperAdmin(): bool;

    public function hasPermission(string $permission): bool;

    /** @return array{scope_type:string,scope_value:string} */
    public function scope(string $module): array;
}
