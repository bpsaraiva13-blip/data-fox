<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Support\PageStore;
use App\Support\StoreError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** API do banco de documentos usada pela interface (window.claude.use('db')). */
class DbController extends Controller
{
    /** Várias consultas de uma vez (carga inicial e sincronização a cada poucos segundos). */
    public function sync(Request $request): JsonResponse
    {
        $data = $request->validate(['queries' => ['required', 'array', 'max:60'], 'queries.*.path' => ['required', 'string', 'max:300']]);
        $store = new PageStore($request->user());
        $out = [];
        foreach ($data['queries'] as $k => $q) {
            try {
                $out[$k] = $store->query((array) $request->input("queries.{$k}"));
            } catch (StoreError $e) {
                $out[$k] = ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]];
            }
        }

        return response()->json(['results' => $out, 'now' => now()->toIso8601String()]);
    }

    public function write(Request $request): JsonResponse
    {
        $request->validate(['op' => ['required', 'in:set,update,add,delete'], 'path' => ['required', 'string', 'max:300']]);
        try {
            $r = (new PageStore($request->user()))->write($request->string('op'), $request->string('path'), $request->input('data'));
        } catch (StoreError $e) {
            return response()->json(['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]], $e->errorCode === 'permission_denied' ? 403 : 422);
        }

        return response()->json($r);
    }
}
