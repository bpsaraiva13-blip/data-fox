<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/**
 * window.claude.use('sample'): a IA da interface (identificar peça pelas fotos, busca
 * inteligente, comparar imagens). A chave fica só no servidor (ANTHROPIC_API_KEY).
 */
class AiController extends Controller
{
    public const MAX_IMAGES = 6;

    public static function enabled(): bool
    {
        return filled(config('acervo.ai.api_key'));
    }

    public function json(Request $request)
    {
        if (! self::enabled()) {
            return response()->json(['error' => ['code' => 'unavailable', 'message' => 'A IA não está configurada neste servidor.']], 503);
        }
        $request->validate(['prompt' => ['required', 'string', 'max:200000'], 'images' => ['array', 'max:'.self::MAX_IMAGES], 'images.*' => ['file', 'max:8192']]);
        $key = 'ai:'.$request->user()->id;
        if (RateLimiter::tooManyAttempts($key, 40)) {
            return response()->json(['error' => ['code' => 'rate_limited', 'message' => 'Muitas solicitações à IA. Aguarde alguns segundos.']], 429);
        }
        RateLimiter::hit($key, 60);

        $content = [];
        foreach ((array) $request->file('images', []) as $img) {
            $mime = in_array($img->getMimeType(), ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true) ? $img->getMimeType() : 'image/jpeg';
            $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode(file_get_contents($img->getRealPath()))]];
        }
        $content[] = ['type' => 'text', 'text' => $request->string('prompt')->toString()];
        $model = $request->input('modelTier') === 'complex' ? config('acervo.ai.model') : config('acervo.ai.search_model');

        $res = Http::withHeaders(['x-api-key' => config('acervo.ai.api_key'), 'anthropic-version' => '2023-06-01'])
            ->timeout((int) config('acervo.ai.timeout', 90))
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => $model,
                'max_tokens' => 4096,
                'system' => 'Responda somente com JSON válido, sem texto antes ou depois.',
                'messages' => [['role' => 'user', 'content' => $content]],
            ]);
        if ($res->status() === 429 || $res->status() === 529) {
            return response()->json(['error' => ['code' => 'rate_limited', 'message' => 'A IA está sobrecarregada. Tente de novo em instantes.']], 429);
        }
        if (! $res->successful()) {
            report(new \RuntimeException('IA: '.$res->status().' '.substr($res->body(), 0, 300)));

            return response()->json(['error' => ['code' => 'ai_error', 'message' => 'A IA não respondeu agora. Tente de novo.']], 502);
        }
        $text = collect($res->json('content', []))->where('type', 'text')->pluck('text')->implode('');
        $data = self::parse($text);
        if ($data === null) {
            return response()->json(['error' => ['code' => 'invalid_json', 'message' => 'A IA respondeu em formato inesperado. Tente de novo.']], 502);
        }

        return response()->json(['result' => $data]);
    }

    public static function parse(string $text): mixed
    {
        if (preg_match('/```(?:json)?\s*(.+?)```/s', $text, $m)) {
            $text = $m[1];
        }
        $d = json_decode(trim($text), true);
        if ($d !== null) {
            return $d;
        }
        foreach ([['{', '}'], ['[', ']']] as [$a, $b]) {
            $s = strpos($text, $a);
            $e = strrpos($text, $b);
            if ($s !== false && $e > $s && ($d = json_decode(substr($text, $s, $e - $s + 1), true)) !== null) {
                return $d;
            }
        }

        return null;
    }
}
