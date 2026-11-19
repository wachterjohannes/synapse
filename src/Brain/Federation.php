<?php

namespace App\Brain;

use Symfony\AI\McpBundle\Client\McpClientInterface;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\Document\VectorDocumentInterface;
use Symfony\AI\Store\Reranker\RerankerInterface;
use Symfony\AI\Store\RetrieverInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Asks this brain and another brain the same question and merges the answers into one ranking.
 *
 * The two brains score their passages on their own scales, so the scores cannot be compared.
 * A reranker looks at the question and the text of every passage and sorts them again.
 */
final class Federation
{
    public function __construct(
        #[Target('brain')]
        private readonly RetrieverInterface $retriever,
        #[Target('brain')]
        private readonly McpClientInterface $client,
        private readonly RerankerInterface $reranker,
    ) {
    }

    private ?string $warning = null;

    /**
     * Why the other brain is missing from the last search, or null when it answered.
     */
    public function warning(): ?string
    {
        return $this->warning;
    }

    /**
     * @return list<VectorDocumentInterface> the best passages of both brains, best first
     */
    public function search(string $question, string $server = 'friend', int $limit = 5): array
    {
        $this->warning = null;
        $candidates = [];
        foreach ($this->retriever->retrieve($question, ['maxItems' => 5]) as $document) {
            $metadata = $document->getMetadata();
            $candidates[] = $this->candidate('mine:'.($metadata->getSource() ?? 'unknown'), $metadata->getText() ?? '');
        }

        // The other brain may be down or may refuse the token. Then the answer still comes from this brain.
        try {
            $result = $this->client->get($server)->callTool('search_notes', ['query' => $question]);
            foreach ($result->content as $content) {
                foreach (json_decode($content->text ?? '[]', true, 8, \JSON_THROW_ON_ERROR) as $passage) {
                    if (\is_array($passage) && \is_string($passage['source'] ?? null) && \is_string($passage['text'] ?? null)) {
                        $candidates[] = $this->candidate($server.':'.$passage['source'], $passage['text']);
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->warning = sprintf('Could not ask "%s", showing only the passages of this brain: %s', $server, $e->getMessage());
        }

        try {
            return $this->reranker->rerank($question, $candidates, $limit);
        } catch (\Throwable $e) {
            $this->warning = trim(($this->warning ?? '').sprintf(' Could not rerank, showing the passages in the order they came: %s', $e->getMessage()));

            return \array_slice($candidates, 0, $limit);
        }
    }

    private function candidate(string $source, string $text): VectorDocument
    {
        return new VectorDocument(
            md5($source.$text),
            new Vector([0.0]),
            new Metadata([Metadata::KEY_SOURCE => $source, Metadata::KEY_TEXT => $text]),
        );
    }
}
