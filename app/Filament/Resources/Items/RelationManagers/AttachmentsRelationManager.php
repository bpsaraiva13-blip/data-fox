<?php

namespace App\Filament\Resources\Items\RelationManagers;

use App\Models\ActivityLog;
use App\Models\ItemAttachment;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Notas fiscais, certificados e laudos ligados à peça. */
class AttachmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'attachments';

    protected static ?string $title = 'Documentos';

    protected static string|\BackedEnum|null $icon = Heroicon::OutlinedPaperClip;

    public function isReadOnly(): bool
    {
        return ! auth()->user()->canEdit();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Select::make('kind')->label('Tipo')->options(array_combine(ItemAttachment::KINDS, ItemAttachment::KINDS))->required()->default('Nota fiscal'),
            TextInput::make('name')->label('Descrição')->required()->maxLength(120),
            FileUpload::make('path')->label('Arquivo (PDF ou imagem, até 20 MB)')->required()
                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic'])->maxSize(20 * 1024)
                ->disk(config('acervo.disk'))->directory('acervo/documentos')->visibility('private')
                ->storeFileNamesIn('original_name'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('kind')->label('Tipo')->badge()->color('gray'),
                TextColumn::make('name')->label('Descrição')->searchable(),
                TextColumn::make('size')->label('Tamanho')->formatStateUsing(fn ($state) => $state ? number_format($state / 1048576, 1, ',', '.').' MB' : '—'),
                TextColumn::make('user.name')->label('Anexado por'),
                TextColumn::make('created_at')->label('Em')->dateTime('d/m/Y H:i'),
            ])
            ->headerActions([
                CreateAction::make()->label('Anexar documento')->icon(Heroicon::OutlinedPaperClip)
                    ->mutateDataUsing(function (array $data) {
                        unset($data['original_name']);
                        try {
                            $disk = \Illuminate\Support\Facades\Storage::disk(config('acervo.disk'));
                            $data['size'] = $disk->size($data['path']);
                            $data['mime'] = $disk->mimeType($data['path']);
                        } catch (\Throwable) {
                        }

                        return $data;
                    })
                    ->after(fn (Model $record) => ActivityLog::record('update', $this->getOwnerRecord()->id, "{$this->getOwnerRecord()->code} — anexou {$record->kind}")),
            ])
            ->recordActions([
                Action::make('open')->label('Abrir')->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (ItemAttachment $r) => route('attachments.download', $r), true),
                DeleteAction::make()->label('Remover'),
            ]);
    }
}
