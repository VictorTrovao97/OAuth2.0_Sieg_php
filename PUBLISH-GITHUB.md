# Como subir o projeto no GitHub

Siga os passos abaixo para publicar este repositório no GitHub.

## 1. Criar o repositório no GitHub

1. Acesse [github.com](https://github.com) e faça login.
2. Clique em **New** (ou **+** → **New repository**).
3. Preencha:
   - **Repository name:** por exemplo `sieg-auth-php`
   - **Description:** opcional, ex.: "SDK OAuth 2.0 SIEG para PHP (emissores de nota fiscal)"
   - **Visibility:** Public ou Private
   - **Não** marque "Add a README file", "Add .gitignore" nem "Choose a license" (o projeto já tem esses arquivos).
4. Clique em **Create repository**.

## 2. Inicializar o Git no projeto (se ainda não tiver)

No terminal, na pasta do projeto:

```bash
cd "c:\Users\Victor\OneDrive\Ambiente de Trabalho\sieg-auth-php"

git init
```

## 3. Adicionar o repositório remoto do GitHub

Use a URL do repositório que você criou (substitua `SEU_USUARIO` e `sieg-auth-php` se tiver alterado):

```bash
git remote add origin https://github.com/SEU_USUARIO/sieg-auth-php.git
```

Se usar SSH:

```bash
git remote add origin git@github.com:SEU_USUARIO/sieg-auth-php.git
```

## 4. Adicionar arquivos, fazer commit e enviar

```bash
git add .
git status
git commit -m "Versão inicial do SDK Sieg.Auth PHP (OAuth 2.0)"
git branch -M main
git push -u origin main
```

Se o GitHub pedir autenticação, use seu usuário e um **Personal Access Token** (em Settings → Developer settings → Personal access tokens) no lugar da senha.

## 5. (Opcional) Criar uma tag de versão

Para marcar a versão 1.0.0:

```bash
git tag v1.0.0
git push origin v1.0.0
```

Isso ajuda quem instala via Composer e futura publicação no Packagist.

---

## Resumo dos comandos

```bash
cd "c:\Users\Victor\OneDrive\Ambiente de Trabalho\sieg-auth-php"
git init
git remote add origin https://github.com/SEU_USUARIO/sieg-auth-php.git
git add .
git commit -m "Versão inicial do SDK Sieg.Auth PHP (OAuth 2.0)"
git branch -M main
git push -u origin main
git tag v1.0.0
git push origin v1.0.0
```

Substitua `SEU_USUARIO` pelo seu usuário ou organização no GitHub.
