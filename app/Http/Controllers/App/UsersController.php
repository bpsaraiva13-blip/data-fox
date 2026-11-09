<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Mail\WelcomeMail;
use App\Models\Acervo;
use App\Models\Membership;
use App\Models\User;
use App\Support\Memberships;
use App\Support\PageStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Tela "Usuários" da interface (só administradores do sistema): contas, acervos de cada pessoa
 * e link de primeiro acesso. A tela Equipe continua sendo o lugar de cada acervo.
 */
class UsersController extends Controller
{
    private function guard(Request $request): User
    {
        $me = $request->user();
        abort_unless($me?->isAdmin(), 403, 'Só administradores do sistema gerenciam usuários.');

        return $me;
    }

    /** Envio de e-mail configurado (com "log" ou "array" nada sai do servidor). */
    public static function mailReady(): bool
    {
        return ! in_array(config('mail.default'), ['log', 'array', null], true);
    }

    public function index(Request $request)
    {
        $this->guard($request);
        $acervos = Acervo::orderBy('name')->get(['id', 'name', 'owner_id']);
        $users = User::with('memberships')->orderBy('name')->get();

        return response()->json([
            'users' => $users->map(fn (User $u) => $this->row($u, $acervos))->values(),
            'acervos' => $acervos->map(fn (Acervo $a) => ['id' => (string) $a->id, 'name' => $a->name, 'ownerId' => $a->owner_id ? PageStore::uid($a->owner_id) : null])->values(),
            'mail' => self::mailReady(),
        ]);
    }

    /** Caixas de um acervo, para escolher as que a pessoa vê. */
    public function boxes(Request $request, Acervo $acervo)
    {
        $this->guard($request);
        $rows = DB::table('docs')->where('acervo_id', $acervo->id)->where('col', 'boxes')->whereNotNull('data')->get(['doc_id', 'data']);
        $boxes = $rows->map(function ($r) {
            $d = json_decode($r->data, true) ?: [];

            return ['id' => $r->doc_id, 'name' => (string) ($d['name'] ?? $r->doc_id), 'code' => $d['code'] ?? null];
        })->sortBy(fn ($b) => Str::lower($b['name']), SORT_NATURAL)->values();

        return response()->json(['boxes' => $boxes]);
    }

