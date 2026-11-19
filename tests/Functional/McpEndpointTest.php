<?php

namespace App\Tests\Functional;

use App\Tests\Support\MockResponses;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The MCP endpoint and the metadata, with a mocked authorization server in place of the network.
 */
final class McpEndpointTest extends BrowserTestCase
{
    private const AUDIENCE = 'http://localhost/mcp';

    public function testTheMetadataIsServedAtTheRoot(): void
    {
        $browser = $this->browser();
        $browser->request('GET', '/.well-known/oauth-protected-resource');

        self::assertResponseIsSuccessful();
        self::assertSame([
            'resource' => self::AUDIENCE,
            'authorization_servers' => ['http://as.test'],
            'scopes_supported' => ['brain:read', 'brain:write'],
            'bearer_methods_supported' => ['header'],
        ], json_decode((string) $browser->getResponse()->getContent(), true));
        self::assertStringContainsString('"http://localhost/mcp"', (string) $browser->getResponse()->getContent(), 'Slashes stay unescaped.');
    }

    public function testTheMetadataIsServedBelowTheResourcePath(): void
    {
        $browser = $this->browser();
        $browser->request('GET', '/.well-known/oauth-protected-resource/mcp');

        self::assertResponseIsSuccessful();
        self::assertSame(self::AUDIENCE, json_decode((string) $browser->getResponse()->getContent(), true)['resource']);
    }

