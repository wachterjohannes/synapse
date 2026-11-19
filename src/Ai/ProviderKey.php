<?php

namespace App\Ai;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Says what is missing before a call goes out. Without this, an empty key still sends a request with an empty
 * bearer token, and what comes back is "no api key supplied" from somewhere deep in the platform.
 */
final class ProviderKey
{
    public function __construct(
        #[Autowire('%env(SYNAPSE_PROVIDER)%')]
        private readonly string $provider,
        #[Autowire('%env(COHERE_API_KEY)%')]
        private readonly string $cohere,
        #[Autowire('%env(HUGGINGFACE_API_KEY)%')]
        private readonly string $huggingFace,
    ) {
    }

    /** The message to show when the key of the chosen provider is empty, or null when it is set. */
    public function missing(): ?string
    {
        [$name, $key] = 'huggingface' === $this->provider ? ['HUGGINGFACE_API_KEY', $this->huggingFace] : ['COHERE_API_KEY', $this->cohere];

        return '' === $key ? sprintf('%s is empty. Put it into .env.local, see the README.', $name) : null;
    }

    /** Federation reranks with Cohere whatever the provider is. */
    public function missingForRerank(): ?string
    {
        return '' === $this->cohere ? 'COHERE_API_KEY is empty. app:federate reranks with Cohere. Put it into .env.local, see the README.' : null;
    }
}
