<?php
declare(strict_types=1);
namespace App\Runtime;

use App\Storage\PrivateFiles;

/** Independent lifetime watchdog. Fork before DB use or starting any management child. */
final class RuntimeGuard
{
    private int $pid;
    private mixed $channel;
    public readonly array $owner;

    public function __construct(string $directory,array $reservedListeners)
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_setpgid')) {
            throw new \DomainException('Der WSL-Supervisor benötigt PHP pcntl und posix.');
        }
        // A dedicated management group includes children born immediately before a parent crash.
        // The independent guard moves outside it. Native Windows game EXEs have no Linux group.
        if (posix_getpgrp() !== getmypid() && !posix_setpgid(0,0)) { throw new \DomainException('Eigene Verwaltungsprozessgruppe nicht anlegbar.'); }
        $parent = LinuxProcess::identity(getmypid()) ?? throw new \DomainException('Linux-Prozessidentität fehlt.');
        $pair = stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,STREAM_IPPROTO_IP);
        if ($pair === false) { throw new \DomainException('Wächterkanal fehlt.'); }
        $this->pid = pcntl_fork();
        if ($this->pid === -1) { fclose($pair[0]); fclose($pair[1]); throw new \DomainException('Laufzeitwächter nicht startbar.'); }
        if ($this->pid === 0) {
            fclose($pair[0]);
            foreach ($reservedListeners as $listener) { fclose($listener); }
            pcntl_signal(SIGTERM,SIG_DFL); pcntl_signal(SIGINT,SIG_DFL);
            if (posix_setsid() === -1) { exit(1); }
            fwrite($pair[1],"ready\n"); stream_set_blocking($pair[1],false);
            while (LinuxProcess::alive($parent)) {
                fread($pair[1],16);
                if (feof($pair[1])) { exit(0); }
                usleep(50000);
            }
            try {
                $path = $directory.'/runtime-lifecycle.json';
                $state = PrivateFiles::object(PrivateFiles::read($path,4096));
                if (($state->owner->pid ?? null) === $parent['pid'] && ($state->owner->start ?? null) === $parent['start']) {
                    $state->active = false;
                    PrivateFiles::write($path,PrivateFiles::json($state));
                }
            } catch (\Throwable) { /* Missing or unreadable state is already fenced by the owner check. */ }
            // The inherited controller flock remains held until all owned management children end.
            posix_kill(-$parent['pid'],SIGTERM); posix_kill(-$parent['pid'],SIGCONT);
            $deadline = microtime(true)+15;
            while (LinuxProcess::groupAlive($parent['pid']) && microtime(true)<$deadline) { usleep(50000); }
            if (LinuxProcess::groupAlive($parent['pid'])) { posix_kill(-$parent['pid'],SIGKILL); }
            exit(0);
        }
        fclose($pair[1]); $this->channel = $pair[0];
        stream_set_timeout($this->channel,5);
        if (fgets($this->channel) !== "ready\n") { $this->finish(); throw new \DomainException('Laufzeitwächter wurde nicht bereit.'); }
        $guard = LinuxProcess::identity($this->pid) ?? throw new \DomainException('Wächteridentität fehlt.');
        $this->owner = $parent + ['guard'=>$guard];
    }
    public function assertAlive(): void
    {
        if (!LinuxProcess::alive($this->owner['guard'])) { throw new \DomainException('Unabhängiger Laufzeitwächter wurde beendet.'); }
    }
    public function finish(): void
    {
        if (is_resource($this->channel)) { fclose($this->channel); }
        if ($this->pid > 0) { pcntl_waitpid($this->pid,$status); }
    }
}
