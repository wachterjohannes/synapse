<?php

namespace App\Setup;

use App\Ai\ProviderKey;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Looks at what a fresh install is missing: a key, the password, the index. It never calls a provider, so a check
 * costs nothing, and it never puts a key or a password into a result. It only knows whether a value is set.
 */
final class Doctor
{
    private const MIN_PHP = '8.4.0';
    private const EXTENSIONS = ['pdo_sqlite', 'mbstring', 'ctype', 'iconv'];
    private const PROVIDERS = ['cohere', 'huggingface'];
    private const INDEX_FIX = 'php bin/console ai:store:setup brain && php bin/console ai:store:index brain';

    /**
     * @param ?\Closure(string): bool $extensionLoaded only for tests, to fake a PHP without an extension
     */
    public function __construct(
        private readonly ProviderKey $keys,
        #[Autowire('%env(UI_PASSWORD)%')]
        private readonly string $uiPassword,
        #[Autowire('%env(ELEVENLABS_API_KEY)%')]
        private readonly string $elevenLabsKey,
        #[Autowire('%env(default:default_db:BRAIN_DB)%')]
        private readonly string $brainDb,
        #[Autowire('%env(default:default_notes:BRAIN_DIR)%')]
        private readonly string $notesDir,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly string $phpVersion = \PHP_VERSION,
        private readonly ?\Closure $extensionLoaded = null,
    ) {
    }

    /**
     * @return list<Check>
     */
    public function checks(): array
    {
        return [
            $this->php(),
            $this->extensions(),
            $this->provider(),
            $this->providerKey(),
            $this->password(),
            $this->index(),
            $this->notes(),
            $this->var(),
            $this->voice(),
            $this->rerank(),
        ];
    }

    /**
     * @return list<Check> what is not ok, the errors first
     */
    public function problems(): array
    {
        $problems = array_values(array_filter($this->checks(), static fn (Check $check): bool => !$check->isOk()));
        usort($problems, static fn (Check $a, Check $b): int => (Level::Error === $b->level) <=> (Level::Error === $a->level));

        return $problems;
    }

    /** What to tell somebody who asks a question, or null when the agents can search. The key comes first. */
    public function searchProblem(): ?string
    {
        return $this->keys->missing() ?? $this->indexProblem();
    }

    /** The plain words for a missing index, or null when it holds documents. */
    public function indexProblem(): ?string
    {
        $index = $this->index();

        return $index->isOk() ? null : $index->detail.' Run '.$index->fix.'.';
    }

    private function php(): Check
    {
        $title = 'PHP 8.4 or newer';

        return version_compare($this->phpVersion, self::MIN_PHP, '>=')
            ? Check::ok($title, 'This is PHP '.$this->phpVersion.'.')
            : Check::error($title, 'This is PHP '.$this->phpVersion.'.', 'Install PHP 8.4 or newer and run composer install again.');
    }

    private function extensions(): Check
    {
        $loaded = $this->extensionLoaded ?? extension_loaded(...);
        $missing = array_values(array_filter(self::EXTENSIONS, static fn (string $extension): bool => !$loaded($extension)));

        return [] === $missing
            ? Check::ok('PHP extensions', implode(', ', self::EXTENSIONS).' are loaded.')
            : Check::error('PHP extensions', 'Missing: '.implode(', ', $missing).'.', 'Enable '.implode(', ', $missing).' in your php.ini or install them with your package manager.');
    }

    private function provider(): Check
    {
        $provider = $this->keys->provider();

        return \in_array($provider, self::PROVIDERS, true)
            ? Check::ok('SYNAPSE_PROVIDER', 'The provider is '.$provider.'.')
            : Check::error('SYNAPSE_PROVIDER', 'The value "'.$provider.'" is not a provider.', 'Set SYNAPSE_PROVIDER to '.implode(' or ', self::PROVIDERS).' in .env.local.');
    }

