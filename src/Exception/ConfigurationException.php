<?php

declare(strict_types=1);

namespace Payxy\Exception;

/**
 * Raised when the SDK is constructed with an unusable configuration.
 */
final class ConfigurationException extends InvalidArgumentException {}
