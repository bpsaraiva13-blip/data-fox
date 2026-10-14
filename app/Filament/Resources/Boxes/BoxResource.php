<?php

namespace App\Filament\Resources\Boxes;

use App\Filament\Pages\BatchUpload;
use App\Filament\Resources\Boxes\Pages\ManageBoxes;
use App\Filament\Resources\Items\ItemResource;
use App\Models\Box;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Throwable;
use UnitEnum;

class BoxResource extends Resource
{
    protected static ?string $model = Box::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|UnitEnum|null $navigationGroup = 'Acervo';

    protected static ?string $modelLabel = 'caixa';

    protected static ?string $pluralModelLabel = 'caixas';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components(ItemResource::boxFields())->columns(1);
    }

    /** URL do acervo filtrado por esta caixa. */
    public static function itemsUrl(Box $box): string
    {
        return ItemResource::getUrl('index', ['filters' => ['box_id' => ['value' => $box->id]]]);
    }

    /** Selo com o número da caixa (ou a inicial), na cor da caixa. */
    public static function badge(Box $box, int $size = 48): HtmlString
    {
        $n = Box::leadingNumber($box->name);
        $label = $n !== null ? (string) $n : mb_strtoupper(mb_substr(preg_replace('/^\s*(caixa|cx\.?)\s+/iu', '', $box->name) ?: '?', 0, 1));

        return new HtmlString(sprintf(
            '<span class="df-box-badge" style="--c:%s;width:%dpx;height:%dpx">%s</span>',
            e($box->color ?: '#1F4D36'), $size, $size, e($label)
        ));
    }

    public static function table(Table $table): Table
    {
        $grid = ($table->getLivewire()->layout ?? 'grid') === 'grid';

        $table
            ->modifyQueryUsing(fn ($q) => $q->withCount('items')->with('creator')->reorder()->naturalOrder())
            ->recordUrl(fn (Box $r) => static::itemsUrl($r))
            ->paginated([24, 48, 96, 'all'])
            ->recordActions([
                Action::make('batch')->label('Lote')->icon(Heroicon::OutlinedArrowUpTray)->color('gray')
                    ->visible(fn () => auth()->user()->canEdit())
                    ->url(fn (Box $r) => BatchUpload::getUrl(['box' => $r->id])),
                EditAction::make()->iconButton(),
                DeleteAction::make()->iconButton()
                    ->modalDescription(fn (Box $r) => $r->items_count ? "As {$r->items_count} peça(s) desta caixa ficarão como “Sem caixa”." : null),
            ]);

        if ($grid) {
            return $table
                ->contentGrid(['md' => 2, 'xl' => 3])
                ->columns([
                    Stack::make([
                        Split::make([
                            TextColumn::make('badge')->state(fn (Box $r) => static::badge($r, 52))->html()->grow(false),
                            Stack::make([
                                TextColumn::make('name')->weight('bold')->size('lg')->searchable(),
                                TextColumn::make('location')->color('gray')->placeholder('Sem local definido'),
                            ]),
                        ])->extraAttributes(['class' => 'df-box-top']),
                        ViewColumn::make('items_count')->view('filament.box-bar'),
                        TextColumn::make('created_at')->color('gray')->size('sm')
                            ->formatStateUsing(fn (Box $r) => "{$r->items_count} peça".($r->items_count === 1 ? '' : 's').' · criada '.$r->created_at->diffForHumans()),
                    ])->space(3)->extraAttributes(['class' => 'df-box-card']),
                ]);
        }

        return $table->columns([
            TextColumn::make('badge')->label('')->state(fn (Box $r) => static::badge($r, 40))->html()->width('56px'),
            TextColumn::make('name')->label('Caixa')->weight('semibold')->searchable(),
            TextColumn::make('location')->label('Local')->placeholder('—')->color('gray'),
            TextColumn::make('items_count')->label('Peças')->numeric()->alignEnd(),
            TextColumn::make('creator.name')->label('Criada por')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('created_at')->label('Criada')->since(),
        ]);
    }

    /** Menu lateral: além de "Caixas", cada caixa vira um atalho, já na ordem natural. */
    public static function getNavigationItems(): array
    {
        $items = parent::getNavigationItems();

        try {
            foreach (Box::naturalOrder()->withCount('items')->limit(60)->get() as $i => $box) {
                $items[] = NavigationItem::make($box->name)
                    ->group('Caixas')
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->badge((string) $box->items_count, 'gray')
                    ->sort($i)
                    ->url(static::itemsUrl($box))
                    ->isActiveWhen(fn () => (string) data_get(request()->query('filters'), 'box_id.value') === (string) $box->id);
            }
        } catch (Throwable) {
            // sem banco durante o build: mostra só o item principal
        }

        return $items;
    }

    public static function getPages(): array
    {
        return ['index' => ManageBoxes::route('/')];
    }
}
