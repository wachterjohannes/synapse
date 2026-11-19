<?php

namespace App\Tests\Support;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A throw-away brain folder per test, so no test ever touches notes/.
 */
trait TempDirectory
{
    private string $root = '';

    #[Before]
    protected function createRoot(): void
    {
        $this->root = sys_get_temp_dir().'/synapse-unit-'.bin2hex(random_bytes(6));
        (new Filesystem())->mkdir([$this->root.'/brain', $this->root.'/inbox']);
    }

    #[After]
    protected function removeRoot(): void
    {
        (new Filesystem())->remove($this->root);
    }
}
