<?php

declare(strict_types=1);

namespace SiegAuth;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use SiegAuth\Exceptions\SiegAuthException;
use SiegAuth\Exceptions\SiegHttpException;

/**
 * Cliente de alto nível que encapsula todo o fluxo OAuth 2.0 com a SIEG, incluindo auto-refresh.
 */
final class SiegIntegrationClient
{
    private ClientInterface $httpClient;
    private RequestFactoryInterface $requestFactory;
    private StreamFactoryInterface $streamFactory;
    private SiegOAuthOptions $options;
    private SiegTokenStoreInterface $tokenStore;
    private ?LoggerInterface $logger;

    public function __construct(
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        SiegOAuthOptions $options,
        SiegTokenStoreInterface $tokenStore,
        ?LoggerInterface $logger = null
    ) {
        $this->httpClient = $httpClient;
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;
        $this->options = $options;
        $this->tokenStore = $tokenStore;
        $this->logger = $logger;

        if (trim($options->clientId) === '') {
            throw new \InvalidArgumentException('ClientId deve ser configurado em SiegOAuthOptions.');
        }
        if (trim($options->secretKey) === '') {
            throw new \InvalidArgumentException('SecretKey deve ser configurada em SiegOAuthOptions.');
        }
        if ($options->redirectUri === null || trim($options->redirectUri) === '') {
            throw new \InvalidArgumentException('RedirectUri deve ser configurada em SiegOAuthOptions.');
        }
    }

    /**
     * Gera a URL de autorização da SIEG para redirecionar o usuário.
     *
     * @param string $state Identificador interno do integrador (empresa, usuário, etc.).
     * @param string|null $accessLevel Nível de acesso (read, write, fullAccess). Se null, usa o padrão das opções.
     */
    public function getAuthorizationUrl(string $state, ?string $accessLevel = null): string
    {
        if (trim($state) === '') {
            throw new \InvalidArgumentException('state não pode ser vazio.');
        }
        $level = $accessLevel ?? $this->options->defaultAccessLevel ?? 'write';

        if ($this->logger !== null) $this->logger->debug("Montando URL de autorização SIEG com state='{$state}' e accessLevel='{$level}'.");

        $base = rtrim($this->options->baseAuthorizeUrl, '/');
        
        $paramsArray = [
            'clientId' => $this->options->clientId,
        ];
        
        if ($this->options->redirectUri !== null) {
            $paramsArray['redirectUri'] = $this->options->redirectUri;
        }

        $paramsArray['state'] = $state;
        $paramsArray['accessLevel'] = $level;

        $params = http_build_query($paramsArray);
        return $base . (strpos($base, '?') !== false ? '&' : '?') . $params;
    }

