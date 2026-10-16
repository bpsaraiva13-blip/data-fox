# Data Fox

Sistema de gestão de acervo de antiguidades, colecionismo e raridades.
Laravel 13 + Filament 5, PostgreSQL, fotos em object storage (S3/R2) e IA da Anthropic.

## O que tem

- **Peças**: fotos, Nº de tombo automático (`ACV-2026-0001`), ficha de catalogação, tags, caixa, histórico de quem cadastrou e alterou.
- **IA**: analisa as fotos e sugere título, época, origem, material, estado, descrição e tags; busca inteligente por descrição; conferência se uma foto nova é a mesma peça de alguma já cadastrada.
- **Cadastro em lote**: todas as fotos de uma caixa de uma vez, agrupadas pelo nome do arquivo, uma por peça ou em sequência. A IA preenche as fichas em segundo plano.
  Também aceita um **.zip com pastas** (até 4 GB): cada pasta vira uma caixa e cada subpasta uma peça.
- **Pendências**: falhas da IA (reprocessar todas de uma vez), peças sem foto, sem caixa, título provisório, possíveis duplicatas;
  dispensar ou mandar para a lixeira em lote.
- **Unir peças**: junta frente e verso (ou partes) que ficaram separados, com sugestões automáticas.
- **Comparar imagem**: ranking visual (dHash + cor) e conferência opcional com IA, com comparação deslizante.
- **Compartilhar no WhatsApp**: cartão de imagem pronto (1080×1350) e texto formatado.
- **Caixas** em cartões ou lista, ordenadas pelo número na frente do nome; etiqueta QR da caixa e catálogo em PDF.
- **Vários acervos**: cada pessoa cria o seu ou participa de acervos compartilhados (troca no seletor do topo).
- **Equipe**: convite por e-mail (ou link/WhatsApp), perfis Administrador, Editor, Somente leitura e Só vitrine,
  permissão por caixa, bloquear/desbloquear acesso; cada um edita o próprio perfil (foto, WhatsApp, senha).
- **Enviar entre acervos**: peças ou caixas inteiras, movendo ou copiando (fotos, documentos e movimentações vão juntos).
  Envios grandes rodam na fila e avisam no sininho.
- **Caixa vinculada**: a mesma caixa aparece em vários acervos, sempre igual; as peças continuam no acervo de origem.
- **Movimentações**: empréstimo, venda, devolução, com data e responsável; várias peças de uma vez.
- **Colecionadores**: cadastro com contato, endereço completo (CEP preenche o endereço), interesses e histórico de movimentações.
- **Lixeira**: peças, fotos, caixas e colecionadores excluídos ficam 30 dias para restaurar; depois são apagados com os arquivos.
- **Coleções** temáticas, **vitrine** (pública ou só para convidados), **conferência** de caixa com leitor de etiqueta,
  **fotografar peças** com a câmera, **pendências** (sem foto, sem caixa, falha na IA, possíveis duplicatas),
  **documentos anexos** (nota fiscal, laudo…), **registro de acesso** (quem viu, exportou, gerou PDF).
- **Backup**: diário e automático (agendador) + botão "Gerar backup agora"; mesmo formato do backup da versão web.
- **Importação** do backup `.zip` da versão anterior (peças, caixas, tags, histórico e fotos).

## Interface

Em `/` roda a **mesma interface da versão web** (`resources/app/data-fox.html`, o mesmo arquivo publicado no
claude.ai). O `public/js/datafox-runtime.js` entrega a ela o que no claude.ai vem do `window.claude`
(banco, usuário, fotos, downloads e IA) usando a API do Laravel:

| Rota | O que faz |
|---|---|
| `POST /api/db/sync` · `POST /api/db/write` | lê/sincroniza e grava documentos (`app/Support/PageStore.php` confere as permissões) |
| `POST /api/assets` · `GET /_blob/{id}` | fotos e anexos no bucket privado |
| `POST /api/ai/json` | IA com a chave só no servidor |
| `POST /api/people/profiles` · `GET /api/people/search` | nomes e busca de pessoas para a equipe |

