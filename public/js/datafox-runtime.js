/*
 * Data Fox — runtime da interface no Laravel.
 *
 * A página do Data Fox (resources/app/data-fox.html) é a mesma da versão web e conversa com
 * window.claude.use('db' | 'user' | 'assets' | 'downloads' | 'sample'). Aqui essas peças são
 * implementadas sobre a API do Laravel (/api/db, /api/assets, /api/people, /api/ai), que confere
 * as permissões no servidor. Mudanças feitas por outras pessoas chegam por sincronização
 * a cada poucos segundos; as suas aparecem na hora (otimista) e são confirmadas pelo servidor.
 */
(() => {
  const B = window.DATAFOX_SERVER;
  if (!B) return;

  // ?acervo=<id> (vindo do aceite de convite) vira o acervo aberto
  try {
    const u = new URL(location.href);
    const a = u.searchParams.get("acervo");
    if (a) {
      localStorage.setItem("datafox.aid", a);
      u.searchParams.delete("acervo");
      history.replaceState(null, "", u.pathname + u.search + u.hash);
    }
  } catch (e) {}

  const clone = (v) => (v === undefined ? undefined : JSON.parse(JSON.stringify(v)));
  const newId = () => {
    const a = "abcdefghijklmnopqrstuvwxyz0123456789";
    let s = "";
    const r = crypto.getRandomValues(new Uint8Array(20));
    for (const x of r) s += a[x % a.length];
    return s;
  };
  const curAid = () => {
    try { return localStorage.getItem("datafox.aid") || ""; } catch (e) { return ""; }
  };

  let gone = false;
  async function api(url, { method = "GET", json, form } = {}) {
    const headers = { "X-CSRF-TOKEN": B.csrf, Accept: "application/json", "X-Requested-With": "XMLHttpRequest" };
    let body;
    if (json !== undefined) { headers["Content-Type"] = "application/json"; body = JSON.stringify(json); }
    if (form) body = form;
    let r;
    try {
      r = await fetch(url, { method, headers, body, credentials: "same-origin" });
    } catch (e) {
      throw { code: "offline", message: "Sem conexão com o servidor. Confira a internet." };
    }
    if (r.status === 401 || r.status === 419) {
      // sessão expirou: volta ao login (uma vez só)
      if (!gone) { gone = true; location.reload(); }
      throw { code: "signed_out", message: "Sua sessão expirou. Entre de novo." };
    }
    let data = null, raw = "";
    try { raw = await r.text(); data = raw ? JSON.parse(raw) : null; } catch (e) {}
    if (!r.ok) {
      const er = data?.error || {};
      const where = String(url).split("?")[0];
      let msg = er.message || (data?.errors && Object.values(data.errors)[0]?.[0]) || data?.message;
      if (r.status === 413) msg = "O arquivo é grande demais para o servidor (erro 413).";
      if (!msg || msg === "Server Error") msg = `Algo deu errado no servidor (erro ${r.status} em ${where}).`;
      console.error("Data Fox:", r.status, where, raw.slice(0, 500));
      throw { code: er.code || (r.status === 403 ? "permission_denied" : "server_error"), status: r.status, message: msg };
    }
    return data;
  }

  // ------------------------------------------------------------------ db

  const parent = (p) => p.split("/").slice(0, -1).join("/");
  const last = (p) => p.split("/").pop();
  const subs = new Map();           // chave da consulta → assinatura
  let syncTimer = 0, polling = 0;

  function specKey(s) { return JSON.stringify([s.kind, s.path, s.where, s.orderBy, s.limit]); }

  function matches(d, where) {
    for (const [f, op, v] of where) {
      const x = d?.[f];
      if (op === "==" && !(x === v)) return false;
      if (op === "array-contains" && !(Array.isArray(x) && x.includes(v))) return false;
      if (op === "in" && !(Array.isArray(v) && v.includes(x))) return false;
      if (op === "!=" && x === v) return false;
    }
    return true;
  }

  function view(s) {
    let ds = [...s.docs].filter(([, d]) => matches(d, s.where));
    if (s.orderBy) {
      const [f, dir] = s.orderBy;
      ds.sort((a, b) => String(a[1]?.[f] ?? "").localeCompare(String(b[1]?.[f] ?? "")) * (dir === "desc" ? -1 : 1));
    }
    if (s.limit) ds = ds.slice(0, s.limit);
    return ds;
  }

  const mkDoc = (id, data) => ({ id, exists: data !== undefined && data !== null, data: () => (data == null ? undefined : clone(data)), get: (f) => data?.[f], metadata: { hasPendingWrites: false, fromCache: false } });

  function emit(s, force) {
    const ds = view(s);
    const sig = JSON.stringify(ds);
    if (!force && sig === s.lastSig) return;
    s.lastSig = sig;
    if (s.kind === "doc") {
      const d = ds[0];
      const snap = mkDoc(last(s.path), d ? d[1] : undefined);
      s.cbs.forEach((c) => { try { c.next(snap); } catch (e) { console.error(e); } });
      return;
    }
    const docs = ds.map(([id, d]) => mkDoc(id, d));
    const snap = { docs, size: docs.length, empty: !docs.length, forEach: (fn) => docs.forEach(fn), docChanges: () => [], metadata: { hasPendingWrites: false } };
    s.cbs.forEach((c) => { try { c.next(snap); } catch (e) { console.error(e); } });
  }

  function apply(s, res) {
    if (res.error) {
      if (!s.errored) { s.errored = true; s.cbs.forEach((c) => c.err && c.err(res.error)); }
      return;
    }
    s.errored = false;
    s.loaded = true;
    if (res.ver) s.ver = res.ver;
    if (res.same) { if (!s.emitted) { s.emitted = true; emit(s, true); } return; }
    if (res.full) s.docs = new Map((res.docs || []).map((d) => [d.id, d.data]));
    else {
      for (const d of res.docs || []) s.docs.set(d.id, d.data);
      for (const id of res.removed || []) s.docs.delete(id);
    }
    s.emitted = true;
    emit(s, false);
  }

  function reqFor(s) {
    const q = { path: s.kind === "doc" ? parent(s.path) : s.path, kind: s.kind, where: s.where, orderBy: s.orderBy, limit: s.limit };
    if (s.kind === "doc") { q.path = s.path; }
    if (s.loaded && s.ver) q.ver = s.ver;
    // coleções grandes: só o que mudou desde a última rodada (com folga)
    if (s.loaded && s.kind === "col" && !s.limit && /^r\d+$/.test(s.ver || "")) q.since = Math.max(0, +s.ver.slice(1) - 25);
    return q;
  }

  async function runSync(list) {
    const items = list.filter((s) => s.cbs.size || s.oneShot);
    if (!items.length) return;
    const queries = {};
    items.forEach((s, i) => (queries["q" + i] = reqFor(s)));
    try {
      const r = await api("/api/db/sync", { method: "POST", json: { queries } });
      items.forEach((s, i) => apply(s, r.results?.["q" + i] || { error: { code: "server_error", message: "Sem resposta." } }));
    } catch (e) {
      items.forEach((s) => { if (!s.loaded && s.oneShot) s.fail?.(e); });
    }
  }

  function scheduleSync(ms = 0) {
    clearTimeout(syncTimer);
    syncTimer = setTimeout(() => runSync([...subs.values()]), ms);
  }

  function pollLoop() {
    clearTimeout(polling);
    const wait = document.hidden ? 15000 : 3500;
    polling = setTimeout(async () => { await runSync([...subs.values()]); pollLoop(); }, wait);
  }
  document.addEventListener("visibilitychange", () => { if (!document.hidden) { scheduleSync(50); } pollLoop(); });

  function subscribe(spec, next, err) {
    const k = specKey(spec);
    let s = subs.get(k);
    if (!s) { s = { ...spec, docs: new Map(), cbs: new Set(), loaded: false, ver: null, lastSig: null }; subs.set(k, s); }
    const cb = { next, err };
    s.cbs.add(cb);
    if (s.loaded) setTimeout(() => emit(s, true), 0); else scheduleSync(0);
    if (!polling) pollLoop();
    return () => { s.cbs.delete(cb); if (!s.cbs.size) subs.delete(k); };
  }

  async function fetchOnce(spec) {
    const s = { ...spec, docs: new Map(), cbs: new Set(), oneShot: true, loaded: false };
    await new Promise((resolve, reject) => { s.fail = reject; runSync([s]).then(resolve, reject); });
    if (s.errored) throw { code: "permission_denied", message: "Sem acesso." };
    return view(s);
  }

  // aplica a mudança na hora em todas as consultas abertas que mostram este documento
  function local(path, op, data) {
    const col = parent(path), id = last(path);
    for (const s of subs.values()) {
      if (s.kind === "col" && s.path === col) {
        if (op === "delete") s.docs.delete(id);
        else s.docs.set(id, op === "update" ? { ...(s.docs.get(id) || {}), ...clone(data) } : clone(data));
        emit(s, false);
      } else if (s.kind === "doc" && s.path === path) {
        if (op === "delete") s.docs.delete(id);
        else s.docs.set(id, op === "update" ? { ...(s.docs.get(id) || {}), ...clone(data) } : clone(data));
        emit(s, false);
      }
    }
  }
  const isVirtual = (path) => /^(acervos|profiles|invites)(\/[^/]+)?$/.test(path);

  async function write(op, path, data) {
    const virtual = isVirtual(path);
    if (!virtual) local(path, op, data);
    try {
      const r = await api("/api/db/write", { method: "POST", json: { op, path, data } });
      scheduleSync(virtual ? 0 : 400);
      return r;
    } catch (e) {
      // desfaz o otimista: recarrega as consultas desta coleção
      for (const s of subs.values()) if (s.path === parent(path) || s.path === path) { s.loaded = false; s.ver = null; }
      scheduleSync(0);
      throw e;
    }
  }

  function query(path, spec = { where: [], orderBy: null, limit: null }) {
    const full = { kind: "col", path, ...spec };
    return {
      path,
      where: (f, op, v) => query(path, { ...spec, where: [...spec.where, [f, op, v]] }),
      orderBy: (f, d = "asc") => query(path, { ...spec, orderBy: [f, d] }),
      limit: (n) => query(path, { ...spec, limit: n }),
      async get() { const ds = await fetchOnce(full); const docs = ds.map(([id, d]) => mkDoc(id, d)); return { docs, size: docs.length, empty: !docs.length, forEach: (fn) => docs.forEach(fn) }; },
      onSnapshot(next, err) { return subscribe(full, next, err); },
      async add(d) {
        if (isVirtual(path)) { const r = await write("add", path, d); return docRef(path + "/" + r.id); }
        const id = newId(); await write("set", path + "/" + id, d); return docRef(path + "/" + id);
      },
      doc: (id) => docRef(path + "/" + (id || newId())),
    };
  }

  function docRef(path) {
    const spec = { kind: "doc", path, where: [], orderBy: null, limit: null };
    return {
      id: last(path), path,
      async get() { const ds = await fetchOnce(spec); return mkDoc(last(path), ds[0] ? ds[0][1] : undefined); },
      set: (d) => write("set", path, d).then(() => undefined),
      update: (d) => write("update", path, d).then(() => undefined),
      delete: () => write("delete", path).then(() => undefined),
      onSnapshot(next, err) { return subscribe(spec, next, err); },
      collection: (c) => query(path + "/" + c),
    };
  }

  const db = { collection: (p) => query(p), doc: (p) => docRef(p) };

  // ------------------------------------------------------------------ user

  const me = { ...B.user, isOwner: false, canEdit: true };
  const user = {
    me: async () => clone(me),
    id: async () => me.id,
    isOwner: async () => false,
    canEdit: async () => true,
    can: async () => true,
    profiles: async (ids) => api("/api/people/profiles", { method: "POST", json: { ids: Array.isArray(ids) ? ids : [ids] } }),
    search: async (q) => api("/api/people/search?q=" + encodeURIComponent(q || "")),
  };

  // ------------------------------------------------------------------ assets

  const assets = {
    async upload(blob, opts = {}) {
      const f = new FormData();
      const type = opts.type || blob.type || "application/octet-stream";
      f.append("file", blob instanceof File ? blob : new File([blob], opts.name || "arquivo", { type }));
      f.append("type", type);
      f.append("aid", curAid());
      return api("/api/assets", { method: "POST", form: f });
    },
    delete: async (id) => api("/api/assets/" + encodeURIComponent(id), { method: "DELETE" }),
    list: async () => ({ assets: [] }),
  };

  // ------------------------------------------------------------------ downloads

  const downloads = {
    async save({ filename, data, mimeType }) {
      const blob = data instanceof Blob ? data : new Blob([data], { type: mimeType || "application/octet-stream" });
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url; a.download = filename || "arquivo"; a.rel = "noopener";
      document.body.append(a); a.click(); a.remove();
      setTimeout(() => URL.revokeObjectURL(url), 60000);
      return {};
    },
  };

  // ------------------------------------------------------------------ sample (IA)

  const sample = B.ai ? {
    limits: async () => ({ images: { maxCount: B.ai.maxCount } }),
    async json(prompt, opts = {}) {
      const f = new FormData();
      f.append("prompt", prompt);
      f.append("modelTier", opts.modelTier || "default");
      (opts.images || []).slice(0, B.ai.maxCount).forEach((b, i) => f.append("images[]", b instanceof Blob ? b : new Blob([b]), "img" + i + ".jpg"));
      const r = await api("/api/ai/json", { method: "POST", form: f });
      return r.result;
    },
  } : null;

  window.claude = { use: async (n) => ({ db, user, assets, downloads, sample }[n] || null) };
})();
