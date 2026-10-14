<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8">
<style>
    @page { margin: 12mm; }
    body { margin: 0; font-family: "DejaVu Sans", sans-serif; color: #15211A; }
    .big, .small { border: .3mm solid #C8C8C3; border-radius: 4mm; overflow: hidden; }
    .big { height: 100mm; padding: 10mm; margin-bottom: 8mm; }
    .big img { float: left; width: 72mm; height: 72mm; margin-right: 10mm; }
    .big h1 { font-size: 30pt; margin: 6mm 0 4mm; line-height: 1.1; }
    .small { height: 60mm; padding: 6mm; }
    .small img { float: left; width: 44mm; height: 44mm; margin-right: 6mm; }
    .small h2 { font-size: 15pt; margin: 4mm 0 3mm; line-height: 1.15; }
    .m { color: #6D756F; }
    table { width: 100%; border-spacing: 6mm 6mm; margin: -6mm; }
    td { width: 50%; }
    .tip { font-size: 8pt; color: #6D756F; margin-top: 4mm; }
</style></head>
<body>
<div class="big"><img src="{{ $qr }}"><h1>{{ $box->name }}</h1><div class="m" style="font-size:13pt">{{ $box->location }}</div><div class="m" style="font-size:11pt;margin-top:3mm">{{ $box->acervo?->name }}</div></div>
<table>
    @for ($r = 0; $r < 2; $r++)
        <tr>@for ($c = 0; $c < 2; $c++)<td><div class="small"><img src="{{ $qr }}"><h2>{{ $box->name }}</h2><div class="m">{{ $box->location }}</div><div class="m" style="font-size:9pt;margin-top:2mm">{{ $box->acervo?->name }}</div></div></td>@endfor</tr>
    @endfor
</table>
<div class="tip">Cole a etiqueta grande na tampa e as menores nas laterais. Leia o QR com a câmera do celular para abrir a caixa.</div>
</body></html>
