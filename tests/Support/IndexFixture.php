<?php

namespace App\Tests\Support;

/**
 * A search index as ai:store:setup and ai:store:index leave it, built without a provider: the table of the store
 * with a given number of passages in it.
 */
final class IndexFixture
{
    public static function build(string $path, int $passages = 1): void
    {
        self::remove($path);
        $pdo = new \PDO('sqlite:'.$path);
        $pdo->exec('CREATE TABLE brain (id TEXT PRIMARY KEY, vector TEXT NOT NULL, metadata TEXT)');
        for ($i = 0; $i < $passages; ++$i) {
            $pdo->exec(sprintf("INSERT INTO brain VALUES ('%d', '[0.1]', '{}')", $i));
        }
    }

    public static function remove(string $path): void
    {
        foreach ([$path, $path.'-journal'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}
