<?php

namespace App\Voice;

use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Speech in and out through ElevenLabs: text to speech for answers, speech to text for questions and notes.
 */
final class Speech
{
    public function __construct(
        #[Autowire(service: 'ai.platform.elevenlabs')]
        private readonly PlatformInterface $platform,
        #[Autowire('%env(ELEVENLABS_VOICE)%')]
        private readonly string $voice,
        #[Autowire('%env(ELEVENLABS_API_KEY)%')]
        private readonly string $apiKey,
    ) {
    }

    /** @return string MP3 audio */
    public function speak(string $text): string
    {
        $this->assertConfigured();

        return $this->platform->invoke('eleven_multilingual_v2', $text, ['voice' => $this->voice])->asBinary();
    }

    public function transcribe(string $audioPath): string
    {
        $this->assertConfigured();
        if (!is_file($audioPath)) {
            throw new \InvalidArgumentException(\sprintf('The audio file "%s" does not exist.', $audioPath));
        }

        return trim($this->platform->invoke('scribe_v1', ['input_audio' => ['path' => $audioPath]])->asText());
    }

    private function assertConfigured(): void
    {
        if ('' === $this->apiKey) {
            throw new \RuntimeException('Speech needs ELEVENLABS_API_KEY.');
        }
    }
}
