<?php
declare(strict_types=1);
namespace App\Access;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class PermissionVoter extends Voter
{
    public function __construct(private readonly AccessPolicy $policy) {}
    protected function supports(string $attribute, mixed $subject): bool { return str_contains($attribute, '.'); }
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $principal = $token->getUser();
        if (!$principal instanceof Principal || ($subject !== null && !is_string($subject))) { return false; }
        try { $this->policy->require($principal, $attribute, $subject); return true; }
        catch (AccessDeniedException) { return false; }
    }
}
