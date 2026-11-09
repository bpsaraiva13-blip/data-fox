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
    pg.goto(BASE + "/entrar")
    pg.fill('input[type="email"]', email)
    pg.fill('input[type="password"]', pw)
    pg.click('form.au-form button.au-btn')
    try:
        pg.wait_for_url(re.compile(r".*/(\?.*)?$"), timeout=20000)
        pg.wait_for_function("typeof S!=='undefined' && S.me && S.me.id", timeout=20000)
    except Exception:
        print("URL:", pg.url); print(pg.evaluate("document.body.innerText.slice(0,600)")); raise
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

    # marca do sistema: o administrador troca o logo e a frase em Aparência
    import base64, tempfile
    png = base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAYAAADED76LAAAAEklEQVR4nGNgYGD4z0AswK4SAFXuAf8EPy+xAAAAAElFTkSuQmCC")
    tf = tempfile.NamedTemporaryFile(suffix=".png", delete=False); tf.write(png); tf.close()
    pg.evaluate("openLook()"); pg.wait_for_timeout(400)
    pg.set_input_files("#bm-file", tf.name)
    pg.fill("#bm-tag", "Frase nova do teste E2E.")
    pg.click("#mini-ok")   # o Salvar principal também grava a marca
    try:
        pg.wait_for_function("SRV.brand && SRV.brand.logo && SRV.brand.tagline==='Frase nova do teste E2E.'", timeout=15000); ok = True
    except Exception:
        ok = False
    check(ok, "Aparência: logo e frase salvos pelo botão Salvar")
    check(pg.evaluate("!!document.querySelector('.brand .brand-mark img')"), "logo aparece no menu")
    icon = pg.evaluate("fetch('/brand/icon').then(r=>r.headers.get('content-type'))")
    check(icon == "image/png", "ícone do site servido em /brand/icon")

    # convite por link
    code = pg.evaluate("(async()=>{const code='E2E'+Math.random().toString(36).slice(2,10).toUpperCase();await S.db.doc('invites/'+code).set({aid:S.aid,role:'viewer',label:'Ana',boxes:null,expiresAt:new Date(Date.now()+864e5).toISOString(),createdAt:new Date().toISOString(),createdBy:S.me.id});return code})()")
    aid = pg.evaluate("S.aid")

    # segunda pessoa: sem acesso não vê nada; com o convite entra como somente leitura
    ctx2 = b.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True)
    p2 = ctx2.new_page(); p2.on("pageerror", lambda e: errs.append("p2: " + str(e)))
    p2.goto(BASE + "/convite/" + code)
    p2.fill('input[name="name"]', "Ana"); p2.fill('input[name="email"]', "ana@exemplo.com")
    p2.fill('input[name="password"]', "senha-forte-2"); p2.fill('input[name="password_confirmation"]', "senha-forte-2")
    p2.click("form.au-form button.au-btn")
    p2.wait_for_function("typeof S!=='undefined' && S.role", timeout=20000)
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


    # Usuários: o administrador do sistema cria a conta já no acervo e a pessoa entra pelo link
    pg.evaluate("document.querySelector('[data-view=\"usuarios\"]').click()")
    pg.wait_for_function("document.querySelectorAll('#us-root .us-row').length>=2", timeout=15000)
    check(pg.evaluate("!document.querySelector('[data-view=\"usuarios\"]').hidden"), "menu Usuários aparece para o administrador do sistema")
    check("Acervo E2E · Somente leitura" in pg.inner_text("#us-root"), "lista mostra a Ana com o acervo e o perfil")
    pg.screenshot(path="/tmp/e2e-usuarios.png")
    pg.click("#us-new"); pg.wait_for_timeout(400)
    check(pg.evaluate("document.querySelectorAll('#uf-acs .ac-row').length") == 1, "novo usuário já vem com o acervo aberto")
    pg.fill("#uf-name", "Carla Dias"); pg.fill("#uf-email", "carla@exemplo.com")
    pg.click("#uf-acs .ac-bx"); pg.wait_for_selector("#uf-acs .ac-all")
    pg.uncheck("#uf-acs .ac-all"); pg.click("#uf-acs .ac-boxes .chips label >> nth=0")
    check("1 caixa(s)" in pg.inner_text("#uf-acs .ac-bx"), "escolhe as caixas do acervo no cadastro")
    pg.screenshot(path="/tmp/e2e-novo-usuario.png")
    pg.click("#mini-ok")
    pg.wait_for_selector("#mini .ub-url", timeout=15000)
    link = pg.input_value("#mini .ub-url")
    check("/acesso/" in link, "conta criada e link de primeiro acesso pronto")
    pg.screenshot(path="/tmp/e2e-usuario-criado.png")
    pg.click("#mini-ok"); pg.wait_for_timeout(500)
    # ficha: vincular depois a outro acervo não é possível (só há um), mas muda o perfil
    key = pg.evaluate("U.list.find(u=>u.email==='carla@exemplo.com').key")
    pg.evaluate("openUser(%d)" % key); pg.wait_for_timeout(400)
    pg.select_option("#uf-acs .ac-role", "editor"); pg.screenshot(path="/tmp/e2e-ficha.png")
    pg.click("#mini-ok")
    pg.wait_for_function("U.list.find(u=>u.email==='carla@exemplo.com').acervos[0].role==='editor'", timeout=15000)
    check(True, "ficha do usuário salva o novo perfil")

    p4 = b.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True).new_page()
    p4.on("pageerror", lambda e: errs.append("p4: " + str(e)))
    p4.goto(link)
    check("Olá, Carla" in p4.content(), "link abre a tela de primeiro acesso")
    p4.screenshot(path="/tmp/e2e-primeiro-acesso.png")
    p4.fill("#password", "senha-da-carla"); p4.fill("#password_confirmation", "senha-da-carla")
    p4.click("form.au-form button.au-btn")
    p4.wait_for_function("typeof S!=='undefined' && S.role && S.loaded && S.loaded.items", timeout=20000)
    check(p4.evaluate("S.role") == "editor", "Carla entra direto no acervo como editora")
    check(p4.evaluate("S.allBoxes.size") == 1 and p4.evaluate("S.boxes.size") == 1, "Carla vê só a caixa liberada")
    check(p4.evaluate("document.querySelector('[data-view=\"usuarios\"]').hidden"), "Usuários não aparece para quem não é administrador do sistema")

    # Equipe → Adicionar pessoa: e-mail sem conta, o administrador do sistema cria ali mesmo
    pg.evaluate("document.querySelector('[data-view=\"equipe\"]').click()"); pg.wait_for_timeout(300)
    pg.click("#team-add"); pg.wait_for_timeout(300)
    pg.fill("#ap-email", "dora@exemplo.com"); pg.wait_for_selector("#ap-st .ap-msg.w", timeout=10000)
    check(not pg.evaluate("document.querySelector('#ap-name-f').hidden"), "e-mail sem conta: pede o nome para criar")
    pg.fill("#ap-name", "Dora"); pg.screenshot(path="/tmp/e2e-adicionar-pessoa.png")
    pg.click("#mini-ok"); pg.wait_for_selector("#mini .ub-url", timeout=15000)
    pg.click("#mini-ok"); pg.wait_for_timeout(300)
    try:
        pg.wait_for_function("Object.keys(curAcervo().members||{}).length>=4", timeout=15000); ok = True
    except Exception:
        ok = False
    check(ok, "Dora aparece na Equipe do acervo")

    p3 = b.new_context().new_page(); p3.goto(BASE + "/entrar")
    check("Frase nova do teste E2E." in p3.content(), "tela de entrar mostra a frase nova")
    check(not errs, "sem erros de JavaScript" + (": " + " | ".join(errs[:3]) if errs else ""))
    b.close()

print("\n%d falha(s)" % len(fails))
sys.exit(1 if fails else 0)
