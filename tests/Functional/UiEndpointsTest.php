<?php

namespace App\Tests\Functional;

use App\Tests\Support\MockResponses;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The paid endpoints of the page. Every case here is turned away before a model is called, and the HTTP client
 * of the test kernel fails loudly on any request, so a call that slipped through would show up as a failure.
 */
final class UiEndpointsTest extends BrowserTestCase
{
    public function testAPostWithoutTheSameOriginMarkIsRefused(): void
    {
        $browser = $this->browser(self::basicAuth());
        $browser->request('POST', '/ui/capture', ['text' => 'hello']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], MockResponses::requests());
    }

    public function testTheUiHeaderIsAnotherWayToProveTheOrigin(): void
    {
        $browser = $this->browser(self::basicAuth() + ['HTTP_X_SYNAPSE_UI' => '1']);
        $browser->request('POST', '/ui/capture', ['text' => str_repeat('a', 8001)]);

        self::assertResponseIsSuccessful();
    }

    public function testACrossSitePostIsRefused(): void
    {
        $browser = $this->browser(self::basicAuth() + ['HTTP_SEC_FETCH_SITE' => 'cross-site']);
        $browser->request('POST', '/ui/ask', ['question' => 'hello']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnEmptyCaptureAsksForText(): void
    {
        $browser = $this->browser(self::basicAuth() + self::fromThePage());
        $browser->request('POST', '/ui/capture', ['text' => '   ']);

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/vnd.turbo-stream.html', (string) $browser->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('Write something first.', (string) $browser->getResponse()->getContent());
    }

    public function testACaptureOverTheLimitSaysSo(): void
    {
        $browser = $this->browser(self::basicAuth() + self::fromThePage());
        $browser->request('POST', '/ui/capture', ['text' => str_repeat('a', 8001)]);

        self::assertStringContainsString('A note can have 8000 characters, yours has 8001.', (string) $browser->getResponse()->getContent());
        self::assertSame([], MockResponses::requests());
    }

    public function testACaptureAtTheLimitIsNotTooLong(): void
    {
        $browser = $this->browser(self::basicAuth() + self::fromThePage());
        $browser->request('POST', '/ui/capture', ['text' => str_repeat('a', 8000)]);

        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString('too long', $content);
        self::assertStringContainsString('That did not work', $content, 'The agent was called, and the unreachable provider is reported.');
    }

    public function testAnEmptyQuestionGetsACardWithoutAStream(): void
    {
        $browser = $this->browser(self::basicAuth() + self::fromThePage());
        $browser->request('POST', '/ui/ask', ['question' => '']);

        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('Ask something first.', $content);
        self::assertStringNotContainsString('turbo-stream-source', $content);
    }

    public function testAQuestionOverTheLimitSaysSo(): void
    {
        $browser = $this->browser(self::basicAuth() + self::fromThePage());
        $browser->request('POST', '/ui/ask', ['question' => str_repeat('a', 1001)]);

        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('It can have 1000 characters, yours has 1001.', $content);
        self::assertStringNotContainsString('turbo-stream-source', $content);
    }

    public function testAValidQuestionGetsACardThatOpensAStream(): void
    {
        $browser = $this->browser(self::basicAuth() + self::fromThePage());
        $browser->request('POST', '/ui/ask', ['question' => 'What is MCP?']);

        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('turbo-stream-source', $content);
        self::assertMatchesRegularExpression('#/ui/ask/[0-9a-f]{8}/stream\?q=What%20is%20MCP\?#', $content);
    }

    public function testAStreamWithoutAValidQuestionIs400(): void
    {
        $browser = $this->browser(self::basicAuth() + self::fromThePage());
        $browser->request('GET', '/ui/ask/0123abcd/stream?q=');

        self::assertResponseStatusCodeSame(400);
    }

    public function testASecondRequestForTheSameStreamIsAnswered204(): void
    {
        $browser = $this->browser(self::basicAuth() + self::fromThePage());
        $cache = static::getContainer()->get('cache.app');
        $id = bin2hex(random_bytes(4));
        $cache->get('ui_stream_'.$id, static fn (): bool => true);

        $browser->request('GET', '/ui/ask/'.$id.'/stream?q=hello');

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $browser->getResponse()->getContent());
        self::assertSame([], MockResponses::requests());
    }

    public function testAStreamIdMustBeEightHexCharacters(): void
    {
        $browser = $this->browser(self::basicAuth() + self::fromThePage());
        $browser->request('GET', '/ui/ask/not-hex!/stream?q=hello');

        self::assertResponseStatusCodeSame(404);
    }

    public function testSpeakWithoutTextIs400(): void
    {
        $browser = $this->browser(self::basicAuth() + self::fromThePage());
        $browser->jsonRequest('POST', '/ui/speak', ['text' => ' ']);

        self::assertResponseStatusCodeSame(400);
    }

    public function testSpeakOverTheLimitIs413(): void
    {
        $browser = $this->browser(self::basicAuth() + self::fromThePage());
        $browser->jsonRequest('POST', '/ui/speak', ['text' => str_repeat('a', 4001)]);

        self::assertResponseStatusCodeSame(413);
        self::assertStringContainsString('The limit is 4000 characters.', (string) $browser->getResponse()->getContent());
        self::assertSame([], MockResponses::requests());
    }

    public function testTranscribeWithoutAFileIs400(): void
    {
        $browser = $this->browser(self::basicAuth() + self::fromThePage());
        $browser->request('POST', '/ui/transcribe');

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('No audio.', (string) $browser->getResponse()->getContent());
    }

    public function testTranscribeRefusesAFileThatIsNotAudio(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'synapse-audio');
        file_put_contents($file, "just text, no audio\n");
        try {
            $browser = $this->browser(self::basicAuth() + self::fromThePage());
            $browser->request('POST', '/ui/transcribe', files: ['audio' => new UploadedFile($file, 'a.webm', 'audio/webm', null, true)]);

            self::assertResponseStatusCodeSame(415);
            self::assertSame([], MockResponses::requests());
        } finally {
            @unlink($file);
        }
    }

    public function testTranscribeRefusesARecordingOverTenMegabytes(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'synapse-audio');
        $handle = fopen($file, 'w');
        ftruncate($handle, 10 * 1024 * 1024 + 1);
        fclose($handle);
        try {
            $browser = $this->browser(self::basicAuth() + self::fromThePage());
            $browser->request('POST', '/ui/transcribe', files: ['audio' => new UploadedFile($file, 'a.webm', 'audio/webm', null, true)]);

            self::assertResponseStatusCodeSame(413);
            self::assertStringContainsString('The limit is 10 MB.', (string) $browser->getResponse()->getContent());
        } finally {
            @unlink($file);
        }
    }

    public function testTheEndpointsAreClosedWithoutALogin(): void
    {
        $browser = $this->browser(self::fromThePage());
        $browser->request('POST', '/ui/capture', ['text' => 'x']);

        self::assertResponseRedirects('/login');
    }
}
