<?php

namespace App\Markdown;

use App\Brain\Vault;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Turns the text of a note or of an answer into HTML for the browser. Both come from agents and from web pages the
 * agents read, so the result must never run anything: raw HTML is dropped and a link with a script or a data
 * target is not allowed. Two kinds of link are added on top:
 *
 * - "/topics/mcp.md" (from the brain root, as notes link each other) leads to the page of that note.
 * - "brain/topics/mcp.md" in the text, or in backticks, leads there too, but only when that note exists and the
 *   page would show it. An answer names its sources this way, and a made up path stays plain text.
 */
final class MarkdownRenderer
{
    private const SOURCE = '#(?<![\w/.-])(brain/[A-Za-z0-9][A-Za-z0-9._/-]*\.md)(?![\w/-])#';

    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly Vault $vault,
    ) {
    }

    /**
     * @param bool $keepLineBreaks show a line break of the text as a line break, as an answer lists its sources
     */
    public function render(string $markdown, bool $keepLineBreaks = false): string
    {
        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'renderer' => ['soft_break' => $keepLineBreaks ? "<br>\n" : "\n"],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());
        $environment->addEventListener(DocumentParsedEvent::class, fn (DocumentParsedEvent $event) => $this->link($event->getDocument()));

        return (new MarkdownConverter($environment))->convert($markdown)->getContent();
    }

    private function link(Node $document): void
    {
        $existing = null;
        $texts = [];
        foreach ($document->iterator() as $node) {
            if ($node instanceof Link && preg_match('#^/[^\s]*\.md\z#', $node->getUrl())) {
                $node->setUrl($this->urls->generate('ui_note', ['path' => 'brain'.$node->getUrl()]));
            } elseif (($node instanceof Text || $node instanceof Code) && !$this->insideLink($node) && preg_match(self::SOURCE, $node->getLiteral())) {
                $texts[] = $node;
            }
        }

        // the tree is changed after the walk, not during it
        foreach ($texts as $node) {
            $existing ??= $this->notes();
            $node instanceof Code ? $this->linkCode($node, $existing) : $this->linkText($node, $existing);
        }
    }

    /**
     * @param array<string, true> $existing
     */
    private function linkCode(Code $code, array $existing): void
    {
        $path = $code->getLiteral();
        if (isset($existing[$path])) {
            $link = new Link($this->url($path));
            $code->replaceWith($link);
            $link->appendChild($code);
        }
    }

    /**
     * @param array<string, true> $existing
     */
    private function linkText(Text $text, array $existing): void
    {
        $parts = preg_split(self::SOURCE, $text->getLiteral(), -1, \PREG_SPLIT_DELIM_CAPTURE | \PREG_SPLIT_NO_EMPTY) ?: [];
        if ([] === array_filter($parts, static fn (string $part): bool => isset($existing[$part]))) {
            return;
        }

        $nodes = [];
        foreach ($parts as $part) {
            if (isset($existing[$part])) {
                $link = new Link($this->url($part));
                $link->appendChild(new Text($part));
                $nodes[] = $link;
            } else {
                $nodes[] = new Text($part);
            }
        }
        $first = array_shift($nodes);
        $text->replaceWith($first);
        foreach ($nodes as $node) {
            $first->insertAfter($node);
            $first = $node;
        }
    }

    private function insideLink(Node $node): bool
    {
        for ($parent = $node->parent(); null !== $parent; $parent = $parent->parent()) {
            if ($parent instanceof Link) {
                return true;
            }
        }

        return false;
    }

    private function url(string $path): string
    {
        return $this->urls->generate('ui_note', ['path' => $path]);
    }

    /**
     * The notes the page shows: finished notes in brain/, without the two reserved files.
     *
     * @return array<string, true>
     */
    private function notes(): array
    {
        $notes = [];
        foreach ($this->vault->list() as $path) {
            if (str_starts_with($path, 'brain/') && !\in_array(basename($path), ['index.md', 'log.md'], true)) {
                $notes[$path] = true;
            }
        }

        return $notes;
    }
}
