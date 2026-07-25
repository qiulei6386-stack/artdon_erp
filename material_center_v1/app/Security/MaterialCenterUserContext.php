<?php
declare(strict_types=1);
namespace Artdon\MaterialCenter\Security;

final class MaterialCenterUserContext
{
    public int $id;
    public string $username;
    public string $displayName;
    public string $roleKey;
    public bool $isSuperAdmin;

    public function __construct(
        int $id,
        string $username,
        string $displayName,
        string $roleKey,
        bool $isSuperAdmin
    ) {
        $this->id = $id;
        $this->username = $username;
        $this->displayName = $displayName;
        $this->roleKey = $roleKey;
        $this->isSuperAdmin = $isSuperAdmin;
    }
}
