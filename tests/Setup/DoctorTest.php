<?php

namespace App\Tests\Setup;

use App\Setup\Check;
use App\Setup\Doctor;
use App\Setup\Level;
use App\Tests\Support\Environment;
use App\Tests\Support\IndexFixture;
use App\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;

final class DoctorTest extends TestCase
{
    use TempDirectory;

    public function testAnInstallThatIsFineHasNoProblems(): void
    {
        Environment::install($this->root);
        $doctor = Environment::doctor($this->root);

        self::assertSame([], $doctor->problems());
        self::assertNull($doctor->searchProblem());
        self::assertCount(10, $doctor->checks());
    }

    public function testAMissingKeyOfTheChosenProviderIsAnErrorWithTheSetting(): void
    {
        Environment::install($this->root);

        $check = $this->check(Environment::doctor($this->root, cohere: ''), 'COHERE_API_KEY');

        self::assertSame(Level::Error, $check->level);
        self::assertSame('Add COHERE_API_KEY=<your key> to .env.local.', $check->fix);
    }

    public function testTheKeyOfAnotherProviderDoesNotMatter(): void
    {
        Environment::install($this->root);

        self::assertSame([], array_filter(Environment::doctor($this->root, huggingFace: '')->checks(), static fn (Check $c): bool => Level::Error === $c->level));
    }

    public function testHuggingFaceAsksForItsOwnKeyAndWarnsAboutCohereForFederation(): void
    {
        Environment::install($this->root);
        $doctor = Environment::doctor($this->root, provider: 'huggingface', cohere: '', huggingFace: '');

        self::assertSame(Level::Error, $this->check($doctor, 'HUGGINGFACE_API_KEY')->level);
        self::assertSame(Level::Warning, $this->check($doctor, 'Cohere key for federation')->level);
    }

    public function testCohereForFederationIsNotAskedForWithCohere(): void
    {
        Environment::install($this->root);

        self::assertSame(Level::Ok, $this->check(Environment::doctor($this->root, cohere: ''), 'Cohere key for federation')->level);
    }

    public function testAnUnknownProviderIsAnError(): void
    {
        Environment::install($this->root);

        $check = $this->check(Environment::doctor($this->root, provider: 'openai'), 'SYNAPSE_PROVIDER');

        self::assertSame(Level::Error, $check->level);
        self::assertStringContainsString('cohere or huggingface', (string) $check->fix);
    }

    public function testAnEmptyProviderMeansTheDefault(): void
    {
        Environment::install($this->root);

        self::assertSame(Level::Ok, $this->check(Environment::doctor($this->root, provider: ''), 'SYNAPSE_PROVIDER')->level);
    }

    public function testAnEmptyPasswordIsAnError(): void
    {
        Environment::install($this->root);

        $check = $this->check(Environment::doctor($this->root, uiPassword: ''), 'UI_PASSWORD');

        self::assertSame(Level::Error, $check->level);
        self::assertStringContainsString('.env.local', (string) $check->fix);
    }

    public function testMissingVoiceIsOnlyAWarning(): void
    {
        Environment::install($this->root);
        $doctor = Environment::doctor($this->root, elevenLabs: '');

        self::assertSame(Level::Warning, $this->check($doctor, 'ELEVENLABS_API_KEY')->level);
        self::assertCount(1, $doctor->problems());
    }

    public function testAnOldPhpIsAnError(): void
    {
        Environment::install($this->root);

        self::assertSame(Level::Error, $this->check(Environment::doctor($this->root, phpVersion: '8.3.9'), 'PHP 8.4 or newer')->level);
    }

    public function testAMissingExtensionIsNamed(): void
    {
        Environment::install($this->root);
        $doctor = Environment::doctor($this->root, extensionLoaded: static fn (string $name): bool => 'mbstring' !== $name);

        $check = $this->check($doctor, 'PHP extensions');

        self::assertSame(Level::Error, $check->level);
        self::assertStringContainsString('Missing: mbstring.', $check->detail);
    }

    public function testNoIndexFileMeansTheTwoCommands(): void
    {
        Environment::install($this->root);
        IndexFixture::remove($this->root.'/var/brain.sqlite');
        $doctor = Environment::doctor($this->root);

        $check = $this->check($doctor, 'Search index');

        self::assertSame(Level::Error, $check->level);
        self::assertSame('php bin/console ai:store:setup brain && php bin/console ai:store:index brain', $check->fix);
        self::assertFileDoesNotExist($this->root.'/var/brain.sqlite', 'Looking must not create the file.');
        self::assertStringContainsString('ai:store:setup brain', (string) $doctor->searchProblem());
    }