    private function providerKey(): Check
    {
        $title = $this->keys->variable();

        return null === $this->keys->missing()
            ? Check::ok($title, 'The key of the provider is set.')
            : Check::error($title, 'The key of the provider "'.$this->keys->provider().'" is empty. Questions and captures need it.', 'Add '.$title.'=<your key> to .env.local.');
    }

    private function password(): Check
    {
        return '' !== $this->uiPassword
            ? Check::ok('UI_PASSWORD', 'The browser page is open to the password.')
            : Check::error('UI_PASSWORD', 'The browser page stays closed while the password is empty.', 'Add UI_PASSWORD=<pick something> to .env.local.');
    }

    private function index(): Check
    {
        $title = 'Search index';
        $file = $this->relative($this->brainDb);
        $provider = $this->keys->provider();
        if (!is_file($this->brainDb)) {
            return Check::error($title, sprintf('There is no index for the provider %s yet (%s).', $provider, $file), self::INDEX_FIX);
        }

        try {
            $pdo = new \Pdo\Sqlite('sqlite:'.$this->brainDb, null, null, [\Pdo\Sqlite::ATTR_OPEN_FLAGS => \Pdo\Sqlite::OPEN_READONLY, \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            // the table is called after the store, see config/packages/ai.yaml
            $count = (int) $pdo->query('SELECT COUNT(*) FROM brain')->fetchColumn();
        } catch (\PDOException) {
            return Check::error($title, sprintf('The index file %s has no table for the notes yet.', $file), self::INDEX_FIX);
        }

        return $count > 0
            ? Check::ok($title, sprintf('%d passages in %s.', $count, $file))
            : Check::error($title, sprintf('The index %s holds no passages yet.', $file), 'php bin/console ai:store:index brain');
    }

    private function notes(): Check
    {
        $title = 'Notes folder';
        $problems = [];
        foreach (['', '/brain', '/inbox'] as $part) {
            $path = $this->notesDir.$part;
            if (!is_dir($path)) {
                $problems[] = $this->relative($path).' does not exist';
            } elseif (!is_writable($path)) {
                $problems[] = $this->relative($path).' is not writable';
            }
        }
        if ([] === $problems) {
            return Check::ok($title, $this->relative($this->notesDir).' has brain/ and inbox/, both writable.');
        }

        $dir = $this->relative($this->notesDir);

        return Check::error($title, ucfirst(implode(', ', $problems)).'.', sprintf('mkdir -p %1$s/brain %1$s/inbox && chmod -R u+w %1$s', $dir));
    }

    private function var(): Check
    {
        $path = $this->projectDir.'/var';

        return is_dir($path) && is_writable($path)
            ? Check::ok('var/ folder', 'The cache and the index can be written.')
            : Check::error('var/ folder', 'var/ does not exist or is not writable. The cache and the index live there.', 'mkdir -p var && chmod -R u+w var');
    }

    private function voice(): Check
    {
        return '' !== $this->elevenLabsKey
            ? Check::ok('ELEVENLABS_API_KEY', 'Voice in and out is on.')
            : Check::warning('ELEVENLABS_API_KEY', 'Voice is off. Everything else works.', 'Add ELEVENLABS_API_KEY=<your key> to .env.local to turn it on.');
    }

    private function rerank(): Check
    {
        if ('huggingface' !== $this->keys->provider()) {
            return Check::ok('Cohere key for federation', 'Not needed with this provider.');
        }

        return null === $this->keys->missingForRerank()
            ? Check::ok('Cohere key for federation', 'app:federate can rerank.')
            : Check::warning('Cohere key for federation', 'app:federate reranks with Cohere, also on Hugging Face. Without the key it cannot run.', 'Add COHERE_API_KEY=<your key> to .env.local.');
    }

    private function relative(string $path): string
    {
        return str_starts_with($path, $this->projectDir.'/') ? substr($path, \strlen($this->projectDir) + 1) : $path;
    }
}
