<?php

namespace App\Mail;

use App\Models\Acervo;
use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class InvitationMail extends Mailable
{
    use Queueable;

    public function __construct(public Invitation $invitation) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Convite para o acervo '.$this->invitation->acervo->name.' no Data Fox');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.invitation', with: [
            'inv' => $this->invitation,
            'acervo' => $this->invitation->acervo,
            'role' => Acervo::ROLES[$this->invitation->role] ?? $this->invitation->role,
            'url' => $this->invitation->url(),
        ]);
    }
}
