<?php

declare(strict_types=1);

namespace Payxy\Http;

/**
 * Seam over the backoff wait so tests (and async runtimes) can replace it.
 */
interface Sleeper
{
    public function sleep(int $milliseconds): void;
}
