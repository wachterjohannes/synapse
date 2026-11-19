<?php

namespace App\Tests\Security;

use App\Security\AuthorizationServerUnavailable;
use App\Security\BearerChallenge;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\InsufficientAuthenticationException;

final class BearerChallengeTest extends TestCase
{
    private const METADATA = 'Bearer resource_metadata="https://brain.example:8443/.well-known/oauth-protected-resource", scope="brain:read"';

    private function challenge(string $audience = 'https://brain.example:8443/mcp'): BearerChallenge
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/.well-known/oauth-protected-resource');

        return new BearerChallenge($urls, $audience);
    }

    public function testNoTokenGives401WithTheMetadataAddress(): void
    {
        $response = $this->challenge()->start(new Request());

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(self::METADATA, $response->headers->get('WWW-Authenticate'));
        self::assertSame('unauthorized', json_decode((string) $response->getContent(), true)['error']);
    }

    public function testAStartWithAnExceptionStillGives401(): void
    {
        self::assertSame(401, $this->challenge()->start(new Request(), new InsufficientAuthenticationException())->getStatusCode());
    }

    public function testAnInvalidTokenGives401WithAnErrorCode(): void
    {
        $response = $this->challenge()->onAuthenticationFailure(new Request(), new BadCredentialsException());

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(self::METADATA.', error="invalid_token"', $response->headers->get('WWW-Authenticate'));
    }

    public function testAnAuthorizationServerThatIsDownGives503WithRetryAfterAndNoChallenge(): void
    {
        $response = $this->challenge()->onAuthenticationFailure(new Request(), new AuthorizationServerUnavailable());

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('5', $response->headers->get('Retry-After'));
        self::assertFalse($response->headers->has('WWW-Authenticate'), 'The token may be fine, so the client must not be told to get a new one.');
        self::assertSame('temporarily_unavailable', json_decode((string) $response->getContent(), true)['error']);
    }

    public function testAValidTokenWithoutTheScopeGives403(): void
    {
        $response = $this->challenge()->handle(new Request(), new AccessDeniedException());

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(self::METADATA.', error="insufficient_scope"', $response->headers->get('WWW-Authenticate'));
    }

    public function testTheAddressComesFromTheAudienceAndNotFromTheRequestHost(): void
    {
        $request = Request::create('http://evil.example/mcp');
        $header = (string) $this->challenge()->start($request)->headers->get('WWW-Authenticate');

        self::assertStringContainsString('https://brain.example:8443/', $header);
        self::assertStringNotContainsString('evil.example', $header);
    }

    public function testAnAudienceWithoutPortOrSchemeFallsBackToDefaults(): void
    {
        $header = (string) $this->challenge('/mcp')->start(new Request())->headers->get('WWW-Authenticate');

        self::assertStringContainsString('resource_metadata="http://localhost/.well-known/oauth-protected-resource"', $header);
    }
}
