# Colocar o Data Fox em produção (Laravel Cloud + domínio no Registro.br)

Roteiro do zero até o sistema no ar no seu domínio. Troque `acervo.seudominio.com.br` pelo endereço que você quer usar
(pode ser um subdomínio, como `acervo.`, ou o domínio principal).

> **Como fica**: em `/` abre o Data Fox com a mesma interface da versão web (o servidor guarda os dados e confere
> as permissões); em `/admin` fica a administração do sistema (usuários, só para administradores) e a tela de
> conta de cada pessoa (senha, e-mail, duas etapas), aberta pelo **Meu perfil** do Data Fox.
>
> O código no branch `main` é testado a cada push (GitHub Actions → **CI**): instalação, migrations no PostgreSQL,
> testes de acesso e da jornada do usuário, e um build igual ao de produção. Só faça deploy com o CI verde.

## 1. Criar a aplicação

1. Entre em [cloud.laravel.com](https://cloud.laravel.com) → **New application** → conecte o GitHub e escolha
   `bpsaraiva13-blip/data-fox`, branch `main`. Região: a mais próxima do Brasil disponível (ex.: US East).
2. **General settings** → PHP **8.4**.

## 2. Banco, arquivos e fila

No canvas do ambiente:

| O quê | Onde | Como |
|---|---|---|
| Banco | **Add database** | *Laravel Serverless Postgres*, mesma região. O Cloud injeta `DB_*`. |
| Fotos e backups | **Add bucket** | Bucket **privado**, marcado como disco padrão. O Cloud injeta `FILESYSTEM_DISK` e as credenciais. |
| Fila | App cluster → **Background processes** | `php artisan queue:work --tries=3 --sleep=3` |
| Agendador | App cluster → **Scheduler** | Ligado (backup diário 03:30 e limpeza da lixeira 04:10). |

Use a fila `database` (padrão do `.env.example`). Ela aguenta o job mais longo (importar .zip, até 1 hora);
o tempo de reentrega já vem configurado (`DB_QUEUE_RETRY_AFTER=3700`).

Mantenha o App cluster com **1 réplica** no começo. Os uploads ficam temporariamente no disco da instância antes de
irem para o bucket; com várias réplicas, configure `LIVEWIRE_UPLOAD_DISK` com o nome do disco do bucket
(e libere CORS no bucket, se o Cloud permitir).

## 3. Comandos de build e deploy

**Deployments** → Build commands (substitua o padrão, o Data Fox não usa `npm`):

```sh
composer install --no-dev --optimize-autoloader
php artisan optimize
php artisan filament:optimize
```

Deploy commands:

```sh
php artisan migrate --force
```

## 4. Variáveis de ambiente

**Settings → Environment variables** (as de banco e bucket o Cloud já injeta):

```ini
APP_NAME="Data Fox"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://acervo.seudominio.com.br
APP_LOCALE=pt_BR
APP_FALLBACK_LOCALE=pt_BR
APP_TIMEZONE=America/Sao_Paulo
LOG_LEVEL=warning

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database

# E-mail: convites, "esqueci a senha" e troca de e-mail dependem disto.
# Exemplo com Resend (resend.com); qualquer SMTP serve.
MAIL_MAILER=smtp
MAIL_HOST=smtp.resend.com
MAIL_PORT=587
MAIL_USERNAME=resend
MAIL_PASSWORD=re_xxxxxxxx
MAIL_FROM_ADDRESS=naoresponda@seudominio.com.br
MAIL_FROM_NAME="Data Fox"

# IA (opcional; sem a chave o sistema funciona sem as funções de IA)
ANTHROPIC_API_KEY=sk-ant-...

# Login com Google (opcional, ver passo 7)
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
```

**O Cloud não cria a `APP_KEY`.** Gere em **Commands** com `php artisan key:generate --show`, copie a linha
`base64:...` e cadastre `APP_KEY=base64:...` nas variáveis (sem ela, toda página dá erro 500). **Nunca troque o `APP_KEY` depois**: ele criptografa os segredos
da verificação em duas etapas.

Depois de mudar variáveis, faça **Redeploy**.

## 5. Primeiro deploy e primeiro administrador

1. **Deploy**. Ao terminar, o ambiente ganha um endereço `*.laravel.cloud` para testar.
2. Aba **Commands**:
   ```sh
   php artisan datafox:admin --name="Bruno" --email="voce@email.com" --password="uma-senha-forte"
   ```
3. Abra o endereço `*.laravel.cloud`, entre e crie o seu acervo (botão **Criar meu acervo**).
   A equipe entra por **Equipe → Convidar**: o link do convite leva a pessoa a criar a conta e já cai no acervo.
4. Recomendado: em **Meu perfil**, ative a verificação em duas etapas.

## 6. Apontar o domínio (Registro.br)

1. No Cloud: **Network** do ambiente → **Add domain** → digite `acervo.seudominio.com.br`.
   - Marque que o DNS **não** é Cloudflare.
   - Se quiser zero indisponibilidade, escolha a opção sem interrupção: o Cloud mostra primeiro os registros de
     verificação (`TXT _cf-custom-hostname...` e `_acme-challenge...`) e depois o de origem (**A**).
2. No [registro.br](https://registro.br) → **Painel** → seu domínio → **DNS** → **Editar zona**.
   - Se o domínio ainda usa os servidores do Registro.br no modo básico, ative o **modo avançado** de DNS para poder
     criar registros TXT/CNAME (a ativação pode demorar um pouco).
   - Crie cada registro exatamente como o Cloud mostrar. No campo de nome, digite só a parte antes do domínio:
     | Registro no Cloud | Nome no Registro.br | Tipo |
     |---|---|---|
     | `acervo.seudominio.com.br` | `acervo` | A (valor: o IP mostrado) |
     | `_cf-custom-hostname.acervo.seudominio.com.br` | `_cf-custom-hostname.acervo` | TXT |
     | `_acme-challenge.acervo.seudominio.com.br` | `_acme-challenge.acervo` | TXT ou CNAME (o que o Cloud pedir) |
     Para o domínio principal (sem subdomínio), deixe o nome em branco.
3. Volte ao Cloud e clique em **Refresh** até ficar **Connected** (normalmente até 15 minutos; o Cloud tenta por
   até 12 horas). O certificado HTTPS é emitido sozinho.
4. Confira `APP_URL=https://acervo.seudominio.com.br` e faça **Redeploy**.

Para conferir de um terminal: `dig A acervo.seudominio.com.br +short` e `dig TXT _acme-challenge.acervo.seudominio.com.br +short`.

### E-mail com o seu domínio

O provedor de e-mail (Resend, Postmark, SES…) pede alguns registros (SPF/DKIM, às vezes MX) para enviar como
`@seudominio.com.br`. Crie-os na mesma zona do Registro.br, do mesmo jeito. Sem isso, os convites podem cair no spam.

## 7. Login com Google (opcional)

1. [console.cloud.google.com](https://console.cloud.google.com) → APIs e serviços → **Credenciais** →
   **ID do cliente OAuth** → Aplicativo da Web.
2. URI de redirecionamento: `https://acervo.seudominio.com.br/auth/google/callback`.
3. Cadastre `GOOGLE_CLIENT_ID` e `GOOGLE_CLIENT_SECRET` no Cloud e faça **Redeploy**. O botão aparece sozinho.

Apple: veja a seção no `README.md` (exige conta Apple Developer).

## 8. Trazer os dados da versão web

1. Na versão web, **Backup completo (.zip)** com as fotos (para acervos grandes, uma caixa por vez).
2. Envie o `.zip` para o bucket, na pasta `imports/`.
3. **Commands** (confira com `--dry-run` e depois importe):
   ```sh
   php artisan datafox:import-web s3:imports/datafox-backup-acervo-AAAA-MM-DD.zip --user=voce@email.com --acervo="Nome do acervo" --dry-run
   php artisan datafox:import-web s3:imports/datafox-backup-acervo-AAAA-MM-DD.zip --user=voce@email.com --acervo="Nome do acervo"
   ```
   Vêm caixas, peças, fotos (com miniaturas), histórico, movimentações, colecionadores e coleções, com os mesmos
   ids da versão web. Pode repetir sem duplicar. Os nomes de quem cadastrou aparecem no histórico.
   As pessoas da equipe precisam de conta no Laravel: convide cada uma pela tela **Equipe**.

Backup diário: às 03:15 o servidor grava um JSON de cada acervo em `backups/interface/<acervo>/` no bucket
(últimos 14). O **Backup completo (.zip)** da interface continua funcionando para baixar tudo com as fotos.

## 9. Conferência final

- [ ] Abrir `https://acervo.seudominio.com.br` → tela de login com cadeado (HTTPS).
- [ ] Entrar, abrir Peças, Caixas, Pendências, Lixeira e Colecionadores.
- [ ] Convidar um e-mail seu de teste em **Equipe → Convidar por e-mail**; o e-mail chega; aceitar cria a conta.
- [ ] Cadastrar uma peça com foto; com a IA ligada, a ficha é preenchida em segundo plano.
- [ ] Em **Usuários**, ver o último acesso.
- [ ] No dia seguinte: o backup diário aparece no bucket (`backups/`).
