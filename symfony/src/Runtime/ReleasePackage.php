<?php
declare(strict_types=1);
namespace App\Runtime;

use App\Storage\PrivateFiles;
use Symfony\Component\Process\Process;

/** Local, commit-bound code package. Never selects a release or touches runtime data. */
final class ReleasePackage
{
    public function build(string $source,string $destination,string $commit): array
    {
        if (PHP_OS_FAMILY!=='Linux') { throw new \DomainException('Releasepakete für den ersten WSL-Betrieb unter Linux erstellen.'); }
        if (!preg_match('/^[a-f0-9]{40}$/D',$commit) || !str_starts_with($source,'/') || !str_starts_with($destination,'/')
            || !is_dir($source) || is_link($source) || !is_dir(dirname($destination))
            || file_exists($destination) || is_link($destination)) { throw new \DomainException('Commit und neue getrennte absolute Releaseablage angeben.'); }
        $source=realpath($source); $destination=realpath(dirname($destination)).'/'.basename($destination);
        if ($destination===$source || str_starts_with($destination,$source.'/') || str_starts_with($source,$destination.'/')
            || $this->git($source,['rev-parse','--show-toplevel'])!==$source
            || $this->git($source,['rev-parse','HEAD'])!==$commit
            || $this->git($source,['status','--porcelain','--untracked-files=no'])!=='') {
            throw new \DomainException('Sauberen Checkout genau des geprüften Commits und getrenntes Ziel verwenden.');
        }
        $vendor=$source.'/symfony/vendor'; $before=$this->inventory($vendor);
        foreach (['autoload.php','composer/installed.php','composer/autoload_real.php'] as $requiredFile) {
            if (!isset($before[$requiredFile])) { throw new \DomainException('Vollständige Composer-Installation erforderlich.'); }
        }
        $lockBytes=PrivateFiles::read($source.'/symfony/composer.lock',4194304);
        $this->dependencies($lockBytes,PrivateFiles::read($vendor.'/composer/installed.json',4194304));
        $free=disk_free_space(dirname($destination)); $required=536870912;
        foreach ($before as $relative=>$hash) { $required+=filesize($vendor.'/'.$relative); }
        if (!is_float($free) || $free<$required) { throw new \DomainException('Zu wenig Speicher für Release und Reserve.'); }
        $staging=dirname($destination).'/.indy-build-'.bin2hex(random_bytes(12));
        if (!mkdir($staging,0700)) { throw new \DomainException('Release nicht anlegbar.'); }
        try {
            $archive=$staging.'/source.zip';
            $this->git($source,['archive','--format=zip','--output='.$archive,$commit]); chmod($archive,0600);
            PrivateFiles::directory($staging.'/code'); $code=$staging.'/code';
            $this->extract($archive,$code); unlink($archive);
            $this->committedFiles($source,$code,$commit);
            if (!hash_equals(hash('sha256',$lockBytes),hash_file('sha256',$code.'/symfony/composer.lock'))) {
                throw new \DomainException('Abhängigkeiten gehören nicht zum Commit.');
            }
            PrivateFiles::directory($code.'/symfony/vendor');
            foreach ($before as $relative=>$hash) {
                PrivateFiles::directory(dirname($code.'/symfony/vendor/'.$relative));
                if (!copy($vendor.'/'.$relative,$code.'/symfony/vendor/'.$relative)) { throw new \DomainException('Abhängigkeit nicht kopierbar.'); }
                chmod($code.'/symfony/vendor/'.$relative,0600);
                if (!hash_equals($hash,hash_file('sha256',$code.'/symfony/vendor/'.$relative))) { throw new \DomainException('Abhängigkeit während Kopie geändert.'); }
            }
            if ($before!==$this->inventory($vendor) || $this->git($source,['rev-parse','HEAD'])!==$commit
                || $this->git($source,['status','--porcelain','--untracked-files=no'])!=='') { throw new \DomainException('Quelle während Erstellung geändert.'); }
            $hashes=$this->inventory($code);
            PrivateFiles::write($code.'/.indy-release.json',PrivateFiles::json((object) ['format'=>'gameserver-indy-release','version'=>1,
                'commit'=>$commit,'composer_lock_sha256'=>hash('sha256',$lockBytes),'dependencies'=>'installed-lock-set-including-dev',
                'hashes'=>(object) $hashes]));
            $this->verify($code,$commit);
            // Publication is an exclusive directory creation; an existing release is never replaced.
            if (file_exists($destination) || is_link($destination) || !mkdir($destination,0700)) { throw new \DomainException('Releaseziel inzwischen belegt.'); }
            try {
                // Rename into the exclusively reserved directory, then publish the code as its child.
                if (!rename($code,$destination.'/code')) { throw new \DomainException('Release nicht veröffentlichbar.'); }
                $this->readOnly($destination.'/code');
                chmod($destination,0555);
            } catch (\Throwable $error) { $this->remove($destination); throw $error; }
            return ['commit'=>$commit,'files'=>count($hashes),'directory'=>$destination.'/code'];
        } finally { $this->remove($staging); }
    }

