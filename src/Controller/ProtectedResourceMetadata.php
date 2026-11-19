<?php

namespace App\Controller;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Tells an MCP client which authorization server issues tokens for this brain (RFC 9728).
 *
 * The document is served at the root of /.well-known/ and, as RFC 9728 asks for a resource with a path,
 * below it with the path of the resource: /.well-known/oauth-protected-resource/mcp. A client may try either.
 */
final class ProtectedResourceMetadata
{
    public function __construct(
        #[Autowire('%env(OAUTH_AUDIENCE)%')]
        private readonly string $resource,
        #[Autowire('%env(OAUTH_ISSUER)%')]
        private readonly string $issuer,
    ) {
    }

    #[Route('/.well-known/oauth-protected-resource', name: 'oauth_protected_resource', methods: 'GET')]
    #[Route('/.well-known/oauth-protected-resource/{path}', name: 'oauth_protected_resource_path', requirements: ['path' => '.+'], methods: 'GET')]
    public function __invoke(string $path = ''): JsonResponse
    {
        // The path variant exists for the path of this brain only
        if ('' !== $path && '/'.$path !== (parse_url($this->resource, \PHP_URL_PATH) ?: '')) {
            throw new NotFoundHttpException();
        }

        $response = new JsonResponse([
            'resource' => $this->resource,
            'authorization_servers' => [$this->issuer],
            'scopes_supported' => ['brain:read', 'brain:write'],
            'bearer_methods_supported' => ['header'],
        ]);

        // Slashes stay as they are, so the address reads the way a client expects it
        return $response->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | \JSON_UNESCAPED_SLASHES);
    }
}
