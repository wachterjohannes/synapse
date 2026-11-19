<?php

namespace App\Tests\Support;

use App\Ai\ProviderKey;
use App\Setup\Doctor;

/**
 * A Doctor for a made-up install, so a check can be tried against any combination of keys, folders and PHP.
 * The defaults are an install where everything is fine, in a throw-away folder.
 */
final class Environment
{
    /**
     * @param ?\Closure(string): bool $extensionLoaded
     */
    public static function doctor(
        string $root,
        string $provider = 'cohere',
        string $cohere = 'c-key',
        string $huggingFace = 'h-key',
        string $uiPassword = 'pw',
        string $elevenLabs = 'e-key',
        string $phpVersion = '8.4.1',
        ?\Closure $extensionLoaded = null,
        ?string $brainDb = null,
    ): Doctor {
        return new Doctor(new ProviderKey($provider, $cohere, $huggingFace), $uiPassword, $elevenLabs, $brainDb ?? $root.'/var/brain.sqlite', $root.'/notes', $root, $phpVersion, $extensionLoaded);
    }

    /** The folders and the index of an install that is fine. */
    public static function install(string $root, int $passages = 3): void
    {
        foreach (['notes/brain', 'notes/inbox', 'var'] as $dir) {
            mkdir($root.'/'.$dir, 0o777, true);
        }
        IndexFixture::build($root.'/var/brain.sqlite', $passages);
    }
}
