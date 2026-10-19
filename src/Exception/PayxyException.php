<?php

declare(strict_types=1);

namespace Payxy\Exception;

use Throwable;

/**
 * Marker implemented by every exception the SDK throws, so integrators can catch them all in one place.
 */
interface PayxyException extends Throwable {}