    public function testAnIndexFileWithoutTheTableMeansTheTwoCommands(): void
    {
        Environment::install($this->root);
        file_put_contents($this->root.'/var/brain.sqlite', '');

        $check = $this->check(Environment::doctor($this->root), 'Search index');

        self::assertSame(Level::Error, $check->level);
        self::assertStringContainsString('ai:store:setup brain', (string) $check->fix);
    }

    public function testAnEmptyIndexMeansIndexing(): void
    {
        Environment::install($this->root, passages: 0);

        $check = $this->check(Environment::doctor($this->root), 'Search index');

        self::assertSame(Level::Error, $check->level);
        self::assertSame('php bin/console ai:store:index brain', $check->fix);
    }

    public function testTheIndexIsCountedAndNotChanged(): void
    {
        Environment::install($this->root, passages: 7);
        $before = md5_file($this->root.'/var/brain.sqlite');

        $check = $this->check(Environment::doctor($this->root), 'Search index');

        self::assertSame(Level::Ok, $check->level);
        self::assertStringContainsString('7 passages', $check->detail);
        self::assertSame($before, md5_file($this->root.'/var/brain.sqlite'));
    }

    public function testASearchProblemNamesTheKeyBeforeTheIndex(): void
    {
        $doctor = Environment::doctor($this->root, cohere: '');

        self::assertStringStartsWith('COHERE_API_KEY is empty.', (string) $doctor->searchProblem());
    }

    public function testMissingNotesFoldersAreAnError(): void
    {
        mkdir($this->root.'/var');
        $doctor = Environment::doctor($this->root);

        $check = $this->check($doctor, 'Notes folder');

        self::assertSame(Level::Error, $check->level);
        self::assertStringContainsString('Notes does not exist', $check->detail);
        self::assertSame('mkdir -p notes/brain notes/inbox && chmod -R u+w notes', $check->fix);
    }

    public function testAFolderThatCannotBeWrittenIsNamed(): void
    {
        Environment::install($this->root);
        chmod($this->root.'/notes/inbox', 0o555);
        try {
            if (is_writable($this->root.'/notes/inbox')) {
                self::markTestSkipped('Running as a user that can write anywhere.');
            }

            $check = $this->check(Environment::doctor($this->root), 'Notes folder');

            self::assertSame(Level::Error, $check->level);
            self::assertStringContainsString('Notes/inbox is not writable', $check->detail);
        } finally {
            chmod($this->root.'/notes/inbox', 0o777);
        }
    }

    public function testAVarFolderThatIsMissingIsAnError(): void
    {
        Environment::install($this->root);
        (new \Symfony\Component\Filesystem\Filesystem())->remove($this->root.'/var');

        self::assertSame(Level::Error, $this->check(Environment::doctor($this->root, brainDb: $this->root.'/elsewhere.sqlite'), 'var/ folder')->level);
    }

    public function testProblemsListTheErrorsFirst(): void
    {
        Environment::install($this->root);
        $doctor = Environment::doctor($this->root, uiPassword: '', elevenLabs: '');

        self::assertSame(['UI_PASSWORD', 'ELEVENLABS_API_KEY'], array_map(static fn (Check $c): string => $c->title, $doctor->problems()));
    }

    public function testNoResultEverHoldsAKeyOrAPassword(): void
    {
        Environment::install($this->root);
        $doctor = Environment::doctor($this->root, provider: 'huggingface', cohere: 'secret-cohere', huggingFace: 'secret-hf', uiPassword: 'secret-password', elevenLabs: 'secret-eleven');
        $broken = Environment::doctor($this->root.'/nowhere', cohere: 'secret-cohere', uiPassword: 'secret-password', elevenLabs: 'secret-eleven');

        foreach ([$doctor, $broken] as $each) {
            $text = json_encode(array_map(static fn (Check $c): array => (array) $c, $each->checks()), \JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('secret-', $text);
        }
    }

    private function check(Doctor $doctor, string $title): Check
    {
        foreach ($doctor->checks() as $check) {
            if ($check->title === $title) {
                return $check;
            }
        }

        self::fail('No check called '.$title);
    }
}
