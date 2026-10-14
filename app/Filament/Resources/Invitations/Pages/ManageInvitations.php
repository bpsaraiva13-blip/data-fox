<?php

namespace App\Filament\Resources\Invitations\Pages;

use App\Filament\Resources\Invitations\InvitationResource;
use App\Mail\InvitationMail;
use App\Models\Acervo;
use App\Models\ActivityLog;
use App\Models\Invitation;
use App\Support\BoxAccess;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\Mail;
use Throwable;

class ManageInvitations extends ManageRecords
{
    protected static string $resource = InvitationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Convidar por e-mail')
                ->mutateDataUsing(function (array $data) {
                    $days = (int) ($data['days'] ?? 7);
                    unset($data['days']);
                    $data['boxes'] = BoxAccess::value($data);
                    unset($data['all_boxes']);
                    $data['expires_at'] = now()->addDays($days ?: 7);
                    $data['acervo_id'] = Acervo::current()->id;

                    return $data;
                })
                ->after(function (Invitation $record) {
                    ActivityLog::record('user', null, "Convidou {$record->email} (".Acervo::ROLES[$record->role].')');
                    static::send($record);
                }),
        ];
    }

    public static function send(Invitation $inv): void
    {
        try {
            Mail::to($inv->email)->send(new InvitationMail($inv->load('acervo', 'inviter')));
            Notification::make()->title("Convite enviado para {$inv->email}")->success()->send();
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title('Convite criado, mas o e-mail não saiu')
                ->body('Verifique a configuração de e-mail (MAIL_*). Enquanto isso, use “Link” ou “WhatsApp” para enviar.')
                ->warning()->persistent()->send();
        }
    }

    public function mount(): void
    {
        parent::mount();
        if (request('action') === 'create') {
            $this->mountAction('create');
        }
    }
}
