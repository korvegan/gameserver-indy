<?php
declare(strict_types=1);
namespace App\Runtime;

use App\Instance\NativePaths;
use App\Storage\PrivateFiles;

/** Private local configuration; no HTTP route accepts tool or release paths. */
final class RuntimeSettings
{
    public function __construct(private readonly SliceRuntime $runtime,private readonly NativePaths $paths) {}
    public function read(): array
    {
        $value = PrivateFiles::object(PrivateFiles::read($this->runtime->directory.'/runtime.local.json',16384));
        if (array_diff(array_keys(get_object_vars($value)),['php','frankenphp','port','family_port','threads'])
            || !is_string($value->php ?? null) || !is_string($value->frankenphp ?? null)) { throw new \DomainException('Private Laufzeitkonfiguration unvollständig.'); }
        foreach (['php','frankenphp'] as $key) {
            $this->paths->absolute($value->{$key});
            if (!is_file($this->paths->local($value->{$key}))) { throw new \DomainException('Ein festgelegtes Laufzeitwerkzeug fehlt.'); }
        }
        $port = $value->port ?? 8765; $family = $value->family_port ?? 8767; $threads = $value->threads ?? 6;
        if (!is_int($port) || !is_int($family) || $port < 1024 || $port > 65535 || $family < 1024 || $family > 65535 || $port === $family
            || !is_int($threads) || $threads < 4 || $threads > 24) { throw new \DomainException('Ungültige Listener- oder Threadkonfiguration.'); }
        return ['php'=>$value->php,'frankenphp'=>$value->frankenphp,'port'=>$port,'family_port'=>$family,'threads'=>$threads];
    }
}