    public function verify(string $directory,string $commit): array
    {
        $manifest=PrivateFiles::object(PrivateFiles::read($directory.'/.indy-release.json',16777216));
        if (($manifest->format ?? null)!=='gameserver-indy-release' || ($manifest->version ?? null)!==1
            || ($manifest->commit ?? null)!==$commit || !(($manifest->hashes ?? null) instanceof \stdClass)) { throw new \DomainException('Unbekannter Releasenachweis.'); }
        $actual=$this->inventory($directory); unset($actual['.indy-release.json']);
        if ($actual!==get_object_vars($manifest->hashes) || ($manifest->composer_lock_sha256 ?? null)!==($actual['symfony/composer.lock'] ?? null)) {
            throw new \DomainException('Release unvollständig oder verändert.');
        }
        return ['commit'=>$commit,'files'=>count($actual),'verified'=>true];
    }

    private function dependencies(string $lockBytes,string $installedBytes): void
    {
        $lock=json_decode($lockBytes,true,flags:JSON_THROW_ON_ERROR); $installed=json_decode($installedBytes,true,flags:JSON_THROW_ON_ERROR);
        $expected=[]; $actual=[];
        foreach (array_merge($lock['packages'] ?? [],$lock['packages-dev'] ?? []) as $package) {
            $expected[$package['name']]=[$package['version'],$package['source']['reference'] ?? null,$package['dist']['reference'] ?? null];
        }
        foreach ($installed['packages'] ?? [] as $package) {
            $actual[$package['name']]=[$package['version'],$package['source']['reference'] ?? null,$package['dist']['reference'] ?? null];
        }
        ksort($expected); ksort($actual);
        if ($expected===[] || $expected!==$actual) { throw new \DomainException('Installierte Bibliotheken entsprechen nicht vollständig composer.lock.'); }
    }
    private function committedFiles(string $source,string $directory,string $commit): void
    {
        // Git archive also reads local info/attributes: neither export-ignore nor export-subst may
        // silently remove/change committed application bytes while retaining the reviewed SHA.
        $expected=[];
        foreach (explode("\0",$this->git($source,['ls-tree','-r','-z','--full-tree',$commit])) as $entry) {
            if ($entry==='') { continue; }
            if (!preg_match('/^(100644|100755) blob ([a-f0-9]{40})\t(.+)$/Ds',$entry,$match)) {
                throw new \DomainException('Nur reguläre versionierte Codedateien sind erlaubt.');
            }
            $this->relative($match[3]); $expected[$match[3]]=$match[2];
        }
        $actual=$this->inventory($directory); ksort($expected);
        if (array_keys($expected)!==array_keys($actual)) { throw new \DomainException('Git-Archiv enthält nicht den vollständigen geprüften Commit.'); }
        foreach ($expected as $relative=>$object) {
            $file=$directory.'/'.$relative; $hash=hash_init('sha1'); hash_update($hash,'blob '.filesize($file)."\0");
            if (!hash_update_file($hash,$file) || !hash_equals($object,hash_final($hash))) {
                throw new \DomainException('Git-Archiv enthält veränderte Commitbytes.');
            }
        }
    }
    private function extract(string $path,string $target): void
    {
        $zip=new \ZipArchive(); if ($zip->open($path)!==true) { throw new \DomainException('Git-Archiv nicht lesbar.'); }
        try {
            $seen=[]; $size=0;
            if ($zip->numFiles>100000) { throw new \DomainException('Git-Archiv zu groß.'); }
            for ($index=0;$index<$zip->numFiles;$index++) {
                $stat=$zip->statIndex($index); $name=$stat['name']; $relative=rtrim($name,'/');
                $this->relative($relative);
                $zip->getExternalAttributesIndex($index,$system,$attributes); $type=($attributes>>16)&0170000;
                if (isset($seen[$relative]) || !in_array($type,[0,0040000,0100000],true)
                    || preg_match('~^(?:data(?:/|$)|manager\.local\.json$|\.indy-|symfony/(?:vendor|var)(?:/|$))~',$relative)) {
                    throw new \DomainException('Unsicherer oder veränderlicher Inhalt im Git-Archiv.');
                }
                $seen[$relative]=true; $size+=$stat['size'];
                if ($size>1073741824) { throw new \DomainException('Git-Archiv zu groß.'); }
                if (str_ends_with($name,'/')) { PrivateFiles::directory($target.'/'.$relative); continue; }
                PrivateFiles::directory(dirname($target.'/'.$relative));
                $input=$zip->getStream($name); $output=fopen($target.'/'.$relative,'xb');
                if ($input===false || $output===false) { throw new \DomainException('Git-Archiv nicht vollständig lesbar.'); }
                try { if (stream_copy_to_stream($input,$output)!==$stat['size']) { throw new \DomainException('Unvollständiger Archiveintrag.'); } }
                finally { fclose($input); fclose($output); }
                chmod($target.'/'.$relative,0600);
            }
            foreach (['symfony/bin/indy','symfony/composer.lock','web/app.js','profiles/enshrouded.json'] as $required) {
                if (!is_file($target.'/'.$required)) { throw new \DomainException('Unvollständiger Anwendungscode.'); }
            }
        } finally { $zip->close(); }
    }
    private function relative(string $path): void
    {
        if ($path==='' || strpbrk($path,"\\\0\r\n")!==false || str_starts_with($path,'/')
            || array_intersect(explode('/',$path),['.','..',''])!==[] || in_array('.git',explode('/',$path),true)) {
            throw new \DomainException('Unsicherer relativer Dateipfad.');
        }
    }
    private function inventory(string $directory,string $prefix='',int &$count=0): array
    {
        if (!is_dir($directory) || is_link($directory)) { throw new \DomainException('Vollständige lokale Dateien erforderlich.'); }
        $result=[];
        foreach (scandir($directory) as $name) {
            if ($name==='.' || $name==='..') { continue; }
            $relative=$prefix.$name; $this->relative($relative); $path=$directory.'/'.$name;
            if (is_link($path)) { throw new \DomainException('Verknüpfte Releasedatei abgewiesen.'); }
            if (is_dir($path)) { $result+=$this->inventory($path,$relative.'/',$count); continue; }
            if (!is_file($path) || stat($path)['nlink']!==1 || ++$count>100000) { throw new \DomainException('Unzulässiger oder zu großer Dateibestand.'); }
            $hash=hash_file('sha256',$path); if (!is_string($hash)) { throw new \DomainException('Datei nicht vollständig prüfbar.'); } $result[$relative]=$hash;
        }
        ksort($result); return $result;
    }
    private function git(string $source,array $arguments): string
    {
        $process=new Process(['git','-C',$source,...$arguments],timeout:120); $process->mustRun(); return rtrim($process->getOutput(),"\r\n");
    }
    private function readOnly(string $directory): void
    {
        foreach (scandir($directory) as $name) { if ($name!=='.' && $name!=='..') { $path=$directory.'/'.$name; is_dir($path) ? $this->readOnly($path) : chmod($path,0444); } }
        chmod($directory,0555);
    }
    private function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            chmod($path,0700);
            foreach (scandir($path) as $name) { if ($name!=='.' && $name!=='..') { $this->remove($path.'/'.$name); } } rmdir($path);
        } elseif (file_exists($path) || is_link($path)) { unlink($path); }
    }
}
