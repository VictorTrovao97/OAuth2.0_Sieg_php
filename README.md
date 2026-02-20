# Sieg.Auth SDK PHP (OAuth 2.0 SIEG)

Biblioteca PHP para autenticação OAuth 2.0 da SIEG em sistemas emissores de nota fiscal, com renovação automática do token.

## Requisitos

- PHP 8.1+
- Composer
- Extensão `json`
- Guzzle HTTP (instalado via Composer)

## Instalação

```bash
composer require sieg/auth
```

Se o pacote não estiver no Packagist, use repositório por path no seu `composer.json`:

```json
{
    "repositories": [
        { "type": "path", "url": "./sieg-auth-php" }
    ],
    "require": {
        "sieg/auth": "@dev"
    }
}
```

Depois execute:

```bash
composer update
```

## Configuração

Configure o cliente com as credenciais fornecidas pela SIEG e a URL de callback do seu sistema:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use SiegAuth\SiegOAuthOptions;
use SiegAuth\SiegIntegrationClient;
use SiegAuth\InMemorySiegTokenStore;
use GuzzleHttp\Client;

$options = new SiegOAuthOptions([
    'clientId'           => 'SEU_CLIENT_ID',           // Fornecido pela SIEG
    'secretKey'           => 'SUA_SECRET_KEY',          // Fornecida pela SIEG
    'redirectUri'         => 'https://seu-sistema.com/oauth/callback',  // URL de callback configurada na SIEG
    'defaultAccessLevel'  => 'read',                    // read, write ou fullAccess
]);

$httpClient = new Client(['timeout' => 10]);
$tokenStore = new InMemorySiegTokenStore();
$sieg = new SiegIntegrationClient($httpClient, $options, $tokenStore);
```

**Opções disponíveis:**

| Opção | Obrigatório | Descrição |
|-------|-------------|-----------|
| `clientId` | Sim | Identificador do cliente (SIEG) |
| `secretKey` | Sim | Chave secreta (SIEG) |
| `redirectUri` | Sim | URL de callback após autorização |
| `defaultAccessLevel` | Não | `read`, `write` ou `fullAccess` (padrão: `read`) |
| `baseAuthorizeUrl` | Não | URL da tela de autorização (padrão: SIEG) |
| `baseApiUrl` | Não | URL base dos endpoints OAuth (padrão: SIEG) |
| `autoRefreshThresholdSeconds` | Não | Antecedência para renovar o token em segundos (padrão: 5 dias) |

Em produção, use um armazenamento persistente (banco de dados ou cache) em vez de `InMemorySiegTokenStore` — veja a seção [Armazenamento em produção](#armazenamento-em-produção) abaixo.

---

## Instruções para emissores

### 1. Iniciar a conexão com a SIEG

Gere um `state` e redirecione o usuário para a URL de autorização:

```php
$state = bin2hex(random_bytes(16));
// Salve no seu sistema a relação $state -> empresaId (sessão, banco, etc.)

$url = $sieg->getAuthorizationUrl($state, 'read');
header('Location: ' . $url);
exit;
```

### 2. Callback (RedirectUri)

A SIEG redireciona o usuário de volta para a sua `redirectUri` com `?accessToken=...&state=...`. No script que trata essa URL:

```php
$accessToken = $_GET['accessToken'] ?? '';
$state      = $_GET['state'] ?? '';
$empresaId  = /* recupere do $state que você salvou no passo 1 */;

$sieg->completeAuthorization($empresaId, $accessToken, $state);
echo 'Integração SIEG concluída para a empresa ' . $empresaId;
```

### 3. Obter token para chamadas às APIs SIEG

Sempre que for chamar as APIs fiscais da SIEG, use o token retornado no header `Authorization: Bearer <token>`. O SDK renova o token automaticamente quando necessário (por exemplo, no 25º dia):

```php
$accessToken = $sieg->getValidAccessToken($empresaId);
// Use $accessToken nas requisições à API SIEG
```

### 4. Revogar integração

Para desvincular a integração SIEG de uma conta:

```php
$sieg->revoke($empresaId);
```

---

## Armazenamento em produção

O `InMemorySiegTokenStore` não persiste os tokens (perde ao reiniciar). Em produção, implemente `SiegAuth\SiegTokenStoreInterface` usando banco de dados ou cache.

Exemplo com PDO:

```php
use SiegAuth\SiegToken;
use SiegAuth\SiegTokenStoreInterface;

class DbSiegTokenStore implements SiegTokenStoreInterface
{
    public function __construct(private \PDO $pdo) {}

    public function getToken(string $accountKey): ?SiegToken
    {
        $stmt = $this->pdo->prepare('SELECT access_token, expires_at, refresh_token FROM sieg_tokens WHERE account_key = ?');
        $stmt->execute([$accountKey]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;
        return new SiegToken(
            $row['access_token'],
            new \DateTimeImmutable($row['expires_at']),
            $row['refresh_token'] ?: null
        );
    }

    public function saveToken(string $accountKey, SiegToken $token): void
    {
        $stmt = $this->pdo->prepare('REPLACE INTO sieg_tokens (account_key, access_token, expires_at, refresh_token) VALUES (?, ?, ?, ?)');
        $stmt->execute([
            $accountKey,
            $token->accessToken,
            $token->expiresAt->format('Y-m-d H:i:s'),
            $token->refreshToken ?? ''
        ]);
    }

    public function deleteToken(string $accountKey): void
    {
        $this->pdo->prepare('DELETE FROM sieg_tokens WHERE account_key = ?')->execute([$accountKey]);
    }
}
```

Tabela sugerida:

```sql
CREATE TABLE sieg_tokens (
    account_key VARCHAR(255) PRIMARY KEY,
    access_token TEXT NOT NULL,
    expires_at DATETIME NOT NULL,
    refresh_token TEXT
);
```

Uso:

```php
$tokenStore = new DbSiegTokenStore($pdo);
$sieg = new SiegIntegrationClient($httpClient, $options, $tokenStore);
```

---

## Licença

MIT.