    public function store(Request $request)
    {
        $me = $this->guard($request);
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'system_admin' => ['boolean'],
            'access' => ['required', Rule::in(['link', 'password'])],
            'password' => ['nullable', 'required_if:access,password', 'string', 'min:8', 'max:200'],
            'send_email' => ['boolean'],
            ...$this->membershipRules(),
        ], $this->messages());

        $user = DB::transaction(function () use ($data, $me) {
            $u = User::create([
                'name' => trim($data['name']), 'email' => $data['email'],
                'password' => $data['access'] === 'password' ? $data['password'] : Str::random(40),
                'role' => ! empty($data['system_admin']) ? 'admin' : 'editor', 'active' => true,
            ]);
            // conta criada por um administrador: o e-mail vale como confirmado (permite entrar com Google)
            $u->forceFill(['email_verified_at' => now()])->saveQuietly();
            foreach ($data['acervos'] ?? [] as $m) {
                $this->link($u, Acervo::findOrFail((int) $m['aid']), $m, $me);
            }

            return $u;
        });

        $out = ['user' => $this->row($user->load('memberships'))];
        if ($data['access'] === 'link') {
            $out += $this->accessLinkFor($user, $request->boolean('send_email'), $me);
        }

        return response()->json($out, 201);
    }

    public function update(Request $request, User $user)
    {
        $me = $this->guard($request);
        if ($request->has('email')) {
            $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        }
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'email' => ['sometimes', 'required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'system_admin' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'password' => ['sometimes', 'nullable', 'string', 'min:8', 'max:200'],
        ], $this->messages());

        if ($user->is($me) && ((array_key_exists('active', $data) && ! $data['active']) || (array_key_exists('system_admin', $data) && ! $data['system_admin']))) {
            throw ValidationException::withMessages(['active' => 'Você não pode tirar o seu próprio acesso de administrador nem se desativar.']);
        }
        $fill = [];
        if (isset($data['name'])) {
            $fill['name'] = trim($data['name']);
        }
        if (isset($data['email']) && $data['email'] !== $user->email) {
            $fill['email'] = $data['email'];
        }
        if (array_key_exists('system_admin', $data)) {
            $fill['role'] = $data['system_admin'] ? 'admin' : 'editor';
        }
        if (array_key_exists('active', $data)) {
            $fill['active'] = (bool) $data['active'];
        }
        if (! empty($data['password'])) {
            $fill['password'] = $data['password'];
        }
        $user->fill($fill);
        if (isset($fill['email'])) {
            $user->email_verified_at = now();
        }
        $user->save();
        if (array_key_exists('active', $fill) && ! $fill['active']) {
            $this->endSessions($user);
        }

        return response()->json(['user' => $this->row($user->load('memberships'))]);
    }

    public function destroy(Request $request, User $user)
    {
        $me = $this->guard($request);
        abort_if($user->is($me), 422, 'Você não pode excluir a sua própria conta.');
        $owned = Acervo::where('owner_id', $user->id)->pluck('name');
        if ($owned->isNotEmpty()) {
            return response()->json(['error' => ['code' => 'owner', 'message' => 'Esta pessoa é dona de '.$owned->implode(', ').'. Desative a conta em vez de excluir.']], 422);
        }
        $name = $user->name;
        foreach ($user->memberships as $m) {
            PageStore::logActivity($m->acervo_id, $me, "Excluiu a conta de {$name}");
        }
        $user->delete();

        return response()->json(['ok' => true]);
    }

    /** Vincula a um acervo ou muda perfil, caixas e situação do vínculo. */
    public function setMembership(Request $request, User $user, Acervo $acervo)
    {
        $me = $this->guard($request);
        $m = $request->validate([
            'role' => ['required', Rule::in(array_keys(Acervo::ROLES))],
            'status' => ['nullable', Rule::in(['active', 'blocked'])],
            'boxes' => ['nullable', 'array', 'max:2000'],
            'boxes.*' => ['string', 'max:80'],
        ]);
        $this->link($user, $acervo, $m, $me);

        return response()->json(['user' => $this->row($user->load('memberships'))]);
    }

    public function removeMembership(Request $request, User $user, Acervo $acervo)
    {
        $me = $this->guard($request);
        abort_if($acervo->owner_id === $user->id, 422, 'O dono do acervo não pode ser removido dele.');
        Memberships::remove($user, $acervo, $me);

        return response()->json(['user' => $this->row($user->load('memberships'))]);
    }

    /** Novo link para a pessoa criar (ou trocar) a senha. */
    public function accessLink(Request $request, User $user)
    {
        $me = $this->guard($request);
        abort_unless($user->active, 422, 'A conta está desativada. Reative antes de gerar o link.');

        return response()->json($this->accessLinkFor($user, $request->boolean('send_email'), $me));
    }

    public function endAllSessions(Request $request, User $user)
    {
        $this->guard($request);
        $this->endSessions($user);

        return response()->json(['ok' => true]);
    }

    public function resetTwoFactor(Request $request, User $user)
    {
        $this->guard($request);
        $user->forceFill(['app_authentication_secret' => null, 'app_authentication_recovery_codes' => null])->save();

        return response()->json(['user' => $this->row($user->load('memberships'))]);
    }

    // ------------------------------------------------------------------

    private function membershipRules(): array
    {
        return [
            'acervos' => ['nullable', 'array', 'max:50'],
            'acervos.*.aid' => ['required', 'integer', 'distinct', 'exists:acervos,id'],
            'acervos.*.role' => ['required', Rule::in(array_keys(Acervo::ROLES))],
            'acervos.*.boxes' => ['nullable', 'array', 'max:2000'],
            'acervos.*.boxes.*' => ['string', 'max:80'],
        ];
    }

    private function messages(): array
    {
        return [
            'name.required' => 'Digite o nome.', 'email.required' => 'Digite o e-mail.', 'email.email' => 'Esse e-mail não parece válido.',
            'email.unique' => 'Já existe uma conta com este e-mail.', 'password.required_if' => 'Digite a senha (ou escolha enviar o link).',
            'password.min' => 'A senha precisa de pelo menos 8 caracteres.', 'acervos.*.aid.distinct' => 'O mesmo acervo aparece duas vezes.',
            'acervos.*.aid.exists' => 'Um dos acervos não existe mais.',
        ];
    }

    private function link(User $user, Acervo $acervo, array $m, User $by): void
    {
        Memberships::set($user, $acervo, $m, $by);
    }

    /** @return array{link:string, expiresAt:string, mailed:bool} */
    private function accessLinkFor(User $user, bool $sendEmail, User $by): array
    {
        $token = Password::broker('welcome')->createToken($user);
        $link = route('welcome', ['token' => $token, 'email' => $user->email]);
        $mailed = false;
        if ($sendEmail && self::mailReady()) {
            try {
                Mail::to($user->email)->send(new WelcomeMail($user, $link, $by));
                $mailed = true;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return ['link' => $link, 'expiresAt' => now()->addMinutes((int) config('auth.passwords.welcome.expire', 10080))->toIso8601String(), 'mailed' => $mailed];
    }

    private function endSessions(User $user): void
    {
        DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
    }

    private function row(User $u, $acervos = null): array
    {
        $owners = ($acervos ?? Acervo::whereIn('id', $u->memberships->pluck('acervo_id'))->get(['id', 'owner_id']))->pluck('owner_id', 'id');

        return [
            'id' => PageStore::uid($u),
            'key' => $u->id,
            'name' => $u->name,
            'displayName' => ($u->profile['name'] ?? '') ?: $u->name,
            'email' => $u->email,
            'phone' => ($u->profile['phone'] ?? '') ?: $u->phone,
            'systemAdmin' => $u->isAdmin(),
            'active' => (bool) $u->active,
            'pending' => ! $u->last_login_at,
            'lastLoginAt' => $u->last_login_at?->toIso8601String(),
            'lastLoginVia' => $u->last_login_via,
            'createdAt' => $u->created_at?->toIso8601String(),
            'google' => (bool) $u->google_id,
            'apple' => (bool) $u->apple_id,
            'twoFactor' => (bool) $u->app_authentication_secret,
            'acervos' => $u->memberships->map(fn (Membership $m) => [
                'aid' => (string) $m->acervo_id, 'role' => $m->role, 'status' => $m->status ?: 'active',
                'boxes' => $m->allowedBoxes(), 'owner' => ($owners[$m->acervo_id] ?? null) === $u->id,
            ])->values(),
        ];
    }
}
