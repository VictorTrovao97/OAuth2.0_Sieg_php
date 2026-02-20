<?php

declare(strict_types=1);

namespace SiegAuth;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use SiegAuth\Exceptions\SiegAuthException;
use SiegAuth\Exceptions\SiegHttpException;

/**
 * Cliente de alto nível que encapsula todo o fluxo OAuth 2.0 com a SIEG, incluindo auto-refresh.
 */
final class SiegIntegrationClient
{
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly SiegOAuthOptions $options,
        private readonly SiegTokenStoreInterface $tokenStore
    ) {
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
        $level = $accessLevel ?? $this->options->defaultAccessLevel ?? 'read';
        $base = rtrim($this->options->baseAuthorizeUrl, '/');
        $params = http_build_query([
            'clientId' => $this->options->clientId,
            'state' => $state,
            'accessLevel' => $level,
        ]);
        return $base . (str_contains($base, '?') ? '&' : '?') . $params;
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

        $url = rtrim($this->options->baseApiUrl, '/') . '/generate-token';
        $payload = [
            'AccessToken' => $temporaryAccessToken,
            'State' => $state,
            'RedirectUri' => $this->options->redirectUri,
        ];

        $response = $this->postJson($url, $payload);
        $data = $response['Data'] ?? null;

        if (empty($response['IsSuccess']) || $data === null || trim($data['AccessToken'] ?? '') === '') {
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
    }

    /**
     * Obtém um access token SIEG válido para a conta. Se estiver próximo de expirar, faz auto-refresh.
     *
     * @param string $accountKey Identificador da conta no sistema emissor.
     * @return string Access token para uso nas APIs da SIEG (header Authorization: Bearer &lt;token&gt;).
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
            $this->refreshTokenInternal($token->accessToken);
            $renewed = new SiegToken(
                $token->accessToken,
                (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+30 days')
            );
            $this->tokenStore->saveToken($accountKey, $renewed);
            $token = $renewed;
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
            return;
        }

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
        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Client-Id' => $this->options->clientId,
                    'X-Secret-Key' => $this->options->secretKey,
                ],
                'json' => $payload,
            ]);
        } catch (GuzzleException $e) {
            $statusCode = 0;
            $body = '';
            if ($e->hasResponse() && $e->getResponse() !== null) {
                $statusCode = $e->getResponse()->getStatusCode();
                $body = (string) $e->getResponse()->getBody();
            }
            throw new SiegHttpException(
                $statusCode,
                $body,
                'Chamada HTTP para \'' . $url . '\' falhou: ' . $e->getMessage(),
                $e
            );
        }

        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new SiegAuthException('Não foi possível desserializar a resposta JSON de \'' . $url . '\'.');
        }

        return $decoded;
    }
}
