<?php
declare(strict_types=1);
namespace App\Access;

use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class AccessPolicy
{
    public function __construct(private readonly AccessStore $store) {}
    public function require(Principal $principal, string $permission, ?string $serverId = null): void
    {
        $this->store->validate($principal);
        if (!$principal->allows($permission, $serverId)) { throw new AccessDeniedException('Für diese Aktion oder dieses Serverprofil fehlt die Berechtigung.'); }
    }
    public function visible(Principal $principal, array $job): bool { return $principal->allows('jobs.read', $job['server_id']); }
}
