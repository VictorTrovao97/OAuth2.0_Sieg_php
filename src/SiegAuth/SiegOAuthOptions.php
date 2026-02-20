<?php

declare(strict_types=1);

namespace SiegAuth;

/**
 * Opções de configuração para autenticação OAuth 2.0 com a SIEG.
 */
final class SiegOAuthOptions
{
    /** ClientId fornecido pela SIEG. */
    public string $clientId = '';

    /** Secret Key fornecida pela SIEG (header X-Secret-Key). */
    public string $secretKey = '';

    /** URL de callback configurada na SIEG (RedirectUri). */
    public ?string $redirectUri = null;

    /** URL base da tela de autorização OAuth 2.0. */
    public string $baseAuthorizeUrl = 'https://app.sieg.com/AuthorizeAccess.aspx';

    /** URL base dos endpoints OAuth (generate-token, refresh, revoke). */
    public string $baseApiUrl = 'https://api.sieg.com/api/v1/oauth/';

    /** Nível de acesso padrão (read, write, fullAccess). */
    public ?string $defaultAccessLevel = 'write';

    /** Antecedência para auto-refresh (segundos). Padrão: 5 dias — refresh no 25º dia para renovar por mais 30 dias. */
    public int $autoRefreshThresholdSeconds = 432000; // 5 dias (30 - 5 = 25º dia)

    public function __construct(array $options = [])
    {
        foreach ($options as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
    }
}
