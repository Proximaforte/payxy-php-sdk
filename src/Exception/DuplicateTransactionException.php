<?php

declare(strict_types=1);

namespace Payxy\Exception;

/**
 * Initialize refused the transactionId because it already exists for this merchant. After an
 * ambiguous failure this means the earlier attempt landed: reconcile with verify() rather
 * than generating a new id.
 */
final class DuplicateTransactionException extends ApiException {}
