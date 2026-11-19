<?php

namespace App\Tests\Ai;

use App\Ai\ProviderKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProviderKeyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, ?string}>
     */
    public static function providers(): iterable
    {
        yield 'cohere with key' => ['cohere', 'c', '', null];
        yield 'cohere without key' => ['cohere', '', 'h', 'COHERE_API_KEY is empty. Put it into .env.local, see the README.'];
        yield 'huggingface with key' => ['huggingface', '', 'h', null];
        yield 'huggingface without key' => ['huggingface', 'c', '', 'HUGGINGFACE_API_KEY is empty. Put it into .env.local, see the README.'];
        yield 'unknown provider falls back to cohere' => ['other', '', '', 'COHERE_API_KEY is empty. Put it into .env.local, see the README.'];
    }

    #[DataProvider('providers')]
    public function testMissing(string $provider, string $cohere, string $huggingFace, ?string $expected): void
    {
        self::assertSame($expected, (new ProviderKey($provider, $cohere, $huggingFace))->missing());
    }

    public function testRerankAlwaysNeedsCohere(): void
    {
        self::assertNull((new ProviderKey('huggingface', 'c', ''))->missingForRerank());
        self::assertStringContainsString('COHERE_API_KEY is empty', (string) (new ProviderKey('huggingface', '', 'h'))->missingForRerank());
    }
}
