<?php

namespace App\Services;

use App\Models\Item;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Integração com a API da Anthropic (Messages API) para:
 *  - analisar fotos e sugerir a ficha de catalogação
 *  - busca semântica no acervo
 *  - conferir se uma foto nova é a mesma peça de itens já cadastrados
 */
class CuratorAI
{
    public function enabled(): bool
    {
        return filled(config('acervo.ai.api_key'));
    }

    /**
     * @param  string[]  $images  conteúdo binário das imagens (JPEG/PNG/WebP)
     */
    public function analyze(array $images, string $context = ''): array
    {
        $cats = implode(' | ', config('acervo.categories'));
        $conds = implode(' | ', config('acervo.conditions'));

        $prompt = <<<TXT
        Você é um perito em antiguidades, colecionismo e raridades, catalogando uma peça para um acervo brasileiro.
        As imagens são fotos da MESMA peça (frente, verso, detalhes, marcas).
        {$this->contextLine($context)}
        Identifique a peça com o máximo de precisão (estilo, fabricante, marcas, período). Em caso de incerteza use "c." ou faixas e diga isso na descrição.
        Responda apenas com JSON em português:
        {"titulo":"","categoria":"uma de: {$cats}","epoca":"","origem":"","material":"","estado":"uma de: {$conds}","descricao":"3 a 5 frases","tags":["5 a 8 tags em minúsculas, sem acento, com hífen"],"palavras_chave":["10 a 15 termos de busca"],"observacao_autenticidade":""}
        TXT;

        return $this->json($prompt, $images);
    }

    /**
     * Busca semântica. Para o custo não crescer com o acervo, primeiro filtra no banco
     * (qualquer palavra da busca) e completa com as peças mais recentes; só esse
     * conjunto limitado vai para a IA.
     *
     * @return Collection<int, array{id:int, motivo:string}>
     */
    public function search(string $query, int $limit = 30): Collection
    {
        $max = (int) config('acervo.ai.search_candidates', 80);
        $base = Item::query()->with(['box', 'tags']);
        $matched = (clone $base)->search($query, any: true)->latest()->limit($max)->get();
        $fill = $matched->count() < $max
            ? (clone $base)->whereNotIn('id', $matched->pluck('id'))->latest()->limit($max - $matched->count())->get()
            : collect();

        $catalog = $matched->concat($fill)->map(fn (Item $i) => [
            'id' => $i->id, 't' => $i->code, 'titulo' => $i->title, 'cat' => $i->category,
            'epoca' => $i->era, 'origem' => $i->origin, 'material' => $i->material,
            'caixa' => $i->box?->name, 'tags' => $i->tags->pluck('name'),
            'desc' => str($i->description)->limit(160)->toString(), 'ia' => array_slice($i->ai_keywords ?? [], 0, 8),
        ])->values();

        $prompt = "Você é o curador de um acervo de antiguidades. Encontre no catálogo as peças que correspondem à busca, entendendo sinônimos, épocas aproximadas, estilos, materiais e descrições vagas.\n"
            ."Busca: \"{$query}\"\n"
            ."Responda apenas com um array JSON, do mais ao menos relevante, até {$limit} objetos {\"id\": <id>, \"motivo\": \"até 12 palavras\"}. Se nada corresponder, responda [].\n"
            .'Catálogo: '.$catalog->toJson(JSON_UNESCAPED_UNICODE);

        return collect($this->json($prompt, [], config('acervo.ai.search_model')))->filter(fn ($r) => isset($r['id']))->values();
    }

