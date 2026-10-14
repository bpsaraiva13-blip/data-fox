# Data Fox

Sistema de gestão de acervo de antiguidades, colecionismo e raridades.
Laravel 13 + Filament 5, PostgreSQL, fotos em object storage (S3/R2) e IA da Anthropic.

## O que tem

- **Peças**: fotos, Nº de tombo automático (`ACV-2026-0001`), ficha de catalogação, tags, caixa, histórico de quem cadastrou e alterou.
- **IA**: analisa as fotos e sugere título, época, origem, material, estado, descrição e tags; busca inteligente por descrição; conferência se uma foto nova é a mesma peça de alguma já cadastrada.
- **Cadastro em lote**: todas as fotos de uma caixa de uma vez, agrupadas pelo nome do arquivo, uma por peça ou em sequência. A IA preenche as fichas em segundo plano.
- **Unir peças**: junta frente e verso (ou partes) que ficaram separados, com sugestões automáticas.
- **Comparar imagem**: ranking visual (dHash + cor) e conferência opcional com IA, com comparação deslizante.
- **Compartilhar no WhatsApp**: cartão de imagem pronto (1080×1350) e texto formatado.
- **Caixas** em cartões ou lista, ordenadas pelo número na frente do nome; atalhos no menu lateral.
- **Perfis de acesso**: Administrador, Editor e Somente leitura.
- **Importação** do backup `.zip` da versão anterior (peças, caixas, tags, histórico e fotos).

## Deploy no Laravel Cloud

1. Em [cloud.laravel.com](https://cloud.laravel.com), crie uma aplicação a partir deste repositório (`main`).
2. No canvas do ambiente:
   - **Add database** → *Laravel Serverless Postgres* (mesma região).
   - **Add bucket** → crie um bucket **privado** e marque como disco padrão.
3. **Variáveis de ambiente** (Settings → Environment variables):
   ```ini
   APP_NAME="Data Fox"
   APP_LOCALE=pt_BR
   APP_FALLBACK_LOCALE=pt_BR
   APP_TIMEZONE=America/Sao_Paulo
   CACHE_STORE=database
   SESSION_DRIVER=database
   QUEUE_CONNECTION=database
   ANTHROPIC_API_KEY=sk-ant-...        # opcional: sem ela o sistema funciona sem IA
   ACERVO_AI_MODEL=claude-sonnet-5-5
   ACERVO_AI_SEARCH_MODEL=claude-haiku-5-5
   ```
   O Cloud injeta sozinho as variáveis do banco (`DB_*`) e do bucket (`FILESYSTEM_DISK` e credenciais).
4. **Build commands**:
   ```sh
   composer install --no-dev --optimize-autoloader
   php artisan optimize
   ```
5. **Deploy commands**:
   ```sh
   php artisan migrate --force
   ```
6. **Fila (análises da IA em segundo plano)**: no App cluster, adicione um *background process*:
   ```sh
   php artisan queue:work --tries=4 --timeout=150 --sleep=3
   ```
   (Ou use as *Managed queues* do Cloud: ele troca `QUEUE_CONNECTION` para `cloud` automaticamente.)
7. Faça o deploy. Depois, em **Commands**, crie o primeiro administrador:
   ```sh
   php artisan datafox:admin --name="Bruno" --email="voce@email.com" --password="uma-senha-forte"
   ```
8. Acesse a URL do ambiente e entre com esse usuário. Em **Administração → Usuários** você cadastra a equipe.

## Trazer os dados da versão anterior

1. Na versão anterior, clique em **Backup completo (.zip)** (barra lateral) e salve o arquivo.
   Para acervos grandes, exporte caixa por caixa.
2. Envie o `.zip` para o bucket, na pasta `imports/` (pelo painel do bucket no Laravel Cloud ou com qualquer cliente S3).
3. Em **Commands** do Laravel Cloud, rode:
   ```sh
   php artisan datafox:import s3:imports/datafox-backup-acervo-2026-10-14.zip --user=voce@email.com
   ```
   Troque `s3` pelo nome do disco do bucket, se for outro. Use `--dry-run` para conferir antes.
   O comando pode ser repetido: o que já foi importado é ignorado. Tombos, datas de cadastro, autores
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

- `app/Filament/Resources` — Peças, Caixas, Tags, Atividade, Usuários
- `app/Filament/Pages` — Cadastro em lote, Comparar imagem
- `app/Services` — `CuratorAI` (Anthropic), `ImageSignature` (dHash), `PhotoManager`, `ItemMerger`, `BatchUploader`, `ShareCard`
- `app/Jobs/AnalyzeItem` — análise da IA em fila, com novas tentativas
- `app/Console/Commands` — `datafox:admin`, `datafox:import`
- `public/css/datafox.css`, `public/js/datafox.js` — identidade visual e microinterações
- `config/acervo.php` — categorias, estados, cores das caixas, modelos de IA

Fonte Plus Jakarta Sans (SIL Open Font License) em `resources/fonts`.
