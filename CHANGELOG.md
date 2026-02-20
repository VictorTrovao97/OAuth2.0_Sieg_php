# Changelog

Todas as mudanças notáveis neste projeto serão documentadas neste arquivo.

## [1.0.0] - 2026-02-20

### Adicionado

- SDK de autenticação OAuth 2.0 da SIEG para PHP (emissores de nota fiscal).
- `SiegOAuthOptions` — configuração (clientId, secretKey, redirectUri, URLs, níveis de acesso, threshold de auto-refresh).
- `SiegToken` — modelo de token com `isExpired()` e tolerância para antecipar renovação.
- `SiegTokenStoreInterface` e `InMemorySiegTokenStore` — armazenamento de tokens por conta.
- `SiegIntegrationClient` — fluxo completo:
  - `getAuthorizationUrl()` — URL para redirecionar o usuário à tela de autorização SIEG.
  - `completeAuthorization()` — troca do token temporário (callback) pelo token permanente via `generate-token`.
  - `getValidAccessToken()` — retorna token válido com auto-refresh (renovação no 25º dia, +30 dias).
  - `revoke()` — revoga integração e remove token do store.
- Exceções `SiegAuthException` e `SiegHttpException` para tratamento de erros.
- Documentação no README com guia rápido e exemplo de store em banco (PDO).
