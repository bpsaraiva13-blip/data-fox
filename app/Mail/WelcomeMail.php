<?php

namespace App\Mail;

use App\Models\Acervo;
use App\Models\User;
use App\Support\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Conta criada por um administrador: link para a pessoa criar a senha. */
class WelcomeMail extends Mailable
{
    use Queueable;

    public function __construct(public User $user, public string $link, public ?User $by = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Seu acesso ao '.Site::public()['name']);
    }

    public function content(): Content
    {
        $acervos = $this->user->acervos()->wherePivot('status', 'active')->orderBy('name')->get()
            ->map(fn ($a) => $a->name.' ('.(Acervo::ROLES[$a->pivot->role] ?? $a->pivot->role).')');

        return new Content(markdown: 'mail.welcome', with: [
            'user' => $this->user, 'link' => $this->link, 'by' => $this->by, 'acervos' => $acervos,
            'brand' => Site::public()['name'],
            'days' => (int) round(config('auth.passwords.welcome.expire', 10080) / 1440),
        ]);
    }
}