    public function testTheMetadataForAnotherPathIs404(): void
    {
        $browser = $this->browser();
        $browser->request('GET', '/.well-known/oauth-protected-resource/other');

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheMetadataIsNotAPageAndGetsNoCsp(): void
    {
        $browser = $this->browser();
        $browser->request('GET', '/.well-known/oauth-protected-resource');

        self::assertResponseNotHasHeader('Content-Security-Policy');
    }

    public function testWithoutATokenTheEndpointAnswers401WithTheChallenge(): void
    {
        $browser = $this->browser();
        $browser->jsonRequest('POST', '/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer resource_metadata="http://localhost/.well-known/oauth-protected-resource", scope="brain:read"');
        self::assertResponseNotHasHeader('Content-Security-Policy');
        self::assertSame([], MockResponses::requests(), 'No token, nothing to introspect.');
    }

    public function testAnInactiveTokenAnswers401WithInvalidToken(): void
    {
        MockResponses::queue($this->introspection(['active' => false]));
        $browser = $this->browser();
        $this->call($browser, 'tools/list', token: 'expired');

        self::assertResponseStatusCodeSame(401);
        self::assertStringContainsString('error="invalid_token"', (string) $browser->getResponse()->headers->get('WWW-Authenticate'));
        self::assertSame([['method' => 'POST', 'url' => 'http://as.test/introspect']], MockResponses::requests());
    }

    public function testATokenForAnotherBrainAnswers401(): void
    {
        MockResponses::queue($this->introspection(['active' => true, 'aud' => 'http://other/mcp', 'sub' => 'a', 'scope' => 'brain:read']));
        $browser = $this->browser();
        $this->call($browser, 'tools/list', token: 'foreign');

        self::assertResponseStatusCodeSame(401);
    }

    public function testAnAuthorizationServerThatIsDownAnswers503(): void
    {
        MockResponses::queue(new MockResponse('', ['error' => 'connection refused']));
        $browser = $this->browser();
        $this->call($browser, 'tools/list', token: 'any');

        self::assertResponseStatusCodeSame(503);
        self::assertResponseHeaderSame('Retry-After', '5');
        self::assertResponseNotHasHeader('WWW-Authenticate');
    }

    public function testATokenWithoutAnyBrainScopeAnswers403(): void
    {
        MockResponses::queue($this->introspection(['active' => true, 'aud' => self::AUDIENCE, 'sub' => 'a', 'scope' => 'openid']));
        $browser = $this->browser();
        $this->call($browser, 'tools/list', token: 'noscope');

        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString('error="insufficient_scope"', (string) $browser->getResponse()->headers->get('WWW-Authenticate'));
    }

    public function testAReaderSeesTheToolsAndMayOnlyCallTheReadOnlyOnes(): void
    {
        $browser = $this->browser();
        $tools = $this->tools($browser, 'brain:read');

        self::assertSame(['describe_format', 'list_notes', 'read_note', 'save_to_brain', 'save_to_inbox', 'search_notes'], array_keys($tools));

        $answer = $this->callTool($browser, 'brain:read', 'save_to_inbox', ['slug' => 'idea', 'text' => 'x']);
        self::assertTrue($answer['result']['isError'] ?? false);
        self::assertStringContainsString('may only read', json_encode($answer, \JSON_THROW_ON_ERROR));
        self::assertFileDoesNotExist($_SERVER['BRAIN_DIR'].'/inbox/'.gmdate('Y-m-d').'-idea.md');

        $answer = $this->callTool($browser, 'brain:read', 'describe_format', []);
        self::assertFalse($answer['result']['isError'] ?? false);
    }

    public function testEveryToolIsReadOnlyOrRefusedForAReader(): void
    {
        $browser = $this->browser();
        foreach ($this->tools($browser, 'brain:read') as $name => $tool) {
            $readOnly = true === ($tool['annotations']['readOnlyHint'] ?? false);
            $args = [];
            foreach ($tool['inputSchema']['required'] ?? [] as $argument) {
                $args[$argument] = match ($tool['inputSchema']['properties'][$argument]['type'] ?? 'string') {
                    'array' => [],
                    'integer', 'number' => 1,
                    'boolean' => false,
                    default => 'x',
                };
            }
            $answer = $this->callTool($browser, 'brain:read', $name, $args);
            $refused = str_contains(json_encode($answer, \JSON_THROW_ON_ERROR), 'may only read');

            self::assertSame(!$readOnly, $refused, sprintf('%s: read-only %s, refused %s', $name, var_export($readOnly, true), var_export($refused, true)));
        }
    }

    public function testAWriterCanSaveToTheInbox(): void
    {
        $browser = $this->browser();
        $file = $_SERVER['BRAIN_DIR'].'/inbox/'.gmdate('Y-m-d').'-from-mcp.md';
        @unlink($file);
        try {
            $answer = $this->callTool($browser, 'brain:write', 'save_to_inbox', ['slug' => 'from-mcp', 'text' => "Error: not an error\n"]);

            self::assertFalse($answer['result']['isError'] ?? false, json_encode($answer, \JSON_THROW_ON_ERROR));
            self::assertSame("Error: not an error\n", file_get_contents($file));
        } finally {
            @unlink($file);
        }
    }

    public function testAToolErrorIsAnMcpToolError(): void
    {
        $browser = $this->browser();
        $answer = $this->callTool($browser, 'brain:write', 'save_to_inbox', ['slug' => 'Bad Slug', 'text' => 'x']);

        self::assertTrue($answer['result']['isError'] ?? false);
        self::assertStringContainsString('not a valid slug', json_encode($answer, \JSON_THROW_ON_ERROR));
    }

    public function testANoteThatStartsWithErrorIsNotAnMcpToolError(): void
    {
        $file = $_SERVER['BRAIN_DIR'].'/inbox/error-note.md';
        file_put_contents($file, "Error: this is the text of a note\n");
        try {
            $answer = $this->callTool($this->browser(), 'brain:read', 'read_note', ['path' => 'inbox/error-note.md']);

            self::assertFalse($answer['result']['isError'] ?? false, json_encode($answer, \JSON_THROW_ON_ERROR));
            self::assertStringContainsString('Error: this is the text of a note', json_encode($answer, \JSON_THROW_ON_ERROR));
        } finally {
            @unlink($file);
        }
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function introspection(array $claims): MockResponse
    {
        return new MockResponse(json_encode($claims, \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type: application/json']]);
    }

    private function token(string $scope): MockResponse
    {
        return $this->introspection(['active' => true, 'aud' => self::AUDIENCE, 'sub' => 'alice', 'scope' => $scope]);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function call(KernelBrowser $browser, string $method, array $payload = [], string $token = 'token', ?string $session = null, ?int $id = 1): array
    {
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_ACCEPT' => 'application/json, text/event-stream', 'CONTENT_TYPE' => 'application/json'];
        if (null !== $session) {
            $server['HTTP_MCP_SESSION_ID'] = $session;
        }
        $body = ['jsonrpc' => '2.0', 'method' => $method, 'params' => $payload] + (null === $id ? [] : ['id' => $id]);
        $browser->request('POST', '/mcp', server: $server, content: json_encode($body, \JSON_THROW_ON_ERROR));

        $content = (string) $browser->getResponse()->getContent();
        $json = preg_match('/^data: (.+)$/m', $content, $m) ? $m[1] : $content;

        return json_decode($json, true) ?? [];
    }

    private function session(KernelBrowser $browser, string $scope): string
    {
        MockResponses::queue($this->token($scope), $this->token($scope));
        $this->call($browser, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'phpunit', 'version' => '1']]);
        self::assertResponseIsSuccessful();
        $session = (string) $browser->getResponse()->headers->get('Mcp-Session-Id');
        self::assertNotSame('', $session);
        $this->call($browser, 'notifications/initialized', session: $session, id: null);

        return $session;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function tools(KernelBrowser $browser, string $scope): array
    {
        $session = $this->session($browser, $scope);
        MockResponses::queue($this->token($scope));
        $list = $this->call($browser, 'tools/list', session: $session, id: 2);
        $tools = [];
        foreach ($list['result']['tools'] ?? [] as $tool) {
            $tools[$tool['name']] = $tool;
        }
        ksort($tools);
        $this->sessions[spl_object_id($browser)] = $session;

        return $tools;
    }

    /** @var array<int, string> */
    private array $sessions = [];

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function callTool(KernelBrowser $browser, string $scope, string $tool, array $arguments): array
    {
        $session = $this->sessions[spl_object_id($browser)] ?? $this->session($browser, $scope);
        $this->sessions[spl_object_id($browser)] = $session;
        MockResponses::queue($this->token($scope));

        return $this->call($browser, 'tools/call', ['name' => $tool, 'arguments' => [] === $arguments ? new \stdClass() : $arguments], session: $session, id: 3);
    }
}