    /**
     * Conclui o fluxo de autorização com o token temporário recebido no callback e persiste o token definitivo.
     *
     * @param string $accountKey Identificador da conta (ex.: CNPJ, ID interno).
     * @param string $temporaryAccessToken Valor do parâmetro "accessToken" enviado pela SIEG no callback.
     * @param string $state Valor do parâmetro "state" enviado pela SIEG no callback.
     */
    public function completeAuthorization(string $accountKey, string $temporaryAccessToken, string $state): void
    {
        if (trim($accountKey) === '') {
            throw new \InvalidArgumentException('accountKey não pode ser vazio.');
        }
        if (trim($temporaryAccessToken) === '') {
            throw new \InvalidArgumentException('temporaryAccessToken não pode ser vazio.');
        }
        if (trim($state) === '') {
            throw new \InvalidArgumentException('state não pode ser vazio.');
        }

        if ($this->logger !== null) $this->logger->info("Concluindo autorização SIEG para conta '{$accountKey}'.");

        $url = rtrim($this->options->baseApiUrl, '/') . '/generate-token';
        $payload = [
            'AccessToken' => $temporaryAccessToken,
            'State' => $state,
            'RedirectUri' => $this->options->redirectUri,
        ];

        $response = $this->postJson($url, $payload);
        $data = $response['Data'] ?? null;

        if (empty($response['IsSuccess']) || !is_array($data) || trim($data['AccessToken'] ?? '') === '') {
            throw new SiegAuthException(
                'Falha ao gerar token definitivo na SIEG. ' .
                'StatusCode=' . ($response['StatusCode'] ?? 0) . ", Error='" . ($response['ErrorMessage'] ?? '') . "'."
            );
        }

        $token = new SiegToken(
            $data['AccessToken'],
            (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+30 days')
        );
        $this->tokenStore->saveToken($accountKey, $token);

        if ($this->logger !== null) $this->logger->info("Autorização SIEG concluída e token salvo para conta '{$accountKey}'.");
    }

    /**
     * Obtém um access token SIEG válido para a conta. Se estiver próximo de expirar, faz auto-refresh.
     *
     * @param string $accountKey Identificador da conta no sistema emissor.
     * @return string Access token para uso nas APIs da SIEG (header Authorization: Bearer <token>).
     */
    public function getValidAccessToken(string $accountKey): string
    {
        if (trim($accountKey) === '') {
            throw new \InvalidArgumentException('accountKey não pode ser vazio.');
        }

        $token = $this->tokenStore->getToken($accountKey);
        if ($token === null) {
            throw new SiegAuthException(
                "Nenhum token SIEG encontrado para a conta '{$accountKey}'. " .
                'Certifique-se de ter concluído o fluxo de autorização.'
            );
        }

        $toleranceSeconds = $this->options->autoRefreshThresholdSeconds;
        if ($token->isExpired(null, $toleranceSeconds)) {
            if ($this->logger !== null) $this->logger->info("Token SIEG para conta '{$accountKey}' próximo de expirar. Iniciando auto-refresh.");

            $this->refreshTokenInternal($token->accessToken);
            $renewed = new SiegToken(
                $token->accessToken,
                (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+30 days')
            );
            $this->tokenStore->saveToken($accountKey, $renewed);
            $token = $renewed;

            if ($this->logger !== null) $this->logger->info("Auto-refresh concluído para conta '{$accountKey}'. Nova expiração: " . $renewed->expiresAt->format(\DateTimeInterface::ATOM) . ".");
        }

        return $token->accessToken;
    }

    /**
     * Revoga o token da conta na SIEG e remove do armazenamento local.
     *
     * @param string $accountKey Identificador da conta no sistema emissor.
     */
    public function revoke(string $accountKey): void
    {
        if (trim($accountKey) === '') {
            throw new \InvalidArgumentException('accountKey não pode ser vazio.');
        }

        $token = $this->tokenStore->getToken($accountKey);
        if ($token === null) {
            if ($this->logger !== null) $this->logger->warning("Nenhum token SIEG encontrado para a conta '{$accountKey}' ao tentar revogar.");
            return;
        }

        if ($this->logger !== null) $this->logger->info("Revogando token SIEG para conta '{$accountKey}'.");

        $url = rtrim($this->options->baseApiUrl, '/') . '/revoke';
        $payload = ['Token' => $token->accessToken];
        $response = $this->postJson($url, $payload);

        if (empty($response['IsSuccess'])) {
            throw new SiegAuthException(
                "Falha ao revogar token na SIEG para conta '{$accountKey}'. " .
                'StatusCode=' . ($response['StatusCode'] ?? 0) . ", Error='" . ($response['ErrorMessage'] ?? '') . "'."
            );
        }

        $this->tokenStore->deleteToken($accountKey);

        if ($this->logger !== null) $this->logger->info("Token SIEG revogado e removido do armazenamento para conta '{$accountKey}'.");
    }

    private function refreshTokenInternal(string $accessToken): void
    {
        $url = rtrim($this->options->baseApiUrl, '/') . '/refresh';
        $payload = ['Token' => $accessToken];
        $response = $this->postJson($url, $payload);

        if (empty($response['IsSuccess'])) {
            throw new SiegAuthException(
                'Falha ao atualizar token na SIEG. ' .
                'StatusCode=' . ($response['StatusCode'] ?? 0) . ", Error='" . ($response['ErrorMessage'] ?? '') . "'."
            );
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function postJson(string $url, array $payload): array
    {
        if ($this->logger !== null) $this->logger->debug("Enviando requisição POST para '{$url}'.");

        try {
            $jsonString = json_encode($payload, JSON_THROW_ON_ERROR);
            $stream = $this->streamFactory->createStream($jsonString);
            
            $request = $this->requestFactory->createRequest('POST', $url)
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('X-Client-Id', $this->options->clientId)
                ->withHeader('X-Secret-Key', $this->options->secretKey)
                ->withBody($stream);

            $response = $this->httpClient->sendRequest($request);
            $statusCode = $response->getStatusCode();
            $body = (string) $response->getBody();
            
            if ($statusCode < 200 || $statusCode >= 300) {
                if ($this->logger !== null) $this->logger->error("Chamada HTTP para '{$url}' falhou com código {$statusCode}. Corpo: {$body}");
                throw new SiegHttpException(
                    $statusCode,
                    $body,
                    "Chamada HTTP para '{$url}' retornou código {$statusCode}."
                );
            }
        } catch (ClientExceptionInterface $e) {
            if ($this->logger !== null) $this->logger->error("Erro de rede ao chamar '{$url}': " . $e->getMessage());
            throw new SiegHttpException(0, '', "Chamada HTTP para '{$url}' falhou: " . $e->getMessage(), $e);
        } catch (\JsonException $e) {
            if ($this->logger !== null) $this->logger->error("Erro ao codificar JSON para '{$url}': " . $e->getMessage());
            throw new SiegAuthException("Erro ao codificar JSON para '{$url}'.", 0, $e);
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            if ($this->logger !== null) $this->logger->error("Erro ao desserializar a resposta JSON de '{$url}': " . $e->getMessage());
            throw new SiegAuthException("Erro ao desserializar a resposta JSON de '{$url}'.", 0, $e);
        }
        
        if ($this->logger !== null) $this->logger->debug("Resposta HTTP de '{$url}' desserializada com sucesso.");

        return is_array($decoded) ? $decoded : [];
    }
}
