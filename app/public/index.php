<?php
declare(strict_types=1);

require dirname(__DIR__).'/src/Foundation.php';
require dirname(__DIR__).'/src/Access.php';
require dirname(__DIR__).'/src/WindowsBridge.php';
require dirname(__DIR__).'/src/ServerRuntime.php';

use IndyNext\AccessStore;
use IndyNext\ListenerIdentity;
use IndyNext\Role;
use IndyNext\ServerRuntime;

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; img-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function formBody(): array
{
    if (($_SERVER['CONTENT_TYPE'] ?? '') !== 'application/x-www-form-urlencoded') {
        throw new DomainException('invalid_form');
    }
    $body = file_get_contents('php://input', length: 4097);
    if ($body === false || strlen($body) > 4096) { throw new DomainException('invalid_form'); }
    $values = [];
    foreach (explode('&', $body) as $pair) {
        if (preg_match('/%(?![a-f0-9]{2})/i', $pair)) { throw new DomainException('invalid_form'); }
        [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
        $key = urldecode($key);
        if (!in_array($key, ['csrf','action','login','role','id','revision','enabled','server','confirmed'], true) || isset($values[$key])) {
            throw new DomainException('invalid_form');
        }
        $values[$key] = urldecode($value);
    }
    return $values;
}
try {
    $directory = (string) getenv('INDY_NEXT_FIXTURE');
    $access = new AccessStore($directory);
    $headers = ListenerIdentity::headers(getallheaders());
    [$actor, $origin] = ListenerIdentity::resolve($access, $_SERVER, $headers,
        (string) getenv('INDY_NEXT_PORT'), getenv('INDY_FAMILY_HOST') ?: null);
    $method = $_SERVER['REQUEST_METHOD'];
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $runtime = new ServerRuntime($directory);
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        if ($method === 'POST' && in_array($path, ['/api/actions','/api/refresh'], true)) {
            $form = formBody();
            $access->checkWrite($actor, $origin, $headers, $form['csrf'] ?? '');
            $keys = $path === '/api/refresh' ? ['csrf'] : ['csrf','server','action'];
            if (($form['action'] ?? '') === 'stop') {
                $keys[] = 'confirmed';
                if (($form['confirmed'] ?? '') !== ($form['server'] ?? '')) { throw new DomainException('confirmation_required'); }
            }
            if (count($form) !== count($keys) || array_diff(array_keys($form), $keys)) { throw new DomainException('invalid_form'); }
            if ($path === '/api/refresh') { $runtime->refresh($actor); $result=['accepted'=>true]; }
            else { $result=['operation'=>$runtime->request($actor, $form['server'], $form['action'])]; }
            http_response_code(202);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($result, JSON_THROW_ON_ERROR);
            exit;
        }
        if ($method !== 'POST' || $path !== '/access') {
            header('Allow: GET, HEAD');
            http_response_code(405);
            exit;
        }
        $access->authorize($actor, 'access.manage');
        $form = formBody();
        $access->checkWrite($actor, $origin, $headers, $form['csrf'] ?? '');
        $action = $form['action'] ?? '';
        $fields = match ($action) {
            'create' => ['action','csrf','login','role'],
            'toggle' => ['action','csrf','id','revision','enabled'],
            'role' => ['action','csrf','id','revision','role'],
            default => throw new DomainException('invalid_form'),
        };
        if (count($form) !== count($fields) || array_diff(array_keys($form), $fields)) {
            throw new DomainException('invalid_form');
        }
        $role = isset($form['role']) ? Role::tryFrom($form['role']) : null;
        if (isset($form['role']) && $role === null) { throw new DomainException('invalid_form'); }
        if ($action === 'create') {
            $access->createGrant($actor, $form['login'], $role);
        } else {
            if (!preg_match('/^[a-f0-9]{32}$/D', $form['id']) || !preg_match('/^[1-9][0-9]{0,8}$/D', $form['revision'])
                || ($action === 'toggle' && !in_array($form['enabled'], ['0','1'], true))) {
                throw new DomainException('invalid_form');
            }
            $access->changeGrant($actor, $form['id'], (int) $form['revision'],
                $action === 'toggle' ? $form['enabled'] === '1' : null, $role);
        }
        header('Location: /access?saved=1', true, 303);
        exit;
    }
    if ($path === '/health') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['mode' => 'fixture', 'native_enabled' => $runtime->nativeEnabled(), 'instance' => basename($directory),
            'build' => getenv('INDY_APP_BUILD') ?: 'development'], JSON_THROW_ON_ERROR);
    } elseif ($path === '/api/session') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['id' => $actor->id, 'role' => $actor->role->value, 'servers' => $actor->servers,
            'csrf' => $access->token($actor, $origin)], JSON_THROW_ON_ERROR);
    } elseif ($path === '/api/access') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['grants' => $access->view($actor)], JSON_THROW_ON_ERROR);
    } elseif ($path === '/api/status') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['mode'=>'fixture', 'servers'=>$runtime->snapshot($actor)], JSON_THROW_ON_ERROR);
    } elseif ($path === '/access') {
        $grants = $access->view($actor);
        $token = $access->token($actor, $origin);
        header('Content-Type: text/html; charset=utf-8');
        require dirname(__DIR__).'/templates/access.php';
    } elseif ($path === '/' || preg_match('#^/servers/(enshrouded|aska)$#D', $path, $match)) {
        $servers = $runtime->snapshot($actor);
        $selected = $path === '/' ? null : $match[1];
        if ($selected !== null && !in_array($selected, $actor->servers, true)) { throw new DomainException('access_denied'); }
        $token = $access->token($actor, $origin);
        header('Content-Type: text/html; charset=utf-8');
        require dirname(__DIR__).'/templates/servers.php';
    } else {
        http_response_code(404);
    }
} catch (DomainException $error) {
    $messages = [
        'identity_exists' => 'Diese Identität ist bereits angelegt.',
        'invalid_identity' => 'Bitte gib den persönlichen Tailscale-Anmeldenamen ein.',
        'grant_changed' => 'Der Zugang wurde inzwischen geändert. Bitte lade die Seite neu.',
        'own_access_protected' => 'Dein eigener Zugang bleibt geschützt.',
        'invalid_form' => 'Bitte prüfe die Eingaben und lade die Seite neu.',
        'invalid_csrf' => 'Die Anfrage ist nicht mehr gültig. Bitte lade die Seite neu und versuche es erneut.',
        'server_busy' => 'Der Serverstatus hat sich geändert oder eine Aktion läuft. Bitte prüfe den aktuellen Status.',
        'confirmation_required' => 'Bitte bestätige das Stoppen des gewählten Servers.',
    ];
    $message = $messages[$error->getMessage()] ?? 'Bitte wende dich an die Verwaltung.';
    http_response_code(match ($error->getMessage()) {
        'grant_changed','server_busy' => 409,
        'invalid_form', 'invalid_identity', 'identity_exists' => 400,
        default => 403,
    });
    if (str_starts_with($path ?? '', '/api/')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error'=>$error->getMessage(),'message'=>$message], JSON_THROW_ON_ERROR);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        require dirname(__DIR__).'/templates/access-error.php';
    }
} catch (\Throwable) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Isolierter Testmodus nicht verfügbar.';
}
