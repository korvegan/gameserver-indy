<?php
declare(strict_types=1);

namespace App\Access;

use Symfony\Component\Security\Core\User\UserInterface;

/** A server-side identity; browser role headers and CSRF tokens never create one. */
final readonly class Principal implements UserInterface
{
    private const READ = ['catalog.read', 'status.read', 'jobs.read', 'community.read'];
    private const OWNER = ['config.read', 'config.write', 'logs.read', 'server.start', 'server.stop',
        'server.restart', 'server.setup', 'catalog.write', 'discord.write', 'updates.check',
        'connections.refresh', 'community.write', 'community.export', 'access.manage', 'backups.manage'];
    public function __construct(public string $id, public string $role, public ?array $serverIds = null,
        public int $revision = 0) {}
    public function getUserIdentifier(): string { return $this->id; }
    public function getRoles(): array { return ['ROLE_'.strtoupper($this->role)]; }
    public function eraseCredentials(): void {}
    public function permissions(): array
    {
        return match ($this->role) {
            'owner' => [...self::READ, ...self::OWNER], 'family' => [...self::READ, 'server.start'],
            'viewer' => self::READ, default => [],
        };
    }
    public function allows(string $permission, ?string $serverId = null): bool
    {
        return $this->id !== '' && in_array($permission, $this->permissions(), true)
            && ($serverId === null || $this->serverIds === null || in_array($serverId, $this->serverIds, true));
    }
    public function localOwner(): bool
    {
        return $this->id === 'local-owner' && $this->role === 'owner' && $this->revision === 0 && $this->serverIds === null;
    }
    public function public(): array
    {
        $permissions = $this->permissions(); sort($permissions);
        return ['id' => $this->id, 'role' => $this->role, 'permissions' => $permissions];
    }
    public function claim(): string
    {
        return json_encode(['id' => $this->id, 'role' => $this->role, 'server_ids' => $this->serverIds,
            'revision' => $this->revision], JSON_THROW_ON_ERROR);
    }
    public static function fromClaim(string $claim): self
    {
        $value = json_decode($claim, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($value) || !is_string($value['id'] ?? null) || !is_string($value['role'] ?? null)
            || !is_int($value['revision'] ?? null) || $value['revision'] < 0
            || (!is_null($value['server_ids'] ?? null) && (!is_array($value['server_ids'])
                || array_filter($value['server_ids'], fn ($id) => !is_string($id))))) {
            throw new \DomainException('Gespeicherte Auftragsidentität ungültig.');
        }
        return new self($value['id'], $value['role'], $value['server_ids'] ?? null, $value['revision']);
    }
}
