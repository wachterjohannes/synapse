<?php

namespace App\Setup;

/**
 * The result of one setup check. The fix is the exact command or setting that makes it pass, and is null when
 * nothing is to be done. Neither field may ever hold a key or a password.
 */
final readonly class Check
{
    public function __construct(
        public Level $level,
        public string $title,
        public string $detail = '',
        public ?string $fix = null,
    ) {
    }

    public static function ok(string $title, string $detail = ''): self
    {
        return new self(Level::Ok, $title, $detail);
    }

    public static function warning(string $title, string $detail, string $fix): self
    {
        return new self(Level::Warning, $title, $detail, $fix);
    }

    public static function error(string $title, string $detail, string $fix): self
    {
        return new self(Level::Error, $title, $detail, $fix);
    }

    public function isOk(): bool
    {
        return Level::Ok === $this->level;
    }
}
