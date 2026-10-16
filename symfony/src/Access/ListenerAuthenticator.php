<?php
declare(strict_types=1);
namespace App\Access;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

final class ListenerAuthenticator extends AbstractAuthenticator
{
    public function supports(Request $request): ?bool { return true; }
    public function authenticate(Request $request): Passport
    {
        $principal = $request->attributes->get('_indy_principal');
        if (!$principal instanceof Principal) { throw new AuthenticationException('Bestätigte Listeneridentität fehlt.'); }
        return new SelfValidatingPassport(new UserBadge($principal->id, fn () => $principal));
    }
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response { return null; }
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(['error' => 'Keine bestätigte persönliche Identität vorhanden.'], 403);
    }
}
