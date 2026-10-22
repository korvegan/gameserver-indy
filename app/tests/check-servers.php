<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Foundation.php';
require dirname(__DIR__).'/src/Access.php';
require dirname(__DIR__).'/src/WindowsBridge.php';
require dirname(__DIR__).'/src/ServerRuntime.php';
use IndyNext\{AccessStore, AuthorizedOperations, FixtureStore, Principal, Role, ServerRuntime};
final class InterleavedSnapshotConnection extends PDO
{
    public ?Closure $afterQuery = null;
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $result = parent::query($query, $fetchMode, ...$fetchModeArgs);
        $after = $this->afterQuery;
        $this->afterQuery = null;
        if ($after !== null) { $after(); }
        return $result;
    }
}
function check(bool $value, string $message): void { if (!$value) { throw new RuntimeException($message); } }
function denied(callable $action, string $code): void {
    try { $action(); } catch (DomainException $e) { check($e->getMessage()===$code, $e->getMessage()); return; }
    throw new RuntimeException('Expected '.$code);
}
$directory=FixtureStore::create();
try {
    AccessStore::create($directory);
    ServerRuntime::createFixture($directory, getenv('SLICE_PHP') ?: PHP_BINARY);
    $runtime=new ServerRuntime($directory); $store=new FixtureStore($directory); $access=new AccessStore($directory);
    $owner=Principal::owner();
    $view=function ($id) use (&$runtime,$owner): array {
        return array_values(array_filter($runtime->snapshot($owner), fn ($s) => $s['id']===$id))[0];
    };
    $wait=function ($id, $state) use ($view): array {
        $until=microtime(true)+8;
        do { $s=$view($id); if (($s['operation']['state'] ?? '')===$state) { return $s; } usleep(20000); } while (microtime(true)<$until);
        throw new RuntimeException('Executor did not reach '.$state);
    };
    check(!$view('aska')['can_start'], 'Unobserved games disabled');
    foreach (['aska','enshrouded'] as $id) { $runtime->execute($id,'status'); check($view($id)['can_start'], 'Observed stop enables start'); }
    $store->observe('aska','running',true,'2020-01-01T00:00:00Z');
    check($view('aska')['process']==='stopped', 'Older status never overwrites a newer observation');

    // Complete a writer between the reader's SELECT and fetch: one response must retain one database snapshot.
    $reader = new InterleavedSnapshotConnection('sqlite:'.$directory.'/state.sqlite', null, null,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $reader->exec('PRAGMA journal_mode=WAL');
    $snapshotStore = (new ReflectionProperty(ServerRuntime::class, 'store'))->getValue($runtime);
    (new ReflectionProperty(FixtureStore::class, 'db'))->setValue($snapshotStore, $reader);
    $crossing = $store->reserve('enshrouded', 'start', $owner->id, $owner->role);
    $store->transition($crossing['id'], 'running');
    $reader->afterQuery = function () use ($store, $crossing): void {
        $store->observe('enshrouded', 'running', null);
        $store->transition($crossing['id'], 'succeeded');
    };
    $during = $view('enshrouded');
    check($during['process']==='stopped' && $during['operation']['state']==='running'
        && !$during['can_start'] && !$during['can_stop'], 'Concurrent completion never mixes old process and new operation');
    $after = $view('enshrouded');
    check($after['process']==='running' && $after['operation']['state']==='succeeded' && $after['can_stop'], 'Next snapshot sees the completed start');
    $runtime->execute('enshrouded', 'status');

    file_put_contents($directory.'/scenario.json',json_encode(['enshrouded'=>['start_delay_ms'=>2200]]));
    $start=microtime(true);
    $first=$runtime->request($owner,'enshrouded','start');
    $again=$runtime->request($owner,'enshrouded','start');
    check(microtime(true)-$start<1.5 && $first['id']===$again['id'], 'Fast asynchronous acceptance and duplicate operation ID');
    denied(fn () => $runtime->request($owner,'enshrouded','stop'),'server_busy');
    check($view('aska')['can_start'], 'Other game remains usable during a slow start');
    $running=$wait('enshrouded','succeeded');
    check($running['process']==='running' && $running['ready']===null && $running['can_stop'], 'Running never invents readiness');
    check(file_get_contents($directory.'/effects-enshrouded.txt')==="start\n", 'Exactly one synthetic effect');
    $settings=json_decode(file_get_contents($directory.'/runtime.json'),true);
    $settings['connections']['enshrouded']='fixture.example.org:15637';
    file_put_contents($directory.'/runtime.json',json_encode($settings));
    $runtime=new ServerRuntime($directory);
    check($view('enshrouded')['connection']==='fixture.example.org:15637', 'Trusted connection belongs to the running game');
    $runtime->request($owner,'enshrouded','stop');
    check($wait('enshrouded','succeeded')['connection']===null, 'Stopped games hide connection values');
    file_put_contents($directory.'/scenario.json',json_encode(['enshrouded'=>['outcome'=>'failed']]));
    $runtime->request($owner,'enshrouded','start');
    $failed=$wait('enshrouded','failed');
    check($failed['status']==='Start fehlgeschlagen' && $failed['process']==='stopped' && $failed['can_start'], 'Confirmed failed start remains retryable');

    file_put_contents($directory.'/scenario.json',json_encode(['aska'=>['outcome'=>'unknown']]));
    $runtime->request($owner,'aska','start');
    $unknown=$wait('aska','unknown');
    check($unknown['status']==='Ausgang unklar' && !$unknown['can_start'] && !$unknown['can_stop'], 'Uncertain operation blocks both actions');
    denied(fn () => $runtime->request($owner,'aska','stop'),'server_busy');
    $runtime->execute('aska','status');
    check($view('aska')['operation']['state']==='unknown', 'Process alone does not resolve operation');
    file_put_contents($directory.'/scenario.json',json_encode(['aska'=>['reconcile'=>true]]));
    $runtime->execute('aska','status');
    check($view('aska')['operation']['state']==='succeeded', 'Confirmed receipt resolves operation');
    check(file_get_contents($directory.'/effects-aska.txt')==="start\n", 'Reconciliation never repeats an effect');

    $access->createGrant($owner,'fixture-family@example.org',Role::Family);
    $family=$access->byLogin('fixture-family@example.org');
    $pending=(new AuthorizedOperations($access,$store))->reserve($family,'aska','stop');
    $access->changeGrant($owner,$family->id,1,false,null);
    $runtime->execute('aska',$pending['id']);
    check($store->operation($pending['id'])['state']==='cancelled', 'Executor rechecks revoked actor');
    check(file_get_contents($directory.'/effects-aska.txt')==="start\n", 'Revoked action never reaches bridge');
    $db=new PDO('sqlite:'.$directory.'/state.sqlite');
    $db->exec('UPDATE servers SET refresh_after=0');
    file_put_contents($directory.'/scenario.json',json_encode(['aska'=>['status_delay_ms'=>2200], 'enshrouded'=>['status_delay_ms'=>2200]]));
    $runtime->refresh($owner);
    usleep(100000);
    $started=microtime(true);
    $runtime->request($owner,'enshrouded','start');
    check(microtime(true)-$started<1.5, 'Slow status never blocks action acceptance');
    $wait('enshrouded','succeeded');
    usleep(2300000);
    file_put_contents($directory.'/scenario.json','{}');
    $runtime->request($owner,'enshrouded','stop');
    $wait('enshrouded','succeeded');
    $db->exec("UPDATE servers SET observed_at='2020-01-01T00:00:00.000000Z' WHERE id='enshrouded'");
    check(!$view('enshrouded')['can_start'], 'Stale observations disable actions');
    $runtime->execute('enshrouded','status');
    $pending=(new AuthorizedOperations($access,$store))->reserve($owner,'enshrouded','start');
    $db->exec("UPDATE operations SET updated_at=0 WHERE id='".$pending['id']."'");
    $store->recover('enshrouded');
    $runtime->execute('enshrouded',$pending['id']);
    check($store->operation($pending['id'])['state']==='cancelled','Late worker cannot start cancelled pending action');
    $pending=(new AuthorizedOperations($access,$store))->reserve($owner,'enshrouded','start');
    (new AuthorizedOperations($access,$store))->begin($pending['id']);
    $db->exec("UPDATE operations SET updated_at=0 WHERE id='".$pending['id']."'");
    $store->recover('enshrouded');
    check($view('enshrouded')['operation']['state']==='unknown', 'Lost running executor stays uncertain');
    $public=json_encode($runtime->snapshot($owner));
    foreach ([$directory,'bridge_command','actor_revision','"pid"'] as $private) { check(!str_contains($public,$private), 'No private executor data in public status'); }
    echo "PASS: asynchronous actions, idempotency, independent games, truthful states, read-only receipt reconciliation, revocation, stale cache and executor recovery\n";
} finally {
    unset($runtime,$store,$access,$db,$reader,$snapshotStore);
    foreach (glob($directory.'/*') as $file) { unlink($file); }
    rmdir($directory);
}
