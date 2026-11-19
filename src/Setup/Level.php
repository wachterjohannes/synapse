<?php

namespace App\Setup;

enum Level: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Error = 'error';

    public function symbol(): string
    {
        return match ($this) {
            self::Ok => '✔',
            self::Warning => '!',
            self::Error => '✘',
        };
    }
}
