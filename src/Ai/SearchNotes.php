<?php

namespace App\Ai;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Store\RetrieverInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

#[McpTool(name: 'search_notes', description: 'Searches the brain and returns the best matching passages with the file they come from.', annotations: new ToolAnnotations(readOnlyHint: true))]
#[AsTool('search_notes', 'Searches the brain and returns the best matching passages as a JSON list of source and text.')]
final class SearchNotes
{
    public function __construct(
        #[Target('brain')]
        private readonly RetrieverInterface $retriever,
    ) {
    }

    /**
     * @param string $query what to look for, as a question or a few keywords
     */
    public function __invoke(string $query): string
    {
        $passages = [];
        foreach ($this->retriever->retrieve($query, ['maxItems' => 4]) as $document) {
            $metadata = $document->getMetadata();
            // The source is the path read_note expects, like "brain/topics/rag-basics.md". JSON keeps the text
            // of a note from passing itself off as another passage or another source.
            $passages[] = ['source' => $metadata->getSource() ?? 'unknown', 'text' => $metadata->getText() ?? ''];
        }

        return json_encode($passages, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }
}