Permissões no servidor: admin/editor gravam, somente leitura só lê, "só vitrine" vê só as peças expostas,
caixas permitidas limitam peças e caixas, caixa vinculada libera só as peças dela, e só administradores mexem em
equipe, convites e configurações. `/admin` (Filament) é a administração do sistema e a tela de conta.

Ao mudar a interface, edite `resources/app/data-fox.html` (e publique a mesma versão no claude.ai, se quiser).
O código específico do Laravel fica atrás de `SRV` (`window.DATAFOX_SERVER`).

## Deploy no Laravel Cloud

O passo a passo completo (banco, bucket, fila, agendador, variáveis, primeiro administrador, domínio no
Registro.br, Google e importação dos dados da versão web) está em **[DEPLOY.md](DEPLOY.md)**.

Resumo:

- Build: `composer install --no-dev --optimize-autoloader && php artisan optimize && php artisan filament:optimize`
- Deploy: `php artisan migrate --force`
- Fila: background process `php artisan queue:work --tries=3 --sleep=3` · Agendador ligado
- Primeiro acesso: `php artisan datafox:admin --name=... --email=... --password=...`

## Testes

`php artisan test` (PostgreSQL, ver `.github/workflows/ci.yml`). Cobrem a jornada (primeiro administrador, acervo,
convite, uso por perfil), as regras de acesso (isolamento entre acervos, caixas permitidas, bloqueio, vitrine,
login com Google e duas etapas), o cadastro, a lixeira, o lote por .zip e a importação do backup da versão web.

## Acesso: senha, Google, Apple e verificação em duas etapas

- **Senha**: todo mundo entra pela tela de login (`/login`). A sessão expira após `SESSION_LIFETIME` minutos sem uso
  (padrão 120). Para pedir login sempre que o navegador for fechado, use `SESSION_EXPIRE_ON_CLOSE=true`.
- **Duas etapas** (opcional): cada pessoa ativa em **Meu perfil** com um app autenticador (Google Authenticator,
  1Password, Authy…). O administrador do sistema pode desligar em **Usuários** se alguém perder o celular.
- **Entrar com Google / Apple**: só entra quem já tem conta com o mesmo e-mail ou tem convite pendente para ele.
  Os botões aparecem sozinhos quando as variáveis abaixo estão preenchidas.
  Quem ativou as duas etapas digita o código do app também ao entrar com Google/Apple. O Google só é ligado a uma
  conta cujo e-mail já foi confirmado, e trocar o e-mail em **Meu perfil** exige confirmar pelo novo endereço.
- Em **Usuários**: último acesso (e como entrou), desativar acesso, desconectar de todos os aparelhos.

