<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Acervo;
use App\Models\Membership;
use App\Models\User;
use App\Support\PageStore;
use Illuminate\Http\Request;

/** window.claude.use('user'): nomes de quem aparece no histórico e busca de pessoas para a equipe. */
class PeopleController extends Controller
{
    public function profiles(Request $request)
    {
        $ids = array_slice(array_values(array_filter((array) $request->input('ids'), 'is_string')), 0, 300);
        $out = [];
        $userIds = array_filter(array_map(fn ($i) => PageStore::userIdFrom($i), $ids));
        foreach (User::whereIn('id', $userIds)->get(['id', 'name', 'profile']) as $u) {
            $out[PageStore::uid($u)] = ['id' => PageStore::uid($u), 'name' => ($u->profile['name'] ?? '') ?: $u->name, 'avatarUrl' => ''];
        }
        // nomes da versão web (importados) ficam guardados no acervo
        $legacy = array_diff($ids, array_keys($out));
        if ($legacy) {
            $mine = Membership::where('user_id', $request->user()->id)->pluck('acervo_id');
            foreach (Acervo::whereIn('id', $mine)->get(['id', 'page']) as $a) {
                foreach ((array) ($a->page['people'] ?? []) as $pid => $name) {
                    if (in_array($pid, $legacy, true) && $name) {
                        $out[$pid] ??= ['id' => $pid, 'name' => $name, 'avatarUrl' => ''];
                    }
                }
            }
        }

        return response()->json($out);
    }

    /** Busca pelo e-mail exato (qualquer conta ativa) ou pelo nome entre quem já divide um acervo. */
    public function search(Request $request)
    {
        $q = trim((string) $request->query('q'));
        // só quem administra algum acervo procura pessoas para a equipe
        if (mb_strlen($q) < 2 || ! (new PageStore($request->user()))->acervosWith(['admin'])) {
            return response()->json(['results' => []]);
        }
        $shared = Membership::whereIn('acervo_id', Membership::where('user_id', $request->user()->id)->pluck('acervo_id'))->pluck('user_id');
        $users = User::where('active', true)->where(fn ($w) => $w->whereRaw('lower(email) = ?', [mb_strtolower($q)])
            ->orWhere(fn ($x) => $x->whereIn('id', $shared)->where('name', 'ilike', '%'.addcslashes($q, '%_').'%')))
            ->limit(12)->get(['id', 'name', 'email', 'profile']);

        return response()->json(['results' => $users->map(fn ($u) => [
            'id' => PageStore::uid($u), 'name' => (($u->profile['name'] ?? '') ?: $u->name).' · '.$u->email, 'avatarUrl' => '', 'guest' => false,
        ])->values()]);
    }
}
