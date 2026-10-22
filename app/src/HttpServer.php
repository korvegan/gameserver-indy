<?php
declare(strict_types=1);

namespace IndyNext;

final class HttpServer
{
    public static function run(string $directory, array $settings): never
    {
        $store = new FixtureStore($directory);
        new ServerRuntime($directory);
        $port = $settings['port'] ?? null;
        $familyPort = $settings['family_port'] ?? null;
        $familyHost = $settings['family_host'] ?? null;
        foreach ([$port, $familyPort] as $number) {
            if ($number !== null && (!is_int($number) || $number < 1024 || $number > 65535)) {
                throw new \RuntimeException('invalid_listener');
            }
        }
        if ($port === null || ($familyPort !== null && ($familyPort === $port
            || !is_string($familyHost) || !preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.ts\.net$/D', $familyHost)))) {
            throw new \RuntimeException('invalid_listener');
        }
        $engine = $settings['engine'] ?? '';
        $php = $settings['php'] ?? '';
        if (!is_executable($engine) || !is_executable($php) || !is_executable('/usr/bin/setsid') || !function_exists('pcntl_exec')) {
            throw new \RuntimeException('invalid_runtime_executable');
        }
        $revisionFile = dirname(__DIR__, 2).'/REVISION';
        $revision = is_file($revisionFile) ? trim(file_get_contents($revisionFile)) : 'development';
        if (!preg_match('/^(development|[a-f0-9]{40})$/D', $revision)) { throw new \RuntimeException('invalid_release_revision'); }
        $environment = array_intersect_key(getenv(), array_flip(['PATH','HOME','LANG','LC_ALL','TMPDIR','WSL_INTEROP','WSL_DISTRO_NAME']));
        $environment += ['INDY_NEXT_PORT'=>(string) $port, 'INDY_NEXT_FIXTURE'=>$directory,
            'INDY_FAMILY_CONFIG'=>$familyPort === null ? 'family-disabled.caddy' : 'family.caddy',
            'INDY_APP_BUILD'=>$revision, 'XDG_CONFIG_HOME'=>$directory.'/config', 'XDG_DATA_HOME'=>$directory.'/data'];
        if ($familyPort !== null) {
            $environment['INDY_FAMILY_PORT'] = (string) $familyPort;
            $environment['INDY_FAMILY_HOST'] = $familyHost;
        }
        $command = ['run', '--config', dirname(__DIR__).'/Caddyfile', '--adapter', 'caddyfile'];
        $executable = $engine;
        if ($store->mode() === 'production') {
            if (!is_executable('/usr/bin/flock')) { throw new \RuntimeException('flock_required'); }
            $lock = $directory.'/manager.lock';
            $file = fopen($lock, 'ce');
            if ($file === false || !chmod($lock, 0600)) { throw new \RuntimeException('manager_lock_failed'); }
            fclose($file);
            // Reserve fd 9 for the manager lease; one-shot children explicitly close it.
            $executable = '/bin/sh';
            $command = ['-c','exec 9>"$1"; /usr/bin/flock -n 9 || exit 1; shift; exec "$@"',
                'indy-manager',$lock,$engine,...$command];
        }
        // ZTS php-cli's virtual chdir does not change exec's OS working directory.
        pcntl_exec('/usr/bin/env', ['--chdir='.dirname(__DIR__),$executable,...$command], $environment);
        throw new \RuntimeException('http_start_failed');
    }
}
