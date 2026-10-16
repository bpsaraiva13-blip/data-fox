<?php

namespace App\Support;

use App\Models\Acervo;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Banco de documentos da interface do Data Fox (a mesma da versão web).
 *
 * Caminhos aceitos (iguais aos da página):
 *   acervos · acervos/<aid>                       → acervos e equipe (tabelas acervos + acervo_user)
 *   profiles · profiles/<uid>                     → perfil de cada pessoa (users.profile)
 *   invites · invites/<código>                    → convites (tabela invitations)
 *   acervos/<aid>/<coleção>[/<id>]                → documentos do acervo (tabela docs)
 *
 * Toda leitura e escrita passa por aqui, com as regras de acesso do acervo:
 * admin/editor gravam, viewer só lê, "só vitrine" vê só as peças expostas,
 * caixas permitidas limitam peças e caixas, e caixas vinculadas liberam o acesso
 * às peças delas para quem participa do acervo vinculado.
 */
class PageStore
{
    public const READ_ROLES = ['admin', 'editor', 'viewer'];

    public const WRITE_ROLES = ['admin', 'editor'];

    /** Coleções em que qualquer participante pode registrar (histórico e acessos). */
    public const LOG_COLS = ['activity', 'access'];

    private const MAX_DOC_BYTES = 900_000;

    /** @var array<int, Membership|null> */
    private array $members = [];

    public function __construct(public User $user) {}

    // ------------------------------------------------------------------ ids

    public static function uid(int|User $u): string
    {
        return 'u'.($u instanceof User ? $u->id : $u);
    }

    public static function userIdFrom(?string $uid): ?int
    {
        return is_string($uid) && preg_match('/^u(\d{1,18})$/', $uid, $m) ? (int) $m[1] : null;
    }

    public static function newId(): string
    {
        return Str::lower(Str::random(20));
    }

    public static function nextRev(): int
    {
        if (DB::getDriverName() === 'pgsql') {
            return (int) DB::selectOne("select nextval('docs_rev_seq') as v")->v;
        }

        return (int) (microtime(true) * 1000);
    }

