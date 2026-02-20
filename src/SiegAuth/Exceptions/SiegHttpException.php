<?php

declare(strict_types=1);

namespace SiegAuth\Exceptions;

/**
 * Representa um erro HTTP retornado pelos endpoints OAuth 2.0 da SIEG.
 */
class SiegHttpException extends SiegAuthException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly ?string $responseBody,
        string $message,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
