<?php

declare(strict_types=1);

namespace SiegAuth;

/**
 * Abstração de armazenamento de tokens por conta/empresa.
 * Implementações podem usar memória, banco de dados, cache distribuído etc.
 */
interface SiegTokenStoreInterface
{
    /**
     * Obtém o token associado a uma conta específica.
     *
     * @param string $accountKey Chave que identifica a conta (ex.: CNPJ, ID interno).
     */
    public function getToken(string $accountKey): ?SiegToken;

    /**
     * Persiste o token associado a uma conta específica.
     *
     * @param string $accountKey Chave que identifica a conta.
     * @param SiegToken $token Token a ser salvo.
     */
    public function saveToken(string $accountKey, SiegToken $token): void;

    /**
     * Remove o token associado a uma conta específica.
     *
     * @param string $accountKey Chave que identifica a conta.
     */
    public function deleteToken(string $accountKey): void;
}
