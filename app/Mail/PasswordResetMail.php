<?php

namespace App\Mail;

use App\Models\User;
use App\Support\Site;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PasswordResetMail extends Mailable
{
    public function __construct(public User $user, public string $link) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Nova senha · '.Site::public()['name']);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.password-reset', with: ['user' => $this->user, 'link' => $this->link, 'brand' => Site::public()['name']]);
    }
}
