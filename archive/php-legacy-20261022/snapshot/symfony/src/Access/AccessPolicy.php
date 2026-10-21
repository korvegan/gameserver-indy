<?php
declare(strict_types=1);
namespace App\Access;

use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class AccessPolicy
{
    public function __construct(private readonly AccessStore $store, private readonly \App\Community\MemberProfiles $members) {}
    public function require(Principal $principal, string $permission, ?string $serverId = null): void
    {
        $this->store->validate($principal);
        $communityWriter=$permission==='community.write' && $this->members->contributes($principal)
            && ($serverId===null || $principal->allows('status.read',$serverId));
        if (!$principal->allows($permission, $serverId) && !$communityWriter) { throw new AccessDeniedException('Für diese Aktion oder dieses Serverprofil fehlt die Berechtigung.'); }
    }
    public function permissions(Principal $principal): array
    {
        $permissions=$principal->permissions();
        if ($this->members->contributes($principal)) { $permissions[]='community.write'; }
        $permissions=array_values(array_unique($permissions)); sort($permissions); return $permissions;
    }
    public function visible(Principal $principal, array $job): bool { return $principal->allows('jobs.read', $job['server_id']); }
}
