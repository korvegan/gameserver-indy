<?php
declare(strict_types=1);

require dirname(__DIR__).'/src/Foundation.php';
require dirname(__DIR__).'/src/Access.php';

use IndyNext\{AccessStore, AuthorizedOperations, FixtureStore, ListenerIdentity, Principal, Role};

function check(bool $value, string $message): void
{
    if (!$value) { throw new RuntimeException($message); }
}
function denied(callable $action, string $expected = 'access_denied'): void
{
    try { $action(); }
    catch (DomainException $e) { check($e->getMessage() === $expected, $e->getMessage()); return; }
    throw new RuntimeException('Expected rejection: '.$expected);
}

$directory = FixtureStore::create();
try {
    AccessStore::create($directory);
    $access = new AccessStore($directory);
    $owner = Principal::owner();
    $access->createGrant($owner, 'Robin@example.org', Role::Family);
    $access->createGrant($owner, 'Alex@example.org', Role::Administrator);
    $family = $access->byLogin('ROBIN@example.org');
    $admin = $access->byLogin('alex@example.org');
    $origin = 'https://fixture.tailnet.ts.net';
    $local = ['REMOTE_ADDR'=>'127.0.0.1','HTTP_HOST'=>'127.0.0.1:18765','INDY_LISTENER'=>'local'];
    $remote = ['REMOTE_ADDR'=>'127.0.0.1','HTTP_HOST'=>'fixture.tailnet.ts.net','INDY_LISTENER'=>'family'];
    check(ListenerIdentity::resolve($access, $local, ['tailscale-user-login'=>'robin@example.org','role'=>'family'], '18765', null)[0] == $owner,
        'Local owner ignores all identity/role headers');
    check(ListenerIdentity::resolve($access, $remote, ['tailscale-user-login'=>'robin@example.org','role'=>'administrator'], '18765', 'fixture.tailnet.ts.net')[0] == $family,
        'Family role comes from grant');
    foreach ([[], ['tailscale-user-login'=>'unknown@example.org'], ['tailscale-user-login'=>'alex@example.org,robin@example.org']] as $headers) {
        denied(fn () => ListenerIdentity::resolve($access, $remote, $headers, '18765', 'fixture.tailnet.ts.net'),
            str_contains($headers['tailscale-user-login'] ?? '', ',') ? 'invalid_identity' : 'access_denied');
    }
    foreach ([['REMOTE_ADDR'=>'192.0.2.1'], ['INDY_LISTENER'=>''], ['HTTP_HOST'=>'127.0.0.1:18765']] as $change) {
        denied(fn () => ListenerIdentity::resolve($access, array_replace($remote, $change), ['tailscale-user-login'=>'alex@example.org'], '18765', 'fixture.tailnet.ts.net'));
    }
    denied(fn () => ListenerIdentity::resolve($access, $remote, ['tailscale-user-login'=>'alex@example.org'], '18765', null));
    denied(fn () => ListenerIdentity::headers(['Origin'=>$origin,'origin'=>$origin]));
    denied(fn () => $access->view($family));
    denied(fn () => $access->createGrant($family, 'intruder@example.org', Role::Administrator));
    denied(fn () => $access->changeGrant($family, $admin->id, 1, false, null));
    denied(fn () => $access->createGrant($owner, 'robin@example.org', Role::Family), 'identity_exists');
    denied(fn () => $access->changeGrant($admin, $admin->id, 1, false, null), 'own_access_protected');
    denied(fn () => $access->authorize(new Principal($family->id, $family->login, $family->name, Role::Administrator, 1), 'access.manage'));

    $token = $access->token($admin, $origin);
    $access->checkWrite($admin, $origin, ['origin'=>$origin], $token);
    check((new AccessStore($directory))->token($admin, $origin) === $token, 'Key and token survive reopen');
    foreach ([['origin'=>'https://evil.example'], [], ['origin'=>$origin.', '.$origin]] as $headers) {
        denied(fn () => $access->checkWrite($admin, $origin, $headers, $token), 'invalid_csrf');
    }
    denied(fn () => $access->checkWrite($family, $origin, ['origin'=>$origin], $token), 'invalid_csrf');
    denied(fn () => $access->checkWrite($admin, 'http://127.0.0.1:18765', ['origin'=>'http://127.0.0.1:18765'], $token), 'invalid_csrf');

    $store = new FixtureStore($directory);
    $operations = new AuthorizedOperations($access, $store);
    foreach (['start','stop'] as $action) {
        $operation = $operations->reserve($family, 'aska', $action);
        $operations->begin($operation['id']);
        $store->transition($operation['id'], 'succeeded');
    }
    $waiting = $operations->reserve($family, 'aska', 'start');
    $access->changeGrant($owner, $family->id, 1, false, null);
    denied(fn () => $operations->begin($waiting['id']));
    check($store->operation($waiting['id'])['state'] === 'cancelled', 'Revoked pending operation cancelled before execution');
    denied(fn () => $operations->reserve($family, 'aska', 'stop'));
    denied(fn () => $access->byLogin('robin@example.org'));
    $other = new AccessStore($directory);
    denied(fn () => $other->changeGrant($owner, $family->id, 1, true, null), 'grant_changed');
    $access->changeGrant($owner, $family->id, 2, true, null);
    denied(fn () => $access->authorize($family, 'start', 'aska'));

    $pending = $operations->reserve($admin, 'enshrouded', 'stop');
    $access->changeGrant($owner, $admin->id, 1, null, Role::Family);
    denied(fn () => $access->checkWrite($admin, $origin, ['origin'=>$origin], $token));
    denied(fn () => $operations->begin($pending['id']));
    $changed = $access->byLogin('alex@example.org');
    denied(fn () => $access->checkWrite($changed, $origin, ['origin'=>$origin], $token), 'invalid_csrf');
    denied(fn () => $access->view($changed));

    $db = new PDO('sqlite:'.$directory.'/access.sqlite');
    $db->exec("UPDATE grants SET servers = '[\"aska\"]', revision=revision+1 WHERE login = 'robin@example.org'");
    $restricted = $access->byLogin('robin@example.org');
    denied(fn () => $operations->reserve($restricted, 'enshrouded', 'start'));
    $restrictedOp = $operations->reserve($restricted, 'aska', 'start');
    $operations->begin($restrictedOp['id']);
    check($store->operation($restrictedOp['id'])['state'] === 'running', 'Permitted server still usable');
    file_put_contents($directory.'/csrf-key', 'broken');
    try { new AccessStore($directory); throw new RuntimeException('Broken key accepted'); }
    catch (RuntimeException $e) { check($e->getMessage() === 'invalid_csrf_key', 'Broken key fails closed'); }
    echo "PASS: trusted listeners, explicit grants, roles/server scopes, bound CSRF, revocation before acceptance/execution, stale revisions and persistent key\n";
} finally {
    unset($access,$other,$store,$operations,$db);
    foreach (glob($directory.'/*') as $file) { unlink($file); }
    rmdir($directory);
}
