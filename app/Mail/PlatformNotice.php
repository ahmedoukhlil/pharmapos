<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** E-mail simple de la plateforme : titre, phrase d'introduction, lignes, lien éventuel. */
class PlatformNotice extends Mailable
{
    use Queueable;

    /** @param array<int, string> $lines */
    public function __construct(
        public string $subjectLine,
        public string $title,
        public string $intro,
        public array $lines = [],
        public ?string $actionUrl = null,
        public ?string $actionLabel = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.notice');
    }
}
