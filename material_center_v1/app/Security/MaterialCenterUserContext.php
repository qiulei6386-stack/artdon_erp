<?php
declare(strict_types=1);
namespace Artdon\MaterialCenter\Security;

final class MaterialCenterUserContext
{
    public function __construct(
        public readonly int $id,
        public readonly string $username,
        public readonly string $displayName,
        public readonly string $roleKey,
        public readonly bool $isSuperAdmin
    ) {}
}
