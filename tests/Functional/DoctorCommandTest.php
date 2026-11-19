<?php

namespace App\Tests\Functional;

use App\Tests\Support\IndexFixture;
use App\Tests\Support\MockResponses;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;

final class DoctorCommandTest extends BrowserTestCase
{
    public function testAGoodSetupExitsWithZero(): void
    {
        $tester = $this->execute();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Everything is set up.', $tester->getDisplay());
    }

    public function testAnErrorExitsWithOneAndPrintsTheFix(): void
    {
        $this->withEnv('UI_PASSWORD', '', function (): void {
            $tester = $this->execute();

            self::assertSame(1, $tester->getStatusCode());
            self::assertStringContainsString('UI_PASSWORD', $tester->getDisplay());
            self::assertStringContainsString('fix: Add UI_PASSWORD=<pick something> to .env.local.', $tester->getDisplay());
            self::assertStringContainsString('✘', $tester->getDisplay());
            self::assertStringContainsString('✔', $tester->getDisplay());
        });
    }

    public function testAMissingIndexNamesTheTwoCommands(): void
    {
        IndexFixture::remove((string) $_SERVER['BRAIN_DB']);

        $tester = $this->execute();

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('php bin/console ai:store:setup brain && php bin/console ai:store:index brain', $tester->getDisplay());
    }

    public function testAWarningAloneKeepsTheExitCodeAtZero(): void
    {
        $this->withEnv('ELEVENLABS_API_KEY', '', function (): void {
            $tester = $this->execute();

            self::assertSame(0, $tester->getStatusCode());
            self::assertStringContainsString('Voice is off.', $tester->getDisplay());
            self::assertStringContainsString('1 warning(s)', $tester->getDisplay());
        });
    }

    public function testNoKeyAndNoPasswordIsPrinted(): void
    {
        $tester = $this->execute();

        foreach (['dummy-cohere-key', 'dummy-elevenlabs-key', self::PASSWORD] as $secret) {
            self::assertStringNotContainsString($secret, $tester->getDisplay());
        }
    }

    public function testASearchCommandStopsWithTheReasonWhenTheIndexIsNotBuilt(): void
    {
        IndexFixture::remove((string) $_SERVER['BRAIN_DB']);

        $tester = $this->execute('app:ask', ['question' => 'What is MCP?']);

        self::assertStringContainsString('ai:store:setup brain', $tester->getDisplay());
        self::assertSame([], MockResponses::requests());
    }

    /**
     * Goes through the application like a real run, so the listener that stops a command is part of it.
     *
     * @param array<string, string> $input
     */
    private function execute(string $command = 'app:doctor', array $input = []): ApplicationTester
    {
        self::ensureKernelShutdown();
        $application = new Application(self::bootKernel());
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $tester->run(['command' => $command] + $input, ['decorated' => false]);

        return $tester;
    }
}