    /** Analisa as fotos de uma peça já salva e preenche a ficha. $overwrite troca também campos preenchidos. */
    public function analyzeItem(Item $item, bool $overwrite = false, string $context = ''): Item
    {
        $disk = \Illuminate\Support\Facades\Storage::disk(config('acervo.disk'));
        $images = $item->photos()->limit(config('acervo.ai.max_images'))->get()
            ->map(fn ($p) => $disk->get($p->thumb_path ?: $p->path))->filter()->values()->all();
        if (! $images) {
            throw new RuntimeException('A peça não tem fotos para analisar.');
        }

        $r = $this->analyze($images, $context);
        $map = ['title' => 'titulo', 'era' => 'epoca', 'origin' => 'origem', 'material' => 'material', 'description' => 'descricao'];
        foreach ($map as $field => $key) {
            if (filled($r[$key] ?? null) && ($overwrite || blank($item->{$field}))) {
                $item->{$field} = (string) $r[$key];
            }
        }
        if (in_array($r['categoria'] ?? null, config('acervo.categories'), true) && ($overwrite || blank($item->category))) {
            $item->category = $r['categoria'];
        }
        if (in_array($r['estado'] ?? null, config('acervo.conditions'), true) && ($overwrite || blank($item->condition))) {
            $item->condition = $r['estado'];
        }
        $item->ai_keywords = array_slice(array_map('strval', $r['palavras_chave'] ?? []), 0, 20);
        $item->ai_authenticity = $r['observacao_autenticidade'] ?? null;
        $item->ai_analyzed_at = now();
        $item->ai_status = 'done';
        $item->ai_error = null;
        $item->save();

        $item->tags()->syncWithoutDetaching(
            collect($r['tags'] ?? [])->map(fn ($t) => \App\Models\Tag::normalize($t))->filter()->unique()
                ->map(fn ($n) => \App\Models\Tag::firstOrCreate(['name' => $n])->id)
        );

        return $item;
    }

    /**
     * @param  string  $reference  binário da foto de referência
     * @param  array<int, array{item:Item, image:string}>  $candidates
     * @return array<int, array{veredito:string, confianca:int, nota:string}> indexado por item id
     */
    public function compare(string $reference, array $candidates): array
    {
        $list = collect($candidates)->values()->map(fn ($c, $i) => ($i + 2).". id={$c['item']->id} · {$c['item']->code} · {$c['item']->title}")->implode("\n");

        $prompt = "Você é um perito em antiguidades. A PRIMEIRA imagem é a foto de referência. As seguintes são peças do acervo, nesta ordem:\n{$list}\n"
            .'Para cada peça diga se é o MESMO objeto físico (mesmo exemplar: marcas, desgaste, defeitos), apenas semelhante (mesmo modelo/estilo) ou diferente. '
            .'Responda apenas com array JSON: [{"id":<id>,"veredito":"same|similar|diff","confianca":0-100,"nota":"até 20 palavras"}]';

        $images = array_merge([$reference], array_column($candidates, 'image'));

        return collect($this->json($prompt, $images))->keyBy('id')->all();
    }

    private function contextLine(string $context): string
    {
        return $context !== '' ? "Informações já anotadas pelo usuário: {$context}" : '';
    }

    private function json(string $prompt, array $images = [], ?string $model = null): array
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Configure ANTHROPIC_API_KEY no .env para usar a IA.');
        }

        $content = [];
        foreach (array_slice($images, 0, config('acervo.ai.max_images')) as $bin) {
            $content[] = ['type' => 'image', 'source' => [
                'type' => 'base64',
                'media_type' => (new \finfo(FILEINFO_MIME_TYPE))->buffer($bin) ?: 'image/jpeg',
                'data' => base64_encode($bin),
            ]];
        }
        $content[] = ['type' => 'text', 'text' => $prompt];

        $res = Http::withHeaders([
            'x-api-key' => config('acervo.ai.api_key'),
            'anthropic-version' => '2023-06-01',
        ])->timeout(config('acervo.ai.timeout'))->post('https://api.anthropic.com/v1/messages', [
            'model' => $model ?? config('acervo.ai.model'),
            'max_tokens' => 2048,
            'messages' => [['role' => 'user', 'content' => $content]],
        ]);

        if ($res->failed()) {
            throw new RuntimeException('Falha na IA: '.($res->json('error.message') ?? $res->status()));
        }

        $text = collect($res->json('content'))->where('type', 'text')->pluck('text')->implode('');

        return $this->parseJson($text);
    }

    private function parseJson(string $text): array
    {
        $text = trim($text);
        if (preg_match('/```(?:json)?\s*(.+?)```/s', $text, $m)) {
            $text = $m[1];
        }
        $data = json_decode($text, true);
        if (! is_array($data)) {
            $start = strcspn($text, '[{');
            $end = max(strrpos($text, '}') ?: 0, strrpos($text, ']') ?: 0);
            $data = json_decode(substr($text, $start, $end - $start + 1), true);
        }
        if (! is_array($data)) {
            throw new RuntimeException('A IA respondeu em formato inesperado. Tente novamente.');
        }

        return $data;
    }
}
