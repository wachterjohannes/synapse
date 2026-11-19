<?php

namespace App\Tests\Security;

use App\Security\AuthorizationServerUnavailable;
use App\Security\BrainUser;
use App\Security\IntrospectionTokenHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;

final class IntrospectionTokenHandlerTest extends TestCase
{
    private const AUDIENCE = 'http://localhost/mcp';

    /**
     * @param array<string, mixed>|MockResponse $answer
     */
    private function handler(array|MockResponse $answer, ?\Closure $inspect = null): IntrospectionTokenHandler
    {
        $response = $answer instanceof MockResponse ? $answer : new MockResponse(json_encode($answer, \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type: application/json']]);
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use ($response, $inspect): MockResponse {
            if (null !== $inspect) {
                $inspect($method, $url, $options);
            }

            return $response;
        });

        return new IntrospectionTokenHandler($client, 'http://as.test/introspect', 'brain-server', 'secret', self::AUDIENCE);
    }

    /**
     * @return list<string>
     */
    private function rolesOf(IntrospectionTokenHandler $handler): array
    {
        $user = $handler->getUserBadgeFrom('token')->getUserLoader()();
        self::assertInstanceOf(BrainUser::class, $user);

        return $user->getRoles();
    }

    public function testAnActiveTokenForThisBrainGivesTheRolesOfItsScopes(): void
    {
        $handler = $this->handler(['active' => true, 'aud' => self::AUDIENCE, 'sub' => 'alice', 'scope' => 'brain:read brain:write openid']);
        $badge = $handler->getUserBadgeFrom('token');

        self::assertSame('alice', $badge->getUserIdentifier());
        self::assertSame(['ROLE_BRAIN_READ', 'ROLE_BRAIN_WRITE'], $this->rolesOf($handler));
    }

    public function testTheIntrospectionRequestCarriesTheTokenTheClientAndATimeout(): void
    {
        $seen = [];
        $handler = $this->handler(['active' => true, 'aud' => self::AUDIENCE, 'sub' => 'a', 'scope' => ''], static function (string $method, string $url, array $options) use (&$seen): void {
            $seen = [$method, $url, $options];
        });
        $handler->getUserBadgeFrom('the-token');

        self::assertSame('POST', $seen[0]);
        self::assertSame('http://as.test/introspect', $seen[1]);
        self::assertStringContainsString('token=the-token', $seen[2]['body']);
        self::assertContains('Authorization: Basic '.base64_encode('brain-server:secret'), $seen[2]['headers']);
        self::assertEquals(5, $seen[2]['timeout']);
    }

    public function testTheAudienceMayBeAList(): void
    {
        $handler = $this->handler(['active' => true, 'aud' => ['http://other/mcp', self::AUDIENCE], 'sub' => 'a', 'scope' => 'brain:read']);

        self::assertSame(['ROLE_BRAIN_READ'], $this->rolesOf($handler));
    }

    public function testScopesAreCaseSensitiveAndUnknownOnesAreDropped(): void
    {
        $handler = $this->handler(['active' => true, 'aud' => self::AUDIENCE, 'sub' => 'a', 'scope' => 'Brain:Write brain:read brain:read admin']);

        self::assertSame(['ROLE_BRAIN_READ'], $this->rolesOf($handler));
    }

    public function testAnInactiveTokenIsRefused(): void
    {
        $this->expectException(BadCredentialsException::class);

        $this->handler(['active' => false])->getUserBadgeFrom('token');
    }

    public function testActiveMustBeExactlyTrue(): void
    {
        $this->expectException(BadCredentialsException::class);

        $this->handler(['active' => 'true', 'aud' => self::AUDIENCE, 'sub' => 'a', 'scope' => 'brain:read'])->getUserBadgeFrom('token');
    }

    public function testATokenForAnotherAudienceIsRefused(): void
    {
        $this->expectException(BadCredentialsException::class);
        $this->expectExceptionMessage('not issued for this brain');

        $this->handler(['active' => true, 'aud' => 'http://other/mcp', 'sub' => 'a', 'scope' => 'brain:read'])->getUserBadgeFrom('token');
    }

    public function testATokenWithoutAudienceIsRefused(): void
    {
        $this->expectException(BadCredentialsException::class);

        $this->handler(['active' => true, 'sub' => 'a', 'scope' => 'brain:read'])->getUserBadgeFrom('token');
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unusableClaims(): iterable
    {
        yield 'no subject' => [['scope' => 'brain:read']];
        yield 'empty subject' => [['sub' => '', 'scope' => 'brain:read']];
        yield 'numeric subject' => [['sub' => 5, 'scope' => 'brain:read']];
        yield 'scope as list' => [['sub' => 'a', 'scope' => ['brain:read']]];
    }

    /**
     * @param array<string, mixed> $claims
     */
    #[DataProvider('unusableClaims')]
    public function testUnusableClaimsAreRefused(array $claims): void
    {
        $this->expectException(BadCredentialsException::class);

        $this->handler(['active' => true, 'aud' => self::AUDIENCE] + $claims)->getUserBadgeFrom('token');
    }

    public function testAServerThatIsDownIsNotTheSameAsAnInvalidToken(): void
    {
        $handler = $this->handler(new MockResponse('', ['error' => 'connection refused']));

        $this->expectException(AuthorizationServerUnavailable::class);
        $handler->getUserBadgeFrom('token');
    }

    public function testAnErrorStatusOfTheServerCountsAsUnavailable(): void
    {
        $this->expectException(AuthorizationServerUnavailable::class);

        $this->handler(new MockResponse('{"error":"boom"}', ['http_code' => 500]))->getUserBadgeFrom('token');
    }

    public function testAnAnswerThatIsNotJsonCountsAsUnavailable(): void
    {
        $this->expectException(AuthorizationServerUnavailable::class);

        $this->handler(new MockResponse('<html>gateway</html>', ['response_headers' => ['content-type: text/html']]))->getUserBadgeFrom('token');
    }

    public function testTheTransportErrorIsKeptAsThePreviousException(): void
    {
        try {
            $this->handler(new MockResponse('', ['error' => 'connection refused']))->getUserBadgeFrom('token');
            self::fail('Expected an exception.');
        } catch (AuthorizationServerUnavailable $e) {
            self::assertInstanceOf(TransportException::class, $e->getPrevious());
        }
    }
}
