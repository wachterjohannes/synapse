<?php

namespace App\Tests\Functional;

use App\Tests\Support\MockResponses;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The start page and the cards that appear on it: layout hooks, the small helpers, and what a reply looks like.
 */
final class UiPagesTest extends BrowserTestCase
{
    public function testTheStartPageHasCaptureAndAskNextToEachOtherAndTheAnswersBelow(): void
    {
        $browser = $this->browser(self::basicAuth());
        $browser->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '.lg\:grid-cols-2 form#capture-form');
        self::assertSelectorCount(1, '.lg\:grid-cols-2 form#ask-form');
        self::assertSelectorExists('#answers');
        self::assertSelectorNotExists('.lg\:grid-cols-2 #answers');
    }

    public function testEveryFormSubmitsWithCtrlOrCmdEnter(): void
    {
        $browser = $this->browser(self::basicAuth());
        $browser->request('GET', '/');

        self::assertSelectorCount(2, 'form[data-controller~="shortcut"] textarea[data-action="keydown->shortcut#submit"]');
    }

    public function testTheMicrophoneButtonCarriesTheRecordingIndicator(): void
    {
        $browser = $this->browser(self::basicAuth());
        $browser->request('GET', '/');

        self::assertSelectorCount(2, 'button[data-recorder-target="button"] .pulse-dot');
        self::assertSelectorCount(2, 'button[data-recorder-target="button"] svg');
    }

    public function testThePageLoadsNothingFromOutsideAndEveryScriptCarriesTheNonce(): void
    {
        $browser = $this->browser(self::basicAuth());
        $crawler = $browser->request('GET', '/');
        $html = (string) $browser->getResponse()->getContent();

        self::assertDoesNotMatchRegularExpression('#(src|href)="https?://#', $html);
        self::assertSame(0, $crawler->filter('script:not([nonce])')->count());
        self::assertStringContainsString("script-src 'self' 'nonce-", (string) $browser->getResponse()->headers->get('Content-Security-Policy'));
        self::assertStringNotContainsString('unsafe-eval', (string) $browser->getResponse()->headers->get('Content-Security-Policy'));
    }

    public function testTheStylesheetKnowsADarkMode(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/assets/styles/app.css');

        self::assertStringContainsString('prefers-color-scheme: dark', $css);
    }

    public function testAnAnswerCardHasASkeletonAndTheLivePartTheStreamFills(): void
    {
        $browser = $this->browser(self::basicAuth() + self::fromThePage());
        $browser->request('POST', '/ui/ask', ['question' => 'What is MCP?']);

        $content = (string) $browser->getResponse()->getContent();
        self::assertMatchesRegularExpression('#id="answer-([0-9a-f]{8})-wait"#', $content);
        self::assertMatchesRegularExpression('#id="answer-([0-9a-f]{8})-live"#', $content);
        self::assertStringContainsString('data-controller="speak copy"', $content);
    }

    public function testAFiledCaptureShowsAMarkdownReplyAndAToast(): void
    {
        MockResponses::queue(new MockResponse(json_encode([
            'id' => 'x',
            'finish_reason' => 'COMPLETE',
            'message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Saved **inbox/x.md**.']]],
            'usage' => ['tokens' => ['input_tokens' => 1, 'output_tokens' => 1]],
        ], \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]));
        $browser = $this->browser(self::basicAuth() + self::fromThePage());
        $browser->request('POST', '/ui/capture', ['text' => 'A thought.']);

        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('<strong>inbox/x.md</strong>', $content);
        self::assertStringContainsString('target="toasts"', $content);
        self::assertStringContainsString('data-toast-reset-value="capture-form"', $content);
    }

    public function testACaptureThatDidNotWorkHasNoToastAndKeepsTheText(): void
    {
        $browser = $this->browser(self::basicAuth() + self::fromThePage());
        $browser->request('POST', '/ui/capture', ['text' => '   ']);

        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('Write something first.', $content);
        self::assertStringNotContainsString('toasts', $content);
    }

    public function testAReplyThatAnAgentWroteCannotInjectHtml(): void
    {
        MockResponses::queue(new MockResponse(json_encode([
            'id' => 'x',
            'finish_reason' => 'COMPLETE',
            'message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => '<script>alert(1)</script> [a](javascript:alert(1)) </template>']]],
            'usage' => ['tokens' => ['input_tokens' => 1, 'output_tokens' => 1]],
        ], \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]));
        $browser = $this->browser(self::basicAuth() + self::fromThePage());
        $browser->request('POST', '/ui/capture', ['text' => 'A thought.']);

        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString('<script', $content);
        self::assertStringNotContainsString('javascript:', $content);
        self::assertSame(2, substr_count($content, '</template>'), 'Only the two templates of the streams close.');
    }
}
