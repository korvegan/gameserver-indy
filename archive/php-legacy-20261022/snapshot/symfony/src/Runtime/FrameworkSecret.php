<?php
declare(strict_types=1);
namespace App\Runtime;

use App\Storage\PrivateFiles;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;

/** Release-independent, purpose-separated framework key; never writes or replaces a key. */
final class FrameworkSecret implements EnvVarProcessorInterface
{
    public function getEnv(string $prefix,string $name,\Closure $getEnv): string
    {
        $root=rtrim($getEnv($name),'/\\'); $managed=file_exists($root.'/.indy-managed-state');
        $path=$root.($managed ? '/data' : '').'/session-key';
        if (!$managed && !file_exists($path)) { return 'isolated-slice-only'; }
        if (is_link($root) || is_link(dirname($path)) || is_link($path)) { throw new \DomainException('Privater Frameworkschlüssel nicht lesbar.'); }
        $key=PrivateFiles::read($path,128);
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/D',$key)) { throw new \DomainException('Vorhandenen privaten Schlüssel zuerst prüfen; nicht ersetzen.'); }
        return hash_hmac('sha256','gameserver-indy/symfony/framework/v1',$key);
    }
    public static function getProvidedTypes(): array { return ['indy_secret'=>'string']; }
}
