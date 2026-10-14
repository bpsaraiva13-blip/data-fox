<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8">
<style>
    @page { margin: 13mm 9mm; }
    body { margin: 0; font-family: "DejaVu Sans", sans-serif; color: #15211A; }
    table { border-collapse: separate; border-spacing: 0; width: 192mm; }
    td { width: 64mm; height: 33.9mm; padding: 0; vertical-align: top; }
    .l { margin: 1mm; height: 31.9mm; border: .25mm solid #E1E1DC; border-radius: 2.5mm; padding: 2mm; overflow: hidden; }
    .l img { float: left; width: 26mm; height: 26mm; margin-right: 2mm; }
    .c { font-family: "DejaVu Sans Mono", monospace; font-weight: bold; font-size: 8pt; color: #1F4D36; }
    .t { font-weight: bold; font-size: 7pt; line-height: 1.25; margin-top: 1mm; max-height: 13mm; overflow: hidden; }
    .b { font-size: 6pt; color: #6D756F; margin-top: 1.5mm; }
    tr { page-break-inside: avoid; }
</style></head>
<body>
<table>
    @foreach ($labels->chunk(3) as $row)
        <tr>
            @foreach ($row as $l)
                <td><div class="l"><img src="{{ $l['qr'] }}"><div class="c">{{ $l['item']->code }}</div><div class="t">{{ \Illuminate\Support\Str::limit($l['item']->title, 70) }}</div><div class="b">{{ $l['item']->box?->name ?? 'Sem caixa' }}</div></div></td>
            @endforeach
            @for ($k = $row->count(); $k < 3; $k++)<td></td>@endfor
        </tr>
    @endforeach
</table>
</body></html>
