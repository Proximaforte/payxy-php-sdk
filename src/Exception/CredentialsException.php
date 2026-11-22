<?php

declare(strict_types=1);

namespace Payxy\Exception;

/**
 * A credential the called feature needs is missing. Thrown before any request is sent.
 */
final class CredentialsException extends ConfigurationException {}
