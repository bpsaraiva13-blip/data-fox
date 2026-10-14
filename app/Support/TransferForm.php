<?php

namespace App\Support;

use App\Models\Acervo;
use App\Models\Box;
use App\Models\Item;
use App\Services\ItemTransfer;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/** Ações "Enviar a outro acervo" para uma peça, várias peças ou uma caixa. */
class TransferForm
{
    public static function visible(): bool
    {
        return ItemTransfer::targets(auth()->user())->isNotEmpty();
    }

    public static function fields(bool $chooseBox = true): array
    {
        return array_values(array_filter([
            Select::make('target')->label('Acervo de destino')->required()->live()
                ->options(fn () => ItemTransfer::targets(auth()->user())->pluck('name', 'id'))
                ->default(fn () => ItemTransfer::targets(auth()->user())->first()?->id),
            $chooseBox ? Select::make('box')->label('Caixa no destino')->native(false)
                ->options(fn (Get $get) => [ItemTransfer::SAME_BOX => 'Mesma caixa da origem', 'none' => 'Sem caixa']
                    + ($get('target') ? Box::withoutGlobalScope('acervo')->where('acervo_id', $get('target'))->naturalOrder()->pluck('name', 'id')->all() : []))
                ->default(ItemTransfer::SAME_BOX)->required()->selectablePlaceholder(false) : null,
            Radio::make('mode')->label('Na origem')->inline()->default('move')->required()
                ->options(['move' => 'Mover (sai deste acervo)', 'copy' => 'Copiar (fica nos dois)']),
            Text::make('Fotos, ficha, tags, documentos e movimentações vão juntos. O Nº de tombo é mantido; se já existir no destino, a peça recebe um número novo.'),
        ]));
    }

    public static function run(Collection $items, array $data, ?Box $box = null): bool
    {
        $target = auth()->user()->acervos()->whereKey($data['target'] ?? 0)->first();
        if (! $target) {
            return false;
        }
        $own = $items->filter(fn (Item $i) => (int) $i->acervo_id === (int) Acervo::current()?->id)->values();
        if ($own->count() < $items->count()) {
            Notification::make()->warning()->title(($items->count() - $own->count()).' peça(s) de caixa vinculada ficaram de fora')
                ->body('Elas pertencem a outro acervo e só podem ser enviadas de lá.')->send();
        }
        if ($own->isEmpty()) {
            return false;
        }
        $items = $own;
        $dest = $box ? ItemTransfer::SAME_BOX : (($data['box'] ?? ItemTransfer::SAME_BOX) === 'none' ? null : $data['box']);
        // envios grandes vão para a fila: a pessoa segue usando o sistema e recebe o aviso no sininho
        if ($items->count() > 15) {
            \App\Jobs\TransferItems::dispatch($items->pluck('id')->all(), $target->id, $data['mode'] ?? 'move', $dest, $box?->id, auth()->id());
            Notification::make()->info()->title('Envio em segundo plano')
                ->body("{$items->count()} peças para {$target->name}. Você recebe um aviso no sininho quando terminar.")->send();

            return false;
        }
        $r = app(ItemTransfer::class)->send($items, $target, $data['mode'] ?? 'move', $dest, $box);
        $verb = ($data['mode'] ?? 'move') === 'move' ? 'enviada(s)' : 'copiada(s)';
        Notification::make()->success()
            ->title("{$r['count']} peça(s) {$verb} para {$target->name}")
            ->body($r['renumbered'] ? "{$r['renumbered']} receberam Nº de tombo novo no destino." : null)
            ->send();

        return ($data['mode'] ?? 'move') === 'move';
    }

    public static function itemAction(): Action
    {
        return Action::make('transfer')->label('Enviar a outro acervo')->icon(Heroicon::OutlinedArrowsRightLeft)
            ->visible(fn () => static::visible())
            ->modalHeading('Enviar a outro acervo')->modalSubmitActionLabel('Enviar')
            ->schema(static::fields())
            ->action(function (Item $record, array $data, $livewire) {
                if (static::run(collect([$record]), $data)) {
                    $livewire->redirect(\App\Filament\Resources\Items\ItemResource::getUrl('index'));
                }
            });
    }

    public static function bulkAction(): BulkAction
    {
        return BulkAction::make('transfer')->label('Enviar a outro acervo')->icon(Heroicon::OutlinedArrowsRightLeft)
            ->visible(fn () => static::visible())
            ->modalHeading('Enviar peças a outro acervo')->modalSubmitActionLabel('Enviar')
            ->schema(static::fields())
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records, array $data) => static::run($records, $data));
    }

    public static function boxAction(): Action
    {
        return Action::make('transfer')->label('Enviar a outro acervo')->icon(Heroicon::OutlinedArrowsRightLeft)
            ->visible(fn () => static::visible())
            ->modalHeading(fn (Box $record) => "Enviar a caixa {$record->name}")
            ->modalDescription('A caixa vai com todas as peças. Se o destino já tiver uma caixa com o mesmo nome, as peças entram nela.')
            ->modalSubmitActionLabel('Enviar caixa')
            ->schema(static::fields(chooseBox: false))
            ->action(fn (Box $record, array $data) => static::run(Item::where('box_id', $record->id)->get(), $data, $record));
    }
}
