<?php

namespace App\Brain;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * One note as the pages show it: the frontmatter as data, then the markdown body.
 */
final class Note
{
    /**
     * @param list<string>                          $tags
     * @param list<array{id: string, resource: string}> $sources
     */
    public function __construct(
        public readonly string $path,
        public readonly string $type,
        public readonly string $title,
        public readonly string $description,
        public readonly array $tags,
        public readonly string $status,
        public readonly string $generatedBy,
        public readonly string $generatedAt,
        public readonly array $sources,
        public readonly string $body,
    ) {
    }

    public static function parse(string $path, string $content): self
    {
        $meta = [];
        $body = $content;
        if (preg_match('/\A---\n(.*?\n)?---\n(.*)\z/s', $content, $m)) {
            try {
                $parsed = Yaml::parse($m[1] ?? '');
                $meta = \is_array($parsed) ? $parsed : [];
            } catch (ParseException) {
                // A hand-edited note with broken frontmatter is still shown, just without its data.
            }
            $body = $m[2];
        }
        $text = static fn (mixed $value): string => \is_scalar($value) ? (string) $value : '';

        return new self(
            $path,
            $text($meta['type'] ?? ''),
            $text($meta['title'] ?? '') ?: basename($path, '.md'),
            $text($meta['description'] ?? ''),
            array_values(array_filter(array_map($text, \is_array($meta['tags'] ?? null) ? array_filter($meta['tags'], 'is_scalar') : []), static fn (string $tag): bool => '' !== $tag)),
            $text($meta['status'] ?? ''),
            $text($meta['generated']['by'] ?? ''),
            // YAML turns an unquoted timestamp into an int
            \is_int($meta['generated']['at'] ?? null) ? gmdate('Y-m-d\TH:i:s\Z', $meta['generated']['at']) : $text($meta['generated']['at'] ?? ''),
            self::sources($meta['sources'] ?? null),
            ltrim($body),
        );
    }

    /**
     * A hand-edited note can hold anything under "sources". Only entries with an id and a resource that are plain
     * values are kept, so a page never has to print a list or a map.
     *
     * @return list<array{id: string, resource: string}>
     */
    private static function sources(mixed $sources): array
    {
        $kept = [];
        foreach (\is_array($sources) ? $sources : [] as $source) {
            if (\is_array($source) && \is_scalar($source['id'] ?? null) && \is_scalar($source['resource'] ?? null)) {
                $kept[] = ['id' => (string) $source['id'], 'resource' => (string) $source['resource']];
            }
        }

        return $kept;
    }
}
