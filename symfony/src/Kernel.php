<?php
declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function getCacheDir(): string
    {
        // WSL and native PHP may review the same checkout; compiled paths cannot be shared.
        $directory = $_SERVER['INDY_CACHE_DIR'] ?? getenv('INDY_CACHE_DIR') ?: parent::getCacheDir();
        return rtrim($directory, '/\\').'/'.PHP_OS_FAMILY;
    }
}
