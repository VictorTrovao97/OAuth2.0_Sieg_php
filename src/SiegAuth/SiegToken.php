<?php

declare(strict_types=1);

namespace SiegAuth;

/**
 * Representa o token definitivo obtido via generate-token, utilizado para consumir as APIs da SIEG.
 */
final class SiegToken
{
    public function __construct(
        public readonly string $accessToken,
        public readonly \DateTimeImmutable $expiresAt,
        public readonly ?string $refreshToken = null,
        /** @var array<string, mixed> */
        public readonly array $additionalData = []
    ) {
        if (trim($accessToken) === '') {
            throw new \InvalidArgumentException('AccessToken não pode ser vazio.');
        }
    }

    /**
     * Indica se o token está expirado considerando uma folga (clock skew).
     *
     * @param \DateTimeInterface|null $now Instante de comparação (UTC por padrão).
     * @param int|null $toleranceSeconds Folga em segundos para antecipar a expiração (padrão 60).
     */
    public function isExpired(?\DateTimeInterface $now = null, ?int $toleranceSeconds = 60): bool
    {
        $current = $now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $expiresAt = \DateTimeImmutable::createFromInterface($this->expiresAt);
        $threshold = $expiresAt->modify("-{$toleranceSeconds} seconds");
        return $current >= $threshold;
    }
}