    /**
     * @return array{kind:string, aid?:string, col?:string, id?:?string}
     */
    public static function parse(string $path): array
    {
        $p = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));
        $ok = fn (string $s) => (bool) preg_match('/^[A-Za-z0-9_.\-]{1,80}$/', $s);
        foreach ($p as $s) {
            if (! $ok($s)) {
                throw new StoreError('invalid_path', 'Caminho inválido.');
            }
        }
        $n = count($p);
        if ($n >= 1 && $n <= 2 && in_array($p[0], ['acervos', 'profiles', 'invites'], true)) {
            return ['kind' => $p[0], 'id' => $p[1] ?? null];
        }
        if ($p[0] === 'acervos' && ($n === 3 || $n === 4) && preg_match('/^[A-Za-z0-9_]{1,40}$/', $p[2])) {
            return ['kind' => 'docs', 'aid' => $p[1], 'col' => $p[2], 'id' => $p[3] ?? null];
        }
        throw new StoreError('invalid_path', 'Este caminho não existe no Data Fox.');
    }

    // ------------------------------------------------------------------ participação

    public function membership(string|int|null $aid): ?Membership
    {
        if (! is_numeric($aid)) {
            return null;
        }
        $aid = (int) $aid;
        if (! array_key_exists($aid, $this->members)) {
            $this->members[$aid] = Membership::where('acervo_id', $aid)->where('user_id', $this->user->id)->first();
        }

        return $this->members[$aid];
    }

    public function role(string|int|null $aid): ?string
    {
        $m = $this->membership($aid);

        return $m && $m->status !== 'blocked' ? $m->role : null;
    }

    public function canRead(string|int|null $aid): bool
    {
        return in_array($this->role($aid), self::READ_ROLES, true);
    }

    public function canWrite(string|int|null $aid): bool
    {
        return in_array($this->role($aid), self::WRITE_ROLES, true);
    }

    public function isAdmin(string|int|null $aid): bool
    {
        return $this->role($aid) === 'admin';
    }

    /** @return array<int, string> ids (string) dos acervos em que a pessoa tem um dos perfis */
    public function acervosWith(array $roles): array
    {
        return Membership::where('user_id', $this->user->id)->where('status', '!=', 'blocked')
            ->whereIn('role', $roles)->pluck('acervo_id')->map(fn ($v) => (string) $v)->all();
    }

    // ------------------------------------------------------------------ leitura

    /**
     * Executa uma consulta. $q: path, where[[campo,op,valor]], orderBy[campo,dir], limit, since, ver.
     *
     * @return array{full:bool, docs?:array, removed?:array, ver:string, same?:bool}
     */
    public function query(array $q): array
    {
        $t = self::parse((string) ($q['path'] ?? ''));
        $where = array_values(array_filter((array) ($q['where'] ?? []), fn ($w) => is_array($w) && count($w) === 3));
        $isDoc = ($q['kind'] ?? 'col') === 'doc';

        if ($t['kind'] !== 'docs') {
            $docs = match ($t['kind']) {
                'acervos' => $this->acervoDocs(),
                'profiles' => $this->profileDocs(),
                'invites' => $this->inviteDocs(),
            };
            if ($isDoc) {
                $docs = array_values(array_filter($docs, fn ($d) => $d['id'] === $t['id']));
            }
            $docs = $this->sortLimit(array_values(array_filter($docs, fn ($d) => $this->matches($d['data'], $where))), $q);
            $ver = 'h'.md5(json_encode($docs));

            return ($q['ver'] ?? null) === $ver ? ['full' => true, 'same' => true, 'ver' => $ver] : ['full' => true, 'docs' => $docs, 'ver' => $ver];
        }

        $aid = $t['aid'];
        $col = $t['col'];
        $scope = $this->readScope($aid, $col, $where);
        if ($scope === null) {
            throw new StoreError('permission_denied', 'Você não tem acesso a este acervo.');
        }
        if ($scope === 'empty') {
            return ['full' => true, 'docs' => [], 'ver' => 'empty'];
        }

        $base = DB::table('docs')->where('acervo_id', (int) $aid)->where('col', $col);
        if ($isDoc) {
            $base->where('doc_id', $t['id']);
        }
        $maxRev = (int) (clone $base)->max('rev');
        $ver = 'r'.$maxRev;
        $limited = ! empty($q['limit']) || $isDoc;
        if (($q['ver'] ?? null) === $ver) {
            return ['full' => ! isset($q['since']), 'same' => true, 'ver' => $ver];
        }

        $keep = fn (array $data) => $this->matches($data, $where) && $this->visible($scope, $col, $data);

        // incremental: o que mudou desde a última rodada (coleções sem limite)
        if (! $limited && isset($q['since']) && is_numeric($q['since'])) {
            $rows = (clone $base)->where('rev', '>', (int) $q['since'])->orderBy('rev')->limit(5000)->get(['doc_id', 'data']);
            $docs = [];
            $removed = [];
            foreach ($rows as $r) {
                $data = $r->data === null ? null : json_decode($r->data, true);
                if (is_array($data) && $keep($data)) {
                    $docs[] = ['id' => $r->doc_id, 'data' => $data];
                } else {
                    $removed[] = $r->doc_id;
                }
            }

            return ['full' => false, 'docs' => $docs, 'removed' => $removed, 'ver' => $ver];
        }

        $sql = (clone $base)->whereNotNull('data');
        foreach ($where as [$f, $op, $v]) {
            $this->applyWhere($sql, (string) $f, (string) $op, $v);
        }
        if (! empty($q['orderBy'][0]) && preg_match('/^[A-Za-z0-9_]{1,60}$/', (string) $q['orderBy'][0])) {
            $dir = strtolower((string) ($q['orderBy'][1] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
            $sql->orderByRaw("data->>'".$q['orderBy'][0]."' {$dir} nulls last");
        }
        if (! empty($q['limit'])) {
            $sql->limit(max(1, min(5000, (int) $q['limit'])));
        }
        $docs = [];
        foreach ($sql->get(['doc_id', 'data']) as $r) {
            $data = json_decode($r->data, true);
            if (is_array($data) && $keep($data)) {
                $docs[] = ['id' => $r->doc_id, 'data' => $data];
            }
        }

        return ['full' => true, 'docs' => $docs, 'ver' => $ver];
    }

    /**
     * Quem pode ler esta coleção e com qual filtro.
     * Retorna null (sem acesso), 'empty' (perfil sem acesso a essa coleção: devolve vazio)
     * ou um array com o escopo.
     */
    private function readScope(string $aid, string $col, array $where): array|string|null
    {
        $role = $this->role($aid);
        if (in_array($role, self::READ_ROLES, true)) {
            return ['mode' => 'member', 'boxes' => $this->membership($aid)->allowedBoxes()];
        }
        if ($role === 'showcase') {
            return in_array($col, ['items', 'collections'], true) ? ['mode' => 'showcase', 'boxes' => null] : 'empty';
        }
        // caixas vinculadas: quem participa do acervo X lê as caixas vinculadas a X e as peças delas
        $readers = $this->acervosWith(self::READ_ROLES);
        if ($col === 'boxes') {
            foreach ($where as [$f, $op, $v]) {
                if ($f === 'linkedTo' && $op === 'array-contains' && in_array((string) $v, $readers, true)) {
                    return ['mode' => 'linked', 'boxes' => null];
                }
            }
        }
        if ($col === 'items') {
            foreach ($where as [$f, $op, $v]) {
                if ($f === 'boxId' && $op === '==' && is_string($v) && $this->boxLinkedTo($aid, $v, $readers)) {
                    return ['mode' => 'linked', 'boxes' => null];
                }
            }
        }

        return null;
    }

    private function visible(array $scope, string $col, array $data): bool
    {
        if ($scope['mode'] === 'showcase') {
            return $col !== 'items' || ! empty($data['showcase']);
        }
        $boxes = $scope['boxes'];
        if (! is_array($boxes)) {
            return true;
        }
        if ($col === 'items') {
            return in_array(($data['boxId'] ?? '') ?: 'none', $boxes, true);
        }

        return true;
    }

    /** A caixa $boxId do acervo $aid está vinculada a algum dos acervos $targets? */
    public function boxLinkedTo(string $aid, string $boxId, array $targets): bool
    {
        if (! $targets || $boxId === '') {
            return false;
        }
        $data = DB::table('docs')->where('acervo_id', (int) $aid)->where('col', 'boxes')->where('doc_id', $boxId)->value('data');
        $linked = $data ? (array) (json_decode($data, true)['linkedTo'] ?? []) : [];

        return (bool) array_intersect(array_map('strval', $linked), $targets);
    }

    private function applyWhere($sql, string $f, string $op, mixed $v): void
    {
        if (! preg_match('/^[A-Za-z0-9_]{1,60}$/', $f)) {
            throw new StoreError('invalid_query', 'Consulta inválida.');
        }
        match ($op) {
            '==' => $sql->whereRaw('data @> ?::jsonb', [json_encode([$f => $v])]),
            'array-contains' => $sql->whereRaw("data->'{$f}' @> ?::jsonb", [json_encode([$v])]),
            'in' => $sql->whereIn(DB::raw("data->>'{$f}'"), array_map('strval', (array) $v)),
            '!=' => $sql->whereRaw("not (data @> ?::jsonb)", [json_encode([$f => $v])]),
            default => throw new StoreError('invalid_query', "Operador {$op} não suportado."),
        };
    }

    private function matches(array $data, array $where): bool
    {
        foreach ($where as [$f, $op, $v]) {
            $x = $data[$f] ?? null;
            $ok = match ($op) {
                '==' => $x === $v || (is_scalar($x) && is_scalar($v) && (string) $x === (string) $v && gettype($x) === gettype($v)),
                'array-contains' => is_array($x) && in_array($v, $x, true),
                'in' => in_array($x, (array) $v, true),
                '!=' => $x !== $v,
                default => false,
            };
            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    private function sortLimit(array $docs, array $q): array
    {
        if (! empty($q['orderBy'][0])) {
            $f = (string) $q['orderBy'][0];
            $desc = strtolower((string) ($q['orderBy'][1] ?? 'asc')) === 'desc';
            usort($docs, fn ($a, $b) => strcmp((string) ($a['data'][$f] ?? ''), (string) ($b['data'][$f] ?? '')) * ($desc ? -1 : 1));
        }

        return ! empty($q['limit']) ? array_slice($docs, 0, (int) $q['limit']) : $docs;
    }

    // ------------------------------------------------------------------ coleções virtuais

    /** Acervos de que a pessoa participa (inclusive bloqueada, para a interface avisar). */
    public function acervoDocs(): array
    {
        $mine = Membership::where('user_id', $this->user->id)->get()->keyBy('acervo_id');
        if ($mine->isEmpty()) {
            return [];
        }
        $acervos = Acervo::whereIn('id', $mine->keys())->get();
        $all = Membership::whereIn('acervo_id', $acervos->pluck('id'))->get()->groupBy('acervo_id');
        $out = [];
        foreach ($acervos as $a) {
            $admin = ($mine[$a->id]->role ?? null) === 'admin' && $mine[$a->id]->status !== 'blocked';
            $members = [];
            foreach ($all[$a->id] ?? [] as $m) {
                // quem não administra vê só a própria participação e o dono
                if (! $admin && $m->user_id !== $this->user->id && $m->user_id !== $a->owner_id) {
                    continue;
                }
                $members[self::uid($m->user_id)] = array_filter([
                    'role' => $m->role, 'status' => $m->status ?: 'active', 'boxes' => $m->allowedBoxes(),
                    'addedAt' => $m->created_at?->toIso8601String(), 'addedBy' => $m->invited_by ? self::uid($m->invited_by) : null,
                ], fn ($v) => $v !== null);
            }
            $page = Arr::except((array) ($a->page ?? []), ['people', 'members', 'ownerId', 'name']);
            $out[] = ['id' => (string) $a->id, 'data' => $page + [
                'name' => $a->name,
                'ownerId' => $a->owner_id ? self::uid($a->owner_id) : null,
                'members' => (object) $members,
                'createdAt' => $a->created_at?->toIso8601String(),
            ]];
        }

        return $out;
    }

    /** Perfis de quem divide algum acervo com a pessoa (e o dela). PIN alheio aparece só como "tem PIN". */
    public function profileDocs(): array
    {
        $ids = Membership::whereIn('acervo_id', Membership::where('user_id', $this->user->id)->pluck('acervo_id'))
            ->pluck('user_id')->push($this->user->id)->unique();
        $out = [];
        foreach (User::whereIn('id', $ids)->get(['id', 'name', 'profile', 'active']) as $u) {
            $p = (array) ($u->profile ?? []);
            if ($u->id !== $this->user->id && isset($p['pin'])) {
                $p['pin'] = ['len' => (int) ($p['pin']['len'] ?? 0)];
            }
            $p['name'] = ($p['name'] ?? '') ?: $u->name;
            $out[] = ['id' => self::uid($u->id), 'data' => $p];
        }

        return $out;
    }

    /** Convites dos acervos que a pessoa administra. */
    public function inviteDocs(): array
    {
        $admin = $this->acervosWith(['admin']);
        if (! $admin) {
            return [];
        }

        return Invitation::whereIn('acervo_id', $admin)->latest()->limit(500)->get()
            ->map(fn (Invitation $i) => ['id' => $i->token, 'data' => self::inviteData($i)])->all();
    }

    public static function inviteData(Invitation $i): array
    {
        return array_filter([
            'aid' => (string) $i->acervo_id, 'role' => $i->role, 'boxes' => $i->boxes, 'label' => $i->name ?: $i->email,
            'expiresAt' => $i->expires_at?->toIso8601String(), 'createdAt' => $i->created_at?->toIso8601String(),
            'createdBy' => $i->invited_by ? self::uid($i->invited_by) : null,
            'usedBy' => $i->accepted_by ? self::uid($i->accepted_by) : null, 'usedAt' => $i->accepted_at?->toIso8601String(),
            'revoked' => $i->revoked_at ? true : null,
        ], fn ($v) => $v !== null);
    }

    // ------------------------------------------------------------------ escrita

    /**
     * @return array{id:string}
     */
    public function write(string $op, string $path, mixed $data): array
    {
        if (! in_array($op, ['set', 'update', 'add', 'delete'], true)) {
            throw new StoreError('invalid_op', 'Operação inválida.');
        }
        if ($op !== 'delete') {
            if (! is_array($data) || array_is_list($data) && $data !== []) {
                throw new StoreError('invalid_data', 'Dados inválidos.');
            }
            if (strlen(json_encode($data)) > self::MAX_DOC_BYTES) {
                throw new StoreError('too_large', 'Este registro ficou grande demais.');
            }
        }
        $t = self::parse($path);
        if (($op === 'add') !== ($t['id'] === null)) {
            throw new StoreError('invalid_path', $op === 'add' ? 'Use add numa coleção.' : 'Informe o documento.');
        }

        return DB::transaction(fn () => match ($t['kind']) {
            'acervos' => $this->writeAcervo($op, $t['id'], $data),
            'profiles' => $this->writeProfile($op, $t['id'], $data),
            'invites' => $this->writeInvite($op, $t['id'], $data),
            'docs' => $this->writeDoc($op, $t['aid'], $t['col'], $t['id'], $data),
        });
    }

    private function writeDoc(string $op, string $aid, string $col, ?string $id, mixed $data): array
    {
        if (! is_numeric($aid) || ! Acervo::whereKey((int) $aid)->exists()) {
            throw new StoreError('permission_denied', 'Este acervo não existe.');
        }
        $id ??= self::newId();
        $row = DB::table('docs')->where('acervo_id', (int) $aid)->where('col', $col)->where('doc_id', $id)->lockForUpdate()->first();
        $old = $row && $row->data !== null ? json_decode($row->data, true) : null;
        if ($op === 'update' && $old === null) {
            throw new StoreError('not_found', 'Este registro não existe mais.');
        }
        $new = match ($op) {
            'delete' => null,
            'update' => array_merge($old, $data),
            default => $data,
        };

        $this->assertDocWrite($aid, $col, $op, $old, $new, $id);

        $rev = self::nextRev();
        $vals = ['data' => $new === null ? null : json_encode($new), 'rev' => $rev, 'updated_by' => $this->user->id, 'updated_at' => now()];
        if ($row) {
            DB::table('docs')->where('id', $row->id)->update($vals);
        } elseif ($new !== null) {
            DB::table('docs')->insert($vals + ['acervo_id' => (int) $aid, 'col' => $col, 'doc_id' => $id, 'created_at' => now()]);
        }

        return ['id' => $id, 'rev' => $rev];
    }

    private function assertDocWrite(string $aid, string $col, string $op, ?array $old, ?array $new, string $id): void
    {
        $deny = fn (string $m = 'Você não tem permissão para alterar este acervo.') => throw new StoreError('permission_denied', $m);
        $role = $this->role($aid);

        if (in_array($role, self::WRITE_ROLES, true)) {
            $boxes = $this->membership($aid)->allowedBoxes();
            if (is_array($boxes)) {
                // caixas permitidas: só mexe em peças dessas caixas e não cria caixas novas
                if ($col === 'items') {
                    foreach ([$old, $new] as $d) {
                        if ($d !== null && ! in_array(($d['boxId'] ?? '') ?: 'none', $boxes, true)) {
                            $deny('Você só pode alterar peças das caixas liberadas para você.');
                        }
                    }
                }
                if ($col === 'boxes' && ! in_array($id, $boxes, true)) {
                    $deny('Você só pode alterar as caixas liberadas para você.');
                }
            }
            if ($col === 'boxes' && $new !== null) {
                // vincular a caixa a outro acervo exige ser editor também no outro acervo
                $added = array_diff(array_map('strval', (array) ($new['linkedTo'] ?? [])), array_map('strval', (array) ($old['linkedTo'] ?? [])));
                $editorIn = $this->acervosWith(self::WRITE_ROLES);
                if (array_diff($added, $editorIn)) {
                    $deny('Para vincular a outro acervo, você precisa ser editor nele também.');
                }
            }

            return;
        }

        // histórico e registro de acessos: qualquer participante ativo acrescenta
        if (in_array($col, self::LOG_COLS, true) && $role !== null && $old === null && $new !== null) {
            return;
        }

        // caixa vinculada: quem edita no acervo vinculado altera as peças dela e a própria caixa
        $editorIn = $this->acervosWith(self::WRITE_ROLES);
        if ($col === 'items' && $editorIn) {
            $boxIds = array_values(array_unique(array_filter([$old['boxId'] ?? '', $new['boxId'] ?? ''])));
            if ($boxIds && collect($boxIds)->every(fn ($b) => $this->boxLinkedTo($aid, (string) $b, $editorIn))) {
                return;
            }
        }
        if ($col === 'boxes' && $editorIn && $old !== null && $new !== null) {
            $oldLinks = array_map('strval', (array) ($old['linkedTo'] ?? []));
            $newLinks = array_map('strval', (array) ($new['linkedTo'] ?? []));
            if (array_intersect($oldLinks, $editorIn) && ! array_diff($newLinks, $oldLinks)) {
                return;   // pode editar e desvincular, não vincular a outros
            }
        }

        $deny($role === 'viewer' ? 'Você só tem acesso de leitura a este acervo.' : 'Você não tem permissão para alterar este acervo.');
    }

    private function writeAcervo(string $op, ?string $id, mixed $data): array
    {
        if ($op === 'add') {
            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '') {
                throw new StoreError('invalid_data', 'Dê um nome ao acervo.');
            }
            $a = new Acervo(['name' => mb_substr($name, 0, 120)]);
            $a->owner_id = $this->user->id;
            $a->page = $this->pageFields($data);
            $a->save();   // o dono entra como administrador

            return ['id' => (string) $a->id];
        }

        $a = is_numeric($id) ? Acervo::find((int) $id) : null;
        if (! $a || ! $this->membership($a->id)) {
            throw new StoreError('permission_denied', 'Você não participa deste acervo.');
        }
        $isOwner = $a->owner_id === $this->user->id;
        $admin = $isOwner || $this->isAdmin($a->id);

        if ($op === 'delete') {
            if (! $isOwner) {
                throw new StoreError('permission_denied', 'Só o dono pode apagar o acervo.');
            }
            $a->delete();

            return ['id' => (string) $a->id];
        }

        $fields = Arr::except((array) $data, ['members', 'ownerId', 'createdAt', 'id']);
        if (array_key_exists('members', (array) $data)) {
            $this->applyMembers($a, (array) $data['members'], $admin);
        }
        if ($fields) {
            if (! $admin) {
                throw new StoreError('permission_denied', 'Só administradores mudam as configurações do acervo.');
            }
            if (isset($fields['name']) && trim((string) $fields['name']) !== '') {
                $a->name = mb_substr(trim((string) $fields['name']), 0, 120);
            }
            $page = $op === 'set' ? [] : (array) ($a->page ?? []);
            $a->page = array_merge($page, $this->pageFields($fields));
            $a->save();
        }

        return ['id' => (string) $a->id];
    }

    private function pageFields(array $data): array
    {
        $keep = Arr::except($data, ['name', 'members', 'ownerId', 'createdAt', 'id', 'people']);
        if (strlen(json_encode($keep)) > 50_000) {
            throw new StoreError('too_large', 'Configurações grandes demais.');
        }

        return $keep;
    }

    /** Aplica o mapa de equipe enviado pela interface, conferindo quem pode mudar o quê. */
    private function applyMembers(Acervo $a, array $members, bool $admin): void
    {
        $me = self::uid($this->user);
        $current = Membership::where('acervo_id', $a->id)->get()->keyBy(fn ($m) => self::uid($m->user_id));

        if (! $admin) {
            // quem não administra só pode sair do acervo
            if (! array_key_exists($me, $members) && $a->owner_id !== $this->user->id) {
                $current[$me]?->delete();
            }

            return;
        }

        foreach ($members as $uid => $m) {
            $userId = self::userIdFrom((string) $uid);
            if (! $userId || ! is_array($m)) {
                continue;
            }
            if ($userId === $a->owner_id) {
                continue;   // o dono é sempre administrador ativo
            }
            $role = in_array($m['role'] ?? '', array_keys(Acervo::ROLES), true) ? $m['role'] : 'viewer';
            $status = ($m['status'] ?? 'active') === 'blocked' ? 'blocked' : 'active';
            $boxes = array_key_exists('boxes', $m) && is_array($m['boxes']) ? array_values(array_map('strval', $m['boxes'])) : null;
            if ($row = $current[$uid] ?? null) {
                $row->update(['role' => $role, 'status' => $status, 'boxes' => $boxes]);
            } elseif (User::whereKey($userId)->where('active', true)->exists()) {
                $a->members()->attach($userId, ['role' => $role, 'status' => $status, 'boxes' => $boxes, 'invited_by' => $this->user->id]);
            }
        }
        foreach ($current as $uid => $row) {
            if (! array_key_exists($uid, $members) && $row->user_id !== $a->owner_id) {
                $row->delete();
            }
        }
        $this->members = [];
    }

    private function writeProfile(string $op, ?string $id, mixed $data): array
    {
        if ($id !== self::uid($this->user) || $op === 'add') {
            throw new StoreError('permission_denied', 'Você só pode alterar o seu perfil.');
        }
        $u = $this->user;
        $p = match ($op) {
            'delete' => null,
            'update' => array_merge((array) ($u->profile ?? []), $data),
            default => $data,
        };
        if ($p !== null && strlen(json_encode($p)) > 64_000) {
            throw new StoreError('too_large', 'Perfil grande demais.');
        }
        $u->forceFill(['profile' => $p])->save();

        return ['id' => $id];
    }

    private function writeInvite(string $op, ?string $code, mixed $data): array
    {
        if ($op === 'add' || ! preg_match('/^[A-Za-z0-9_\-]{6,64}$/', (string) $code)) {
            throw new StoreError('invalid_path', 'Código de convite inválido.');
        }
        $inv = Invitation::where('token', $code)->first();
        $aid = $inv ? (string) $inv->acervo_id : (string) ($data['aid'] ?? '');
        if (! $this->isAdmin($aid)) {
            throw new StoreError('permission_denied', 'Só administradores do acervo gerenciam convites.');
        }

        if ($op === 'delete') {
            $inv?->delete();

            return ['id' => $code];
        }
        $data = (array) $data;
        $expires = isset($data['expiresAt']) ? rescue(fn () => \Illuminate\Support\Carbon::parse($data['expiresAt']), null, false) : null;
        $expires = $expires && $expires->isFuture() ? $expires->min(now()->addDays(31)) : null;

        if (! $inv) {
            if ($op === 'update') {
                throw new StoreError('not_found', 'Convite não encontrado.');
            }
            $inv = new Invitation;
            $inv->forceFill(['acervo_id' => (int) $aid, 'token' => $code, 'invited_by' => $this->user->id]);
        }
        if (array_key_exists('role', $data)) {
            $inv->role = in_array($data['role'], array_keys(Acervo::ROLES), true) ? $data['role'] : 'viewer';
        }
        $inv->role ??= 'viewer';
        if (array_key_exists('boxes', $data)) {
            $inv->boxes = is_array($data['boxes']) ? array_values(array_map('strval', $data['boxes'])) : null;
        }
        if (array_key_exists('label', $data)) {
            $inv->name = mb_substr((string) $data['label'], 0, 120) ?: null;
        }
        if ($expires || ! $inv->expires_at) {
            $inv->expires_at = $expires ?? now()->addDays(7);
        }
        if (! empty($data['revoked'])) {
            $inv->revoked_at ??= now();
        }
        $inv->save();

        return ['id' => $code];
    }
}
