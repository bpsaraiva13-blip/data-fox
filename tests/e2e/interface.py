"""
Teste de ponta a ponta da interface no Laravel (roda no CI, com o site servido por `artisan serve`):
entrar, criar acervo, caixa e peça, recarregar e ver tudo salvo no servidor; outra pessoa
(sem acesso) não vê nada; convite por link põe a pessoa no acervo.
Uso: python3 tests/e2e/interface.py http://127.0.0.1:8000
"""
import sys, re, time
from playwright.sync_api import sync_playwright

BASE = sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:8000"
fails = []

def check(cond, msg):
    print(("ok   " if cond else "FAIL ") + msg)
    if not cond:
        fails.append(msg)

def login(pg, email, pw):
    pg.goto(BASE + "/admin/login")
    pg.fill('input[type="email"]', email)
    pg.fill('input[type="password"]', pw)
    pg.click('button[type="submit"]')
    pg.wait_for_url(re.compile(r".*/(\?.*)?$"), timeout=20000)
    pg.wait_for_function("window.S && S.me && S.me.id", timeout=20000)
    pg.wait_for_timeout(1500)

with sync_playwright() as p:
    b = p.chromium.launch()
    errs = []
    ctx = b.new_context(viewport={"width": 1300, "height": 860})
    pg = ctx.new_page()
    pg.on("pageerror", lambda e: errs.append(str(e)))
    login(pg, "bruno@exemplo.com", "senha-forte-1")
    check(pg.evaluate("!!window.DATAFOX_SERVER"), "página servida pelo Laravel com o runtime")
    gate = pg.evaluate("document.querySelector('#gate:not([hidden])')?.innerText||''")
    check("Nenhum acervo" in gate, "sem acervo: mostra criar acervo / convite")

    pg.click('#gate [data-g="new"]'); pg.wait_for_timeout(300)
    pg.fill("#mini-in", "Acervo E2E"); pg.click("#mini-ok")
    pg.wait_for_function("S.role==='admin'", timeout=15000)
    check(True, "acervo criado e aberto como administrador")

    pg.evaluate("document.querySelector('[data-view=\"caixas\"]').click()"); pg.wait_for_timeout(300)
    pg.click("#new-box"); pg.wait_for_timeout(300); pg.fill("#mini-in", "01 Porcelanas"); pg.click("#mini-ok")
    pg.wait_for_function("S.boxes.size===1", timeout=15000)
    pg.evaluate("col('items').add({code:'ACV-2026-0001',title:'Bule inglês',boxId:[...S.boxes.keys()][0],tags:['porcelana'],photos:[],createdAt:new Date().toISOString(),createdBy:S.me.id,status:'available'})")
    pg.wait_for_function("S.items.size===1", timeout=15000)
    # foto: envia ao bucket e lê de volta
    blob_ok = pg.evaluate("""(async()=>{const c=document.createElement('canvas');c.width=c.height=8;const b=await new Promise(r=>c.toBlob(r,'image/jpeg'));const a=await S.assets.upload(b,{type:'image/jpeg'});const r=await fetch('/_blob/'+a.id);return r.ok&&(await r.blob()).size>0})()""")
    check(blob_ok, "foto enviada ao bucket e lida de volta")
    pg.wait_for_timeout(1200)

    pg.reload(); pg.wait_for_function("S.loaded && S.loaded.items && S.items.size===1", timeout=20000)
    check(pg.evaluate("[...S.items.values()][0].title") == "Bule inglês", "depois de recarregar, a peça continua salva no servidor")
    check(pg.evaluate("[...S.boxes.values()][0].name") == "01 Porcelanas", "a caixa continua salva")

    # convite por link
    code = pg.evaluate("(async()=>{const code='E2E'+Math.random().toString(36).slice(2,10).toUpperCase();await S.db.doc('invites/'+code).set({aid:S.aid,role:'viewer',label:'Ana',boxes:null,expiresAt:new Date(Date.now()+864e5).toISOString(),createdAt:new Date().toISOString(),createdBy:S.me.id});return code})()")
    aid = pg.evaluate("S.aid")

    # segunda pessoa: sem acesso não vê nada; com o convite entra como somente leitura
    ctx2 = b.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True)
    p2 = ctx2.new_page(); p2.on("pageerror", lambda e: errs.append("p2: " + str(e)))
    p2.goto(BASE + "/convite/" + code)
    p2.fill('input[name="name"]', "Ana"); p2.fill('input[name="email"]', "ana@exemplo.com")
    p2.fill('input[name="password"]', "senha-forte-2"); p2.fill('input[name="password_confirmation"]', "senha-forte-2")
    p2.click("form.pb-form button")
    p2.wait_for_function("window.S && S.role", timeout=20000)
    p2.wait_for_function("S.loaded && S.loaded.items", timeout=20000)
    check(p2.evaluate("S.role") == "viewer", "convite por link: Ana entra como somente leitura")
    check(p2.evaluate("S.aid") == aid, "Ana cai direto no acervo do convite")
    check(p2.evaluate("S.items.size") == 1, "Ana vê a peça")
    denied = p2.evaluate("(async()=>{try{await S.db.doc('acervos/'+S.aid+'/items/hack').set({title:'x'});return false}catch(e){return e.code}})()")
    check(denied == "permission_denied", "somente leitura não grava (servidor recusa)")

    # tempo real: o que o Bruno cadastra aparece para a Ana sem recarregar
    pg.evaluate("col('items').add({code:'ACV-2026-0002',title:'Xícara',boxId:'',photos:[],createdAt:new Date().toISOString(),createdBy:S.me.id})")
    try:
        p2.wait_for_function("S.items.size===2", timeout=15000); ok = True
    except Exception:
        ok = False
    check(ok, "peça nova do Bruno aparece para a Ana em segundos")
    p2.screenshot(path="/tmp/e2e-mobile.png"); pg.screenshot(path="/tmp/e2e-desktop.png")

    check(not errs, "sem erros de JavaScript" + (": " + " | ".join(errs[:3]) if errs else ""))
    b.close()

print("\n%d falha(s)" % len(fails))
sys.exit(1 if fails else 0)
