<?php
declare(strict_types=1);
namespace App\Backup;

use App\Access\AccessPolicy;
use App\Access\Principal;
use App\Instance\InstanceMutation;
use App\Instance\InstanceRegistry;
use App\Job\JobRepository;
use App\Job\WorkerClaim;
use App\Runtime\OutcomeUnknown;
use App\Runtime\ProcessGateway;
use App\Runtime\ProcessObservation;
use App\Runtime\SliceRuntime;
use App\Storage\PrivateFiles;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** Private verified offline snapshots. No game shutdown or arbitrary archive upload. */
final class WorldBackups
{
    public function __construct(private readonly SliceRuntime $runtime, private readonly InstanceRegistry $registry,
        private readonly AccessPolicy $policy, private readonly InstanceMutation $mutation, private readonly WorkerClaim $claim,
        private readonly ProcessGateway $gateway, private readonly ProcessObservation $observations, private readonly JobRepository $jobs,
        private readonly BackupPlan $plans, private readonly BackupFiles $files, private readonly BackupArchive $archives,
        private readonly ClockInterface $clock) {}
    private function folder(string $id): string
    {
        $this->registry->get($id); $path = $this->runtime->directory.'/data/world-backups/'.$id;
        $this->files->native([$path]); return $this->files->plain($path);
    }
    private function path(string $id, string $name): string { return $this->files->plain($this->folder($id).'/'.$name); }
    private function pending(string $id): bool
    {
        $path = $this->folder($id).'/restore-pending.json';
        return file_exists($path) || is_link($path);
    }
    private function guardPending(string $id): void
    {
        if ($this->pending($id)) { throw new \DomainException('Wiederherstellung unterbrochen. Zuerst den ausdrücklich bestätigten Rückweg ausführen.'); }
    }
    private function identifier(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) { throw new \InvalidArgumentException('Ungültige Sicherungs-ID.'); }
    }
    private function now(): string { return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP'); }
    private function metadata(string $server, string $id): \stdClass
    {
        $this->identifier($id); $path = $this->path($server, $id.'.json'); $this->files->native([$path]);
        $record = PrivateFiles::object(PrivateFiles::read($path, BackupFiles::MANIFEST_LIMIT * 2));
        if (($record->id ?? null) !== $id || !is_string($record->created_at ?? null)
            || !property_exists($record, 'verified_at') || !is_bool($record->pinned ?? null)
            || !is_string($record->reason ?? null) || !is_int($record->bytes ?? null) || $record->bytes < 0
            || !is_int($record->count ?? null) || $record->count < 0 || !property_exists($record, 'build')
            || !($record->manifest ?? null) instanceof \stdClass || ($record->manifest->id ?? null) !== $id || !is_string($record->sha256 ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $record->sha256)) { throw new \DomainException('Sicherungsmetadaten beschädigt.'); }
        return $record;
    }
    private function store(string $server, \stdClass $record): void
    {
        PrivateFiles::write($this->path($server, $record->id.'.json'), PrivateFiles::json($record));
    }
    private function phase(string $id, string $phase, int $completed = 0, int $total = 0): void
    {
        $folder = $this->folder($id); PrivateFiles::directory($folder);
        PrivateFiles::write($folder.'/progress.json', PrivateFiles::json((object) ['phase' => $phase, 'completed' => $completed,
            'total' => $total, 'job_id' => $this->claim->forServer($id)['id'] ?? null]));
    }
    public function view(Principal $actor, string $id): array
    {
        $this->policy->require($actor, 'backups.manage', $id); $folder = $this->folder($id); $records = [];
        foreach (glob($folder.'/*.json') ?: [] as $path) {
            $identifier = pathinfo($path, PATHINFO_FILENAME); if (!preg_match('/^[a-f0-9]{32}$/D', $identifier)) { continue; }
            try { $record = $this->metadata($id, $identifier); $records[] = array_intersect_key(get_object_vars($record),
                array_flip(['id', 'created_at', 'verified_at', 'pinned', 'reason', 'bytes', 'count', 'build'])); }
            catch (\Throwable) { $records[] = ['id' => $identifier, 'error' => 'Sicherungsmetadaten nicht lesbar.']; }
        }
        usort($records, fn ($one, $two) => strcmp($two['created_at'] ?? '', $one['created_at'] ?? ''));
        $target = null; $error = null;
        try { $target = $this->plans->resolve($id, $this->observations->view($id))['binding']; }
        catch (\Throwable) { $error = 'Weltziel nicht eindeutig lesbar. Konfiguration und native Beobachtung prüfen.'; }
        $automatic = is_file($folder.'/settings.json') && (PrivateFiles::object(PrivateFiles::read($this->path($id, 'settings.json')))->automatic_after_stop ?? null) === true;
        $progress = is_file($folder.'/progress.json') ? get_object_vars(PrivateFiles::object(PrivateFiles::read($this->path($id, 'progress.json')))) : null;
        if ($progress !== null && ($progress['job_id'] ?? null) !== null) {
            try { if ($this->jobs->get($progress['job_id'])['state'] !== 'running') { $progress = null; } }
            catch (\Throwable) { $progress = null; }
        }
        return ['server_id' => $id, 'backups' => $records, 'target' => $target, 'error' => $error, 'retention_hours' => 72,
            'automatic_after_stop' => $automatic, 'recovery_required' => $this->pending($id), 'progress' => $progress];
    }
    public function preview(Principal $actor, string $server, string $id, bool $recovery = false, ?array $status = null): array
    {
        $this->policy->require($actor, 'backups.manage', $server); $record = $this->metadata($server, $id);
        $plan = $this->plans->resolve($server, $status ?? $this->observations->view($server));
        if (!$this->plans->sameBinding($record->manifest->binding ?? null, $plan['binding'])) { throw new \DomainException('Sicherung gehört zu einem anderen Weltziel. Kein Weltwechsel durch Wiederherstellen.'); }
        if ($recovery && (!$this->pending($server) || (PrivateFiles::object(PrivateFiles::read($this->path($server, 'restore-pending.json')))->before ?? null) !== $id)) {
            throw new \DomainException('Diese Sicherung gehört nicht zum ausstehenden Rückweg.');
        }
        return ['backup_id' => $id, 'created_at' => $record->created_at, 'target' => $plan['binding'],
            'token' => $this->plans->token($plan), 'confirmation' => 'WIEDERHERSTELLEN'];
    }
    public function recoveryPreview(Principal $actor, string $id): array
    {
        $this->policy->require($actor, 'backups.manage', $id);
        if (!$this->pending($id)) { throw new \DomainException('Keine ausstehende Wiederherstellung.'); }
        $pending = PrivateFiles::object(PrivateFiles::read($this->path($id, 'restore-pending.json')));
        if (!is_string($pending->before ?? null)) { throw new \DomainException('Wiederherstellungsmarkierung beschädigt.'); }
        return $this->preview($actor, $id, $pending->before, true);
    }
    private function offline(string $id, callable $guard): array
    {
        $guard(); $status = $this->gateway->call($id, 'Status'); $guard();
        if (($status['known'] ?? null) !== true || ($status['processes'] ?? null) !== [] || ($status['running'] ?? null) !== false
            || ($status['starting'] ?? null) !== false || ($status['launcher_running'] ?? null) !== false) { throw new \DomainException('Server und Starter müssen eindeutig gestoppt sein.'); }
        return $status;
    }
    public function execute(Principal $actor, string $id, string $operation, ?\stdClass $payload = null): array
    {
        BackupOperations::validate($operation, $payload);
        return $this->mutation->reserved($actor, $id, 'backups.manage', function (callable $guard) use ($actor, $id, $operation, $payload): array {
            try {
                return match ($operation) {
                    'backup' => $this->create($id, $guard),
                    'backup_verify' => $this->verify($id, $payload->backup_id, $guard),
                    'backup_pin' => $this->pin($id, $payload->backup_id, $payload->pinned, $guard),
                    'backup_settings' => $this->configure($id, $payload->automatic_after_stop, $guard),
                    'backup_restore', 'backup_recover' => $this->restore($actor, $id, $payload, $guard, $operation === 'backup_recover'),
                };
            } finally { $this->phase($id, 'idle'); }
        });
    }
    public function afterStop(string $id): void
    {
        $settings = $this->path($id, 'settings.json');
        if (!is_file($settings) || (PrivateFiles::object(PrivateFiles::read($settings))->automatic_after_stop ?? null) !== true) { return; }
        $claim = $this->claim->forServer($id);
        if ($claim === null || !in_array($claim['operation'], ['stop', 'restart'], true)) { throw new \DomainException('Sicherung nach Stopp benötigt den laufenden Serverauftrag.'); }
        $actor = Principal::fromClaim($claim['actor']);
        try {
            $this->mutation->reserved($actor, $id, 'backups.manage', function (callable $guard) use ($id): void {
                try { $this->create($id, $guard, 'after_stop'); } finally { $this->phase($id, 'idle'); }
            }, true);
        } catch (OutcomeUnknown|AccessDeniedException $error) { throw $error; }
        catch (\Throwable $error) { throw new \DomainException('Server gestoppt, Weltsicherung fehlgeschlagen. Kein anschließender Neustart ausgeführt.', previous: $error); }
    }
    private function create(string $id, callable $guard, string $reason = 'manual', bool $pinned = false, bool $prune = true): array
    {
        $this->guardPending($id); $status = $this->offline($id, $guard); $plan = $this->plans->resolve($id, $status);
        $this->phase($id, 'Warte auf ruhende Weltdateien'); $first = $this->files->inventory($plan['world'], $plan['files']);
        $this->clock->sleep(1); $this->offline($id, $guard); $inventory = $this->files->inventory($plan['world'], $plan['files']);
        if ($first['signature'] !== $inventory['signature'] || $first['directories'] !== $inventory['directories']) { throw new \DomainException('Weltdateien ändern sich noch. Später erneut sichern.'); }
        $folder = $this->folder($id); PrivateFiles::directory($folder);
        $this->files->space($folder, $inventory['size'] * 2 + BackupFiles::MANIFEST_LIMIT);
        $identifier = bin2hex(random_bytes(16)); $partial = $folder.'/'.$identifier.'.partial'; $archive = $folder.'/'.$identifier.'.zip';
        $manifest = (object) ['schema' => 1, 'id' => $identifier, 'created_at' => $this->now(), 'binding' => $plan['binding'],
            'directories' => $inventory['directories'], 'files' => [], 'build' => null];
        try {
            $this->phase($id, 'Sichere Dateien', 0, count($inventory['entries'])); $guard(); $this->archives->create($partial, $inventory, $manifest);
            $status = $this->offline($id, $guard); $after = $this->files->inventory($plan['world'], $plan['files']);
            if ($after['signature'] !== $inventory['signature'] || $after['directories'] !== $inventory['directories']
                || !$this->plans->sameBinding($this->plans->resolve($id, $status)['binding'], $plan['binding'])) { throw new \DomainException('Welt oder Konfiguration während der Sicherung geändert.'); }
            $this->phase($id, 'Prüfe Archiv und Prüfsummen'); $this->archives->inspect($partial, $manifest); $guard();
            $record = (object) ['id' => $identifier, 'created_at' => $manifest->created_at, 'verified_at' => $this->now(), 'pinned' => $pinned,
                'reason' => $reason, 'bytes' => filesize($partial), 'count' => count($inventory['entries']), 'build' => null,
                'manifest' => $manifest, 'sha256' => $this->files->digest($partial)];
            $guard(); if (!rename($partial, $archive)) { throw new \DomainException('Archiv nicht übernommen.'); } $this->store($id, $record);
        } finally { if (is_file($partial)) { unlink($partial); } }
        if ($prune) { $guard(); $this->prune($id, $identifier, $guard); }
        return ['message' => 'Welt und Konfiguration gesichert; alle Prüfsummen bestätigt.', 'backup_id' => $identifier];
    }
    private function verify(string $server, string $id, callable $guard): array
    {
        $record = $this->metadata($server, $id); $this->phase($server, 'Prüfe Archiv und Prüfsummen');
        try {
            $archive = $this->path($server, $id.'.zip'); $this->files->native([$archive]);
            if (!hash_equals($record->sha256, $this->files->digest($archive))) { throw new \DomainException('Archivprüfsumme stimmt nicht.'); }
            $this->archives->inspect($archive, $record->manifest);
        } catch (\Throwable $error) {
            $guard(); $record->verified_at = null; $this->store($server, $record);
            throw new \DomainException('Sicherung unvollständig oder Prüfsummen fehlerhaft. Keine Weltdateien geändert.', previous: $error);
        }
        $guard(); $record->verified_at = $this->now(); $this->store($server, $record);
        return ['message' => 'Alle Dateien und Prüfsummen der Weltsicherung bestätigt.'];
    }
    private function pin(string $server, string $id, bool $value, callable $guard): array
    {
        if (!$value && $this->pending($server) && (PrivateFiles::object(PrivateFiles::read($this->path($server, 'restore-pending.json')))->before ?? null) === $id) { throw new \DomainException('Rückwegsicherung bleibt bis zum Abschluss angeheftet.'); }
        $record = $this->metadata($server, $id); $record->pinned = $value; $guard(); $this->store($server, $record);
        return ['message' => $value ? 'Sicherung angeheftet.' : 'Sicherung unterliegt wieder der Aufbewahrung von 72 Stunden.'];
    }
    private function configure(string $id, bool $value, callable $guard): array
    {
        $folder = $this->folder($id); PrivateFiles::directory($folder); $guard();
        PrivateFiles::write($this->path($id, 'settings.json'), PrivateFiles::json((object) ['automatic_after_stop' => $value]));
        return ['message' => 'Sicherung nach regulärem Dashboard-Stopp '.($value ? 'aktiviert.' : 'deaktiviert.')];
    }
    private function prune(string $server, string $newest, callable $guard): void
    {
        $cutoff = $this->clock->now()->getTimestamp() - 72 * 3600;
        foreach (glob($this->folder($server).'/*.json') ?: [] as $path) {
            $id = pathinfo($path, PATHINFO_FILENAME); if ($id === $newest || !preg_match('/^[a-f0-9]{32}$/D', $id)) { continue; }
            try {
                $record = $this->metadata($server, $id);
                if ($record->pinned || !is_string($record->verified_at) || !preg_match('/^\d{4}-\d\d-\d\dT.*(?:Z|[+-]\d\d:\d\d)$/D', $record->created_at)
                    || (new \DateTimeImmutable($record->created_at))->getTimestamp() >= $cutoff) { continue; }
                $archive = $this->path($server, $id.'.zip'); $this->files->native([$archive]);
                $guard();
                if (is_file($archive) && !unlink($archive)) { continue; } unlink($path);
            } catch (OutcomeUnknown|AccessDeniedException $error) { throw $error; }
            catch (\Throwable) { /* Unreadable/ambiguous history remains for owner inspection. */ }
        }
    }
    private function restore(Principal $actor, string $id, \stdClass $payload, callable $guard, bool $recovery): array
    {
        if ($payload->confirmation !== 'WIEDERHERSTELLEN') { throw new \InvalidArgumentException('Ausdrückliche Wiederherstellungsbestätigung fehlt.'); }
        if (!$recovery) { $this->guardPending($id); }
        $status = $this->offline($id, $guard); $preview = $this->preview($actor, $id, $payload->backup_id, $recovery, $status);
        if (!hash_equals($preview['token'], $payload->token)) { throw new \DomainException('Wiederherstellungsziel geändert. Vorschau erneut laden.'); }
        $this->verify($id, $payload->backup_id, $guard);
        if (!hash_equals($payload->token, $this->plans->token($this->plans->resolve($id, $status)))) { throw new \DomainException('Wiederherstellungsziel geändert. Vorschau erneut laden.'); }
        $before = $recovery ? $payload->backup_id : $this->create($id, $guard, 'before_restore', true, false)['backup_id'];
        $guard();
        if (!$recovery) { PrivateFiles::write($this->path($id, 'restore-pending.json'), PrivateFiles::json((object) ['before' => $before, 'binding' => $preview['target']])); }
        try { $this->install($id, $payload->backup_id, $guard); }
        catch (OutcomeUnknown|AccessDeniedException $error) { throw $error; } // Durable pending marker requires explicit recovery after shutdown/revocation.
        catch (\Throwable $error) {
            if (!$recovery) {
                try { $this->install($id, $before, $guard); }
                catch (\Throwable $rollback) { throw new OutcomeUnknown('Wiederherstellung und Rückweg unterbrochen. Server bleibt bis zum ausdrücklichen Rückweg gesperrt.', previous: $rollback); }
                $guard(); unlink($this->path($id, 'restore-pending.json'));
                throw new \DomainException('Wiederherstellung fehlgeschlagen. Zuvor gesicherter Stand wurde zurückgeholt.', previous: $error);
            }
            throw new OutcomeUnknown('Rückweg nicht abgeschlossen. Server bleibt gesperrt.', previous: $error);
        }
        $guard(); if (!unlink($this->path($id, 'restore-pending.json'))) { throw new OutcomeUnknown('Wiederherstellung geprüft; Sperre nicht entfernbar. Rückweg prüfen.'); }
        return ['message' => 'Welt und Konfiguration wiederhergestellt. Server bleibt gestoppt.', 'before_backup_id' => $before];
    }
    private function install(string $id, string $identifier, callable $guard): void
    {
        $record = $this->metadata($id, $identifier); $status = $this->offline($id, $guard); $plan = $this->plans->resolve($id, $status);
        if (!$this->plans->sameBinding($record->manifest->binding ?? null, $plan['binding'])) { throw new \DomainException('Wiederherstellungsziel passt nicht zum Archiv.'); }
        $this->verify($id, $identifier, $guard); $required = array_sum(array_map(fn ($file) => $file->size, $record->manifest->files));
        $this->files->space(dirname($plan['world']), $required); $nonce = bin2hex(random_bytes(16));
        $stage = dirname($plan['world']).'/.indy-restore-'.$nonce; $old = dirname($plan['world']).'/.indy-previous-'.$nonce; $moved = false;
        if (!mkdir($stage, 0700)) { throw new \DomainException('Wiederherstellung nicht vorbereitbar.'); }
        try {
            $this->phase($id, 'Bereite Wiederherstellung vor'); $this->archives->stage($this->path($id, $identifier.'.zip'), $record->manifest, $stage);
            $configuration = $this->plans->decode($id, PrivateFiles::read($stage.'/config/server', BackupFiles::CONFIG_LIMIT));
            if (!$this->plans->sameBinding($this->plans->resolve($id, $status, $configuration)['binding'], $plan['binding'])) { throw new \DomainException('Gesicherte Konfiguration verweist auf eine andere Welt.'); }
            $status = $this->offline($id, $guard);
            if (!$this->plans->sameBinding($this->plans->resolve($id, $status)['binding'], $plan['binding'])) { throw new \DomainException('Weltziel während der Vorbereitung geändert.'); }
            $this->files->native([$plan['world'], ...array_values($plan['files'])], $plan['world']);
            foreach ($plan['files'] as $name => $path) { $this->files->space(dirname($path), filesize($stage.'/'.$name)); }
            $guard(); $this->phase($id, 'Ersetze Welt und Konfiguration');
            if (is_dir($plan['world'])) { if (!rename($plan['world'], $old)) { throw new \DomainException('Alte Welt nicht verschiebbar.'); } $moved = true; }
            if (!rename($stage.'/world', $plan['world'])) { throw new \DomainException('Vorbereitete Welt nicht übernehmbar.'); }
            foreach ($plan['files'] as $name => $path) { $guard(); PrivateFiles::write($this->files->plain($path), PrivateFiles::read($stage.'/'.$name, BackupFiles::CONFIG_LIMIT)); }
            $this->offline($id, $guard); $inventory = $this->files->inventory($plan['world'], $plan['files']);
            $expected = []; foreach ($record->manifest->files as $file) { $expected[$file->name] = $file->sha256; }
            $actual = []; foreach ($inventory['signature'] as $name => $signature) { $actual[$name] = $signature[5]; }
            ksort($expected); ksort($actual);
            if ($actual !== $expected || $inventory['directories'] !== $record->manifest->directories) { throw new \DomainException('Wiederhergestellte Dateien stimmen nicht mit dem Archiv überein.'); }
            $guard(); if ($moved) { $this->files->native([$old], $old); $this->files->removeTree($old); }
        } finally { $this->files->native([$stage], $stage); $this->files->removeTree($stage); }
    }
}
