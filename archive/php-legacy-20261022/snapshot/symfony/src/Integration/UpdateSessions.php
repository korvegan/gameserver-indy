<?php
declare(strict_types=1);
namespace App\Integration;
use App\Runtime\SliceRuntime;
use App\Storage\PrivateFiles;

/** Pending manual Steam step survives manager restarts; never infer a completed update. */
final class UpdateSessions
{
    public function __construct(private readonly SliceRuntime $runtime) {}
    public function path(string $id): string
    {
        if (!\App\Game\GameCatalog::identifier($id)) throw new \InvalidArgumentException('Ungültiges Profil.');
        $this->runtime->assertInitialized(); return $this->runtime->directory.'/data/update-sessions/'.$id;
    }
    public function read(string $id): ?\stdClass
    { return is_file($this->path($id).'/session.json') ? PrivateFiles::object(PrivateFiles::read($this->path($id).'/session.json')) : null; }
    public function store(string $id,\stdClass $session): void
    { PrivateFiles::directory($this->path($id)); PrivateFiles::write($this->path($id).'/session.json',PrivateFiles::json($session)); }
    public function guard(string $id,string $operation): void
    {
        if (in_array($operation,['stop','update_finish','update_abort'],true) || IntegrationOperations::is($operation)) return;
        if (($this->read($id)->state ?? null)==='waiting_steam') { throw new \DomainException('Update-Assistent wartet auf Steam-Abschluss. Unter Updates prüfen oder bewusst beenden; kein Start und keine Dateiänderung ausgeführt.'); }
    }
}
