<?php

namespace App\Security;

use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * The authorization server did not answer. That says nothing about the token, so the client must not be told
 * that its token is invalid: it should try again later.
 */
final class AuthorizationServerUnavailable extends AuthenticationException
{
    public function getMessageKey(): string
    {
        return 'The authorization server could not be reached.';
    }
}
