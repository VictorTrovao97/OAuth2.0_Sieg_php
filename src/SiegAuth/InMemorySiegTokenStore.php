<?php

declare(strict_types=1);

namespace SiegAuth;

/**
 * Implementação em memória de SiegTokenStoreInterface adequada para
 * cenários simples, testes e aplicações de linha de comando.
 */
final class InMemorySiegTokenStore implements SiegTokenStoreInterface
{
    /** @var array<string, SiegToken> */
    private array $tokens = [];

    public function getToken(string $accountKey): ?SiegToken
    {
        return $this->tokens[$accountKey] ?? null;
    }

    public function saveToken(string $accountKey, SiegToken $token): void
    {
        $this->tokens[$accountKey] = $token;
    }

    public function deleteToken(string $accountKey): void
    {
        unset($this->tokens[$accountKey]);
    }
}