### Google
1. [console.cloud.google.com](https://console.cloud.google.com) → APIs e serviços → **Credenciais** → Criar credenciais →
   **ID do cliente OAuth** → Aplicativo da Web.
2. URI de redirecionamento autorizado: `https://SEU-DOMINIO/auth/google/callback`.
3. Variáveis:
   ```ini
   GOOGLE_CLIENT_ID=....apps.googleusercontent.com
   GOOGLE_CLIENT_SECRET=...
   ```

### Apple (precisa de conta Apple Developer)
1. [developer.apple.com](https://developer.apple.com/account) → Certificates, IDs & Profiles:
   - **Identifiers** → um *App ID* com "Sign in with Apple" e um **Services ID** (ex.: `com.seudominio.datafox.web`);
     no Services ID, configure o domínio e o Return URL `https://SEU-DOMINIO/auth/apple/callback`.
   - **Keys** → nova chave com "Sign in with Apple"; baixe o arquivo `.p8` e anote o Key ID.
2. Variáveis:
   ```ini
   APPLE_CLIENT_ID=com.seudominio.datafox.web     # o Services ID
   APPLE_TEAM_ID=XXXXXXXXXX
   APPLE_KEY_ID=XXXXXXXXXX
   APPLE_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----"   # conteúdo do .p8
   ```
   A Apple só informa o e-mail no primeiro login; depois o sistema reconhece a pessoa pelo identificador da Apple.

## Trazer os dados da versão anterior

1. Na versão anterior, clique em **Backup completo (.zip)** (barra lateral) e salve o arquivo.
   Para acervos grandes, exporte caixa por caixa.
2. Envie o `.zip` para o bucket, na pasta `imports/` (pelo painel do bucket no Laravel Cloud ou com qualquer cliente S3).
3. Em **Commands** do Laravel Cloud, rode:
   ```sh
   php artisan datafox:import s3:imports/datafox-backup-acervo-2026-10-14.zip --user=voce@email.com --acervo="Azzurra"
   ```
   Troque `s3` pelo nome do disco do bucket, se for outro. Use `--dry-run` para conferir antes.
   `--acervo` escolhe (ou cria) o acervo de destino; repita o comando com o backup de cada acervo da versão web.
   Vêm também colecionadores, movimentações e coleções. O comando pode ser repetido: o que já foi importado é ignorado. Tombos, datas de cadastro, autores
   (pelo nome) e o histórico são preservados.

## Desenvolvimento local

```sh
composer install
cp .env.example .env && php artisan key:generate
# .env: DB_CONNECTION=pgsql (ou sqlite para testes rápidos), ACERVO_DISK=public
php artisan migrate
php artisan storage:link
php artisan datafox:admin
composer run dev   # servidor + fila + logs
```

## Custo da IA (API Anthropic)

| Operação | Modelo | Custo aproximado |
|---|---|---|
| Analisar uma peça (até 5 fotos de 520 px) | Sonnet | ~US$ 0,01 |
| Lote de 1.000 peças | Sonnet | ~US$ 9 |
| Busca inteligente | Haiku | ~US$ 0,001 por busca (custo fixo: envia no máximo 80 candidatas pré-filtradas) |
| Conferir imagem com IA | Sonnet | ~US$ 0,007 |

Ajuste os modelos em `ACERVO_AI_MODEL` e `ACERVO_AI_SEARCH_MODEL`.

## Estrutura

- `app/Filament/Resources` — Peças, Caixas, Coleções, Movimentações, Colecionadores, Tags, Atividade, Registro de acesso, Equipe, Convites, Usuários
- `app/Filament/Pages` — Cadastro em lote, Fotografar peças, Conferência, Pendências, Lixeira, Comparar imagem, perfil e acervo
- `app/Services` — `CuratorAI` (Anthropic), `ImageSignature` (dHash), `PhotoManager`, `ItemMerger`, `ItemSplitter`,
  `ItemTransfer`, `BatchUploader`, `ZipImporter`, `ShareCard`, `PdfMaker`, `BackupMaker`
- `app/Models/Concerns/BelongsToAcervo` — filtro por acervo (inclui caixas vinculadas)
- `app/Jobs` — `AnalyzeItem` (IA), `MakeBackup`, `TransferItems`, `ImportZip`
- `prototipo/` — a versão web (claude.ai) usada como protótipo
- `app/Console/Commands` — `datafox:admin`, `datafox:import`, `datafox:backup`
- `public/css/datafox.css`, `public/js/datafox.js` — identidade visual e microinterações
- `config/acervo.php` — categorias, estados, cores das caixas, modelos de IA

Pacotes extras: `laravel/socialite` e `socialiteproviders/apple` (login social), `barryvdh/laravel-dompdf` (PDFs), `chillerlan/php-qrcode` (etiquetas), `league/flysystem-aws-s3-v3` (bucket).

Fonte Plus Jakarta Sans (SIL Open Font License) em `resources/fonts`.
