<?php

namespace App\Filament\Resources\Collectors;

use App\Filament\Resources\Collectors\Pages\CreateCollector;
use App\Filament\Resources\Collectors\Pages\EditCollector;
use App\Filament\Resources\Collectors\Pages\ListCollectors;
use App\Filament\Resources\Collectors\RelationManagers\MovementsRelationManager;
use App\Models\Collector;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Http;
use Throwable;
use UnitEnum;

class CollectorResource extends Resource
{
    protected static ?string $model = Collector::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Acervo';

    protected static ?string $modelLabel = 'colecionador';

    protected static ?string $pluralModelLabel = 'colecionadores';

    protected static ?string $slug = 'colecionadores';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Identificação')->columns(6)->schema([
                TextInput::make('name')->label('Nome completo')->required()->maxLength(160)->columnSpan(4),
                TextInput::make('nick')->label('Apelido')->maxLength(60)->columnSpan(2),
                Select::make('kind')->label('Tipo')->options(Collector::KINDS)->default('collector')->required()->native(false)->columnSpan(2),
                TextInput::make('doc')->label('CPF / CNPJ')->maxLength(20)->columnSpan(2),
                DatePicker::make('birth')->label('Aniversário')->native(false)->displayFormat('d/m/Y')->columnSpan(2),
                TagsInput::make('interests')->label('O que coleciona')->placeholder('ex.: chaveiros, moedas')->columnSpanFull(),
            ]),
            Section::make('Contato')->columns(6)->schema([
                TextInput::make('phone')->label('Telefone / WhatsApp')->tel()->maxLength(30)->columnSpan(2),
                TextInput::make('phone2')->label('Outro telefone')->tel()->maxLength(30)->columnSpan(2),
                TextInput::make('instagram')->label('Instagram')->prefix('@')->maxLength(60)->columnSpan(2)
                    ->dehydrateStateUsing(fn ($state) => $state ? ltrim($state, '@') : null),
                TextInput::make('email')->label('E-mail')->email()->maxLength(160)->columnSpanFull(),
            ]),
            Section::make('Endereço')->columns(6)->schema([
                TextInput::make('cep')->label('CEP')->mask('99999-999')->placeholder('00000-000')->columnSpan(2)
                    ->live(onBlur: true)
                    ->helperText('Ao sair do campo, o endereço é preenchido pelo CEP.')
                    ->afterStateUpdated(fn (?string $state, Set $set, Get $get) => static::fillFromCep($state, $set, $get)),
                TextInput::make('street')->label('Rua / logradouro')->maxLength(160)->columnSpan(4),
                TextInput::make('number')->label('Número')->maxLength(20)->columnSpan(1),
                TextInput::make('complement')->label('Complemento')->maxLength(80)->columnSpan(2),
                TextInput::make('district')->label('Bairro')->maxLength(80)->columnSpan(3),
                TextInput::make('city')->label('Cidade')->maxLength(80)->columnSpan(4),
                Select::make('uf')->label('UF')->options(array_combine(Collector::UFS, Collector::UFS))->searchable()->columnSpan(2),
            ]),
            Textarea::make('notes')->label('Observações')->rows(3)->placeholder('Preferências, combinados, como conheceu…'),
        ]);
    }

    /** Busca o endereço no ViaCEP e preenche só os campos vazios. */
    public static function fillFromCep(?string $cep, Set $set, Get $get): void
    {
        $d = preg_replace('/\D/', '', (string) $cep);
        if (strlen($d) !== 8) {
            return;
        }
        try {
            $j = Http::timeout(5)->acceptJson()->get("https://viacep.com.br/ws/{$d}/json/")->json();
        } catch (Throwable) {
            return;
        }
        if (! is_array($j) || ($j['erro'] ?? false)) {
            return;
        }
        foreach (['street' => 'logradouro', 'district' => 'bairro', 'city' => 'localidade', 'uf' => 'uf', 'complement' => 'complemento'] as $field => $key) {
            if (blank($get($field)) && filled($j[$key] ?? null)) {
                $set($field, $j[$key]);
            }
        }
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn ($query) => $query->withCount('movements'))
            ->columns([
                TextColumn::make('name')->label('Nome')->weight('semibold')->searchable(['name', 'nick', 'phone', 'email', 'city'])
                    ->description(fn (Collector $r) => $r->nick),
                TextColumn::make('kind')->label('Tipo')->badge()->color('gray')->formatStateUsing(fn ($state) => Collector::KINDS[$state] ?? $state),
                TextColumn::make('interests')->label('Coleciona')->badge()->limitList(3)->placeholder('—'),
                TextColumn::make('phone')->label('Telefone')->placeholder('—')->copyable(),
                TextColumn::make('city')->label('Cidade')->formatStateUsing(fn ($state, Collector $r) => $state.($r->uf ? '/'.$r->uf : ''))->placeholder('—')->sortable(),
                TextColumn::make('movements_count')->label('Movimentações')->numeric()->alignEnd()->sortable(),
            ])
            ->filters([
                SelectFilter::make('kind')->label('Tipo')->options(Collector::KINDS),
                SelectFilter::make('uf')->label('Estado')->options(fn () => Collector::query()->whereNotNull('uf')->distinct()->orderBy('uf')->pluck('uf', 'uf')),
            ])
            ->recordActions([
                Action::make('whatsapp')->label('WhatsApp')->icon(Heroicon::OutlinedChatBubbleLeftRight)->color('success')
                    ->url(fn (Collector $r) => $r->whatsappUrl(), true)->visible(fn (Collector $r) => (bool) $r->phone),
                Action::make('map')->label('Mapa')->icon(Heroicon::OutlinedMapPin)->color('gray')
                    ->url(fn (Collector $r) => $r->mapsUrl(), true)->visible(fn (Collector $r) => (bool) $r->mapsUrl()),
                EditAction::make()->iconButton(),
                DeleteAction::make()->iconButton()->modalDescription('O cadastro fica 30 dias na lixeira. As movimentações já registradas continuam.'),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->emptyStateHeading('Nenhum colecionador')
            ->emptyStateDescription('Cadastre quem compra, vende, troca ou pega peças emprestadas. Depois é só escolher o nome ao registrar uma movimentação.');
    }

    public static function getRelations(): array
    {
        return [MovementsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCollectors::route('/'),
            'create' => CreateCollector::route('/novo'),
            'edit' => EditCollector::route('/{record}'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'nick', 'phone', 'city'];
    }
}
