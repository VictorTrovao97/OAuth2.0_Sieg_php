<?php

declare(strict_types=1);

namespace SiegAuth\Exceptions;

/**
 * Representa um erro HTTP retornado pelos endpoints OAuth 2.0 da SIEG.
 */
class SiegHttpException extends SiegAuthException
{
    public int $statusCode;
    public ?string $responseBody;

    public function __construct(
        int $statusCode,
        ?string $responseBody,
        string $message,
        ?\Throwable $previous = null
    ) {
        $this->statusCode = $statusCode;
        $this->responseBody = $responseBody;
        parent::__construct($message, 0, $previous);
    }
}
