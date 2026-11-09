/* Data Fox — cor de destaque e tema (o mesmo código da interface), para as telas de entrar, convite e verificação. */
(function(){
  const ACCENTS=[["#1F4D36","Verde"],["#1E4E79","Azul"],["#1D5C63","Petróleo"],["#4B3A8F","Violeta"],["#7A2E4A","Vinho"],["#9A4A26","Terracota"],["#8A6A12","Mostarda"],["#2F3437","Grafite"]];
  const DEF_ACCENT="#1F4D36";
  function hexRgb(h){h=String(h||"").replace("#","");if(h.length===3)h=h.split("").map(c=>c+c).join("");const n=parseInt(h,16);return isNaN(n)||h.length!==6?null:[n>>16&255,n>>8&255,n&255]}
  function mixRgb(a,b,t){return a.map((v,i)=>Math.round(v+(b[i]-v)*t))}
  function toHex(c){return"#"+c.map(v=>v.toString(16).padStart(2,"0")).join("")}
  function lum(c){const f=v=>{v/=255;return v<=.03928?v/12.92:((v+.055)/1.055)**2.4};return .2126*f(c[0])+.7152*f(c[1])+.0722*f(c[2])}
  /* escurece a cor até ter contraste suficiente com branco (botões e textos em destaque) */
  function deepen(c){let x=c;for(let i=0;i<20&&lum(x)>.11;i++)x=mixRgb(x,[0,0,0],.12);return x}
  function accentCss(hex){const raw=hexRgb(hex);if(!raw||hex.toLowerCase()===DEF_ACCENT.toLowerCase())return"";
    const W=[255,255,255],K=[0,0,0],DBG=[18,20,18],b=deepen(raw),rgba=(c,a)=>`rgba(${c.join(",")},${a})`;
    const L=`--accent:${toHex(b)};--accent-soft:${rgba(b,.09)};--accent-ink:#FFFFFF;--sage:${toHex(mixRgb(b,W,.45))};--mint:${toHex(mixRgb(b,W,.84))};--mint-ink:${toHex(b)};--brass:${toHex(mixRgb(b,W,.28))};--brass-soft:${rgba(mixRgb(b,W,.28),.14)};--label:${toHex(b)};--label-ink:${toHex(mixRgb(b,W,.84))};`;
    let a=mixRgb(raw,W,.45);for(let i=0;i<12&&lum(a)<.32;i++)a=mixRgb(a,W,.15);
    const D=`--accent:${toHex(a)};--accent-soft:${rgba(a,.13)};--accent-ink:${toHex(mixRgb(raw,K,.85))};--sage:${toHex(mixRgb(raw,W,.3))};--mint:${toHex(mixRgb(raw,DBG,.72))};--mint-ink:${toHex(mixRgb(a,W,.45))};--brass:${toHex(a)};--brass-soft:${rgba(a,.13)};--label:${toHex(mixRgb(raw,DBG,.6))};--label-ink:${toHex(mixRgb(a,W,.55))};`;
    return`:root{${L}}@media (prefers-color-scheme: dark){:root:not([data-theme="light"]){${D}}}:root[data-theme="dark"]{${D}}`}
  function applyLook(accent,theme){try{document.getElementById("accent-css").textContent=accentCss(accent||DEF_ACCENT)}catch(e){}
    const r=document.documentElement;if(theme==="light"||theme==="dark")r.setAttribute("data-theme",theme);else r.removeAttribute("data-theme");
    try{localStorage.setItem("datafox.look",JSON.stringify({accent:accent||"",theme:theme||""}))}catch(e){}}
  try{var lk=JSON.parse(localStorage.getItem("datafox.look")||"null");if(lk)applyLook(lk.accent,lk.theme)}catch(e){}
})();
