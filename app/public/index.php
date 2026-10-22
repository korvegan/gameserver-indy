<?php
declare(strict_types=1);

require dirname(__DIR__).'/src/Foundation.php';
require dirname(__DIR__).'/src/Access.php';

use IndyNext\AccessStore;
use IndyNext\FixtureStore;
use IndyNext\ListenerIdentity;
use IndyNext\Role;

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

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
        if (!in_array($key, ['csrf','action','login','role','id','revision','enabled'], true) || isset($values[$key])) {
            throw new DomainException('invalid_form');
        }
        $values[$key] = urldecode($value);
    }
    return $values;
}
try {
    $directory = (string) getenv('INDY_NEXT_FIXTURE');
    $store = new FixtureStore($directory);
    $access = new AccessStore($directory);
    $headers = ListenerIdentity::headers(getallheaders());
    [$actor, $origin] = ListenerIdentity::resolve($access, $_SERVER, $headers,
        (string) getenv('INDY_NEXT_PORT'), getenv('INDY_FAMILY_HOST') ?: null);
    $method = $_SERVER['REQUEST_METHOD'];
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (!in_array($method, ['GET', 'HEAD'], true)) {
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
        echo json_encode(['mode' => 'fixture', 'native_enabled' => false, 'instance' => basename($directory),
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
        $servers = array_values(array_filter($store->snapshot(), fn ($s) => in_array($s['id'], $actor->servers, true)));
        echo json_encode(['mode' => 'fixture', 'servers' => $servers], JSON_THROW_ON_ERROR);
    } elseif ($path === '/access') {
        $grants = $access->view($actor);
        $token = $access->token($actor, $origin);
        header('Content-Type: text/html; charset=utf-8');
        require dirname(__DIR__).'/templates/access.php';
    } elseif ($path === '/') {
        header('Content-Type: text/html; charset=utf-8');
        require dirname(__DIR__).'/templates/diagnostics.php';
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
    ];
    $message = $messages[$error->getMessage()] ?? 'Bitte wende dich an die Verwaltung.';
    http_response_code(match ($error->getMessage()) {
        'grant_changed' => 409,
        'invalid_form', 'invalid_identity', 'identity_exists' => 400,
        default => 403,
    });
    header('Content-Type: text/html; charset=utf-8');
    require dirname(__DIR__).'/templates/access-error.php';
} catch (\Throwable) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Isolierter Testmodus nicht verfügbar.';
}
