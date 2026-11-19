<?php

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Answers an MCP client that is not (yet) let in, and tells it where to look next. The WWW-Authenticate header points
 * to the protected resource metadata, and that document names the authorization server (RFC 9728). The three cases
 * are the ones RFC 6750 and the MCP specification tell apart:
 *
 * - no token or a token that is not valid: 401
 * - a valid token without the scope this brain asks for: 403 with error="insufficient_scope"
 * - an authorization server that cannot be reached: 503, because the token may well be fine
 */
final class BearerChallenge implements AuthenticationEntryPointInterface, AuthenticationFailureHandlerInterface, AccessDeniedHandlerInterface
{
    /** The scope that opens the endpoint. The write scope includes it through the role hierarchy. */
    private const SCOPE = 'brain:read';

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        #[Autowire('%env(OAUTH_AUDIENCE)%')]
        private readonly string $audience,
    ) {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->respond(Response::HTTP_UNAUTHORIZED, 'unauthorized', 'A bearer token is required.');
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($exception instanceof AuthorizationServerUnavailable) {
            return new JsonResponse(
                ['error' => 'temporarily_unavailable', 'error_description' => 'The authorization server could not be reached. Try again later.'],
                Response::HTTP_SERVICE_UNAVAILABLE,
                ['Retry-After' => '5'],
            );
        }

        return $this->respond(Response::HTTP_UNAUTHORIZED, 'invalid_token', 'The token is not valid for this brain.', 'invalid_token');
    }

    public function handle(Request $request, AccessDeniedException $accessDeniedException): Response
    {
        return $this->respond(Response::HTTP_FORBIDDEN, 'insufficient_scope', 'The token needs the scope '.self::SCOPE.' or brain:write.', 'insufficient_scope');
    }

    private function respond(int $status, string $error, string $description, ?string $code = null): Response
    {
        // The address comes from OAUTH_AUDIENCE, not from the request: the token has to be meant for exactly this address,
        // so the client must be sent there, whatever host name or proxy the request came through.
        $parts = parse_url($this->audience);
        $origin = sprintf('%s://%s%s', $parts['scheme'] ?? 'http', $parts['host'] ?? 'localhost', isset($parts['port']) ? ':'.$parts['port'] : '');
        $header = sprintf('Bearer resource_metadata="%s%s", scope="%s"', $origin, $this->urlGenerator->generate('oauth_protected_resource'), self::SCOPE);
        if (null !== $code) {
            $header .= sprintf(', error="%s"', $code);
        }

        return new JsonResponse(['error' => $error, 'error_description' => $description], $status, ['WWW-Authenticate' => $header]);
    }
}
