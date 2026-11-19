<?php

namespace App\Tests\Functional;

use App\Tests\Support\IndexFixture;
use App\Tests\Support\MockResponses;

/**
 * What a fresh install sees: a setup page while the password is empty, a banner while something else is missing,
 * and plain words on the answer card instead of an exception.
 */
final class SetupPagesTest extends BrowserTestCase
{
    public function testTheLoginPageIsTheSetupPageWhileThePasswordIsEmpty(): void
    {
        $this->withEnv('UI_PASSWORD', '', function (): void {
            $browser = $this->browser();
            $browser->request('GET', '/login');

            self::assertResponseStatusCodeSame(503);
            self::assertSelectorTextContains('h1', 'Almost there');
            self::assertSelectorCount(10, '[data-check]');
            self::assertSelectorTextContains('[data-check="error"]', 'UI_PASSWORD');
            self::assertSelectorTextContains('[data-check="error"] code', 'UI_PASSWORD=');
            self::assertSelectorNotExists('form');
            self::assertResponseHasHeader('Content-Security-Policy');
        });
    }

    public function testTheStartPageLeadsToTheSetupPageWhileThePasswordIsEmpty(): void
    {
        $this->withEnv('UI_PASSWORD', '', function (): void {
            $browser = $this->browser();
            $browser->request('GET', '/');
            self::assertResponseRedirects('/login');
            $browser->followRedirect();

            self::assertResponseStatusCodeSame(503);
            self::assertSelectorTextContains('h1', 'Almost there');
        });
    }

    public function testTheSetupPageShowsNoKeyAndNoPassword(): void
    {
        $this->withEnv('UI_PASSWORD', '', function (): void {
            $browser = $this->browser();
            $browser->request('GET', '/login');

            $html = (string) $browser->getResponse()->getContent();
            foreach (['dummy-cohere-key', 'dummy-elevenlabs-key', self::PASSWORD] as $secret) {
                self::assertStringNotContainsString($secret, $html);
            }
        });
    }

    public function testASetupPageAlsoShowsWhatIsStillMissing(): void
    {
        $this->withEnv('UI_PASSWORD', '', function (): void {
            $this->withEnv('COHERE_API_KEY', '', function (): void {
                $browser = $this->browser();
                $browser->request('GET', '/login');

                self::assertSelectorCount(2, '[data-check="error"]');
                self::assertSelectorTextContains('body', 'COHERE_API_KEY=<your key>');
            });
        });
    }

    public function testNothingIsShownWhenEverythingIsOk(): void
    {
        $browser = $this->browser(self::basicAuth());
        $browser->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-setup-banner]');
    }

    public function testTheBannerShowsTheFirstProblemsWithTheirFix(): void
    {
        $this->withEnv('COHERE_API_KEY', '', function (): void {
            IndexFixture::remove((string) $_SERVER['BRAIN_DB']);
            $this->withEnv('ELEVENLABS_API_KEY', '', function (): void {
                $browser = $this->browser(self::basicAuth());
                $browser->request('GET', '/');

                self::assertResponseIsSuccessful();
                self::assertSelectorCount(1, '[data-setup-banner]');
                self::assertSelectorTextContains('[data-setup-banner]', 'COHERE_API_KEY');
                self::assertSelectorTextContains('[data-setup-banner]', 'Add COHERE_API_KEY=<your key> to .env.local.');
                self::assertSelectorTextContains('[data-setup-banner]', 'php bin/console ai:store:setup brain && php bin/console ai:store:index brain');
                self::assertSelectorNotExists('[data-setup-banner] p');
            });
        });
    }

    public function testTheBannerShowsThreeProblemsAndCountsTheRest(): void
    {
        IndexFixture::remove((string) $_SERVER['BRAIN_DB']);
        $this->withEnv('SYNAPSE_PROVIDER', 'huggingface', function (): void {
            $this->withEnv('COHERE_API_KEY', '', function (): void {
                $this->withEnv('ELEVENLABS_API_KEY', '', function (): void {
                    $browser = $this->browser(self::basicAuth());
                    $browser->request('GET', '/');

                    self::assertSelectorCount(3, '[data-setup-banner] li');
                    self::assertSelectorTextContains('[data-setup-banner] p', 'And 1 more.');
                    self::assertSelectorTextContains('[data-setup-banner]', 'HUGGINGFACE_API_KEY');
                });
            });
        });
    }

    public function testTheBannerNeedsALogin(): void
    {
        $this->withEnv('COHERE_API_KEY', '', function (): void {
            $browser = $this->browser();
            $browser->request('GET', '/');

            self::assertResponseRedirects('/login');
            self::assertStringNotContainsString('COHERE_API_KEY', (string) $browser->getResponse()->getContent());
        });
    }

    public function testAQuestionWithoutAKeyGetsACardThatSaysSo(): void
    {
        $this->withEnv('COHERE_API_KEY', '', function (): void {
            $browser = $this->browser(self::basicAuth() + self::fromThePage());
            $browser->request('POST', '/ui/ask', ['question' => 'What is MCP?']);

            $content = (string) $browser->getResponse()->getContent();
            self::assertStringContainsString('COHERE_API_KEY is empty. Put it into .env.local', $content);
            self::assertStringNotContainsString('turbo-stream-source', $content);
        });
    }

    public function testAQuestionWithoutAnIndexGetsACardThatNamesTheCommands(): void
    {
        IndexFixture::remove((string) $_SERVER['BRAIN_DB']);
        $browser = $this->browser(self::basicAuth() + self::fromThePage());
        $browser->request('POST', '/ui/ask', ['question' => 'What is MCP?']);

        $content = html_entity_decode((string) $browser->getResponse()->getContent());
        self::assertStringContainsString('php bin/console ai:store:setup brain && php bin/console ai:store:index brain', $content);
        self::assertStringNotContainsString('turbo-stream-source', $content);
        self::assertSame([], MockResponses::requests());
    }

    public function testTheStreamOfAQuestionWithoutAnIndexIsRefused(): void
    {
        IndexFixture::remove((string) $_SERVER['BRAIN_DB']);
        $browser = $this->browser(self::basicAuth() + self::fromThePage());
        $browser->request('GET', '/ui/ask/0123abcd/stream?q=hello');

        self::assertResponseStatusCodeSame(400);
        self::assertSame([], MockResponses::requests());
    }
}
