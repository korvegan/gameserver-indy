<?php
declare(strict_types=1);
namespace App\Runtime;
use App\Storage\PrivateFiles;

/** Only a running supervisor may publish a verified listener binding. */
final class ListenerRuntime
{
    public function __construct(private readonly SliceRuntime $runtime,private readonly RuntimeLifecycle $lifecycle) {}
    public function view(): array
    {
        $unknown = ['active'=>false,'hostname'=>'','port'=>0,'error'=>null];
        try {
            $value = PrivateFiles::object(PrivateFiles::read($this->runtime->directory.'/listener-runtime.json',4096));
            if (($value->active ?? null) !== true || ($value->epoch ?? null) !== $this->lifecycle->epoch()
                || !is_string($value->hostname ?? null) || !is_int($value->port ?? null) || $value->port < 1024 || $value->port > 65535) { return $unknown; }
            return ['active'=>true,'hostname'=>$value->hostname,'port'=>$value->port,'error'=>null];
        } catch (\Throwable) { return $unknown; }
    }
}
