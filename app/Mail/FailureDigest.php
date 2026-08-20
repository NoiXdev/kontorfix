<?php

namespace App\Mail;

use App\Models\Organization;
use App\Support\DigestLine;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class FailureDigest extends Mailable
{
    use Queueable, SerializesModels;

    /** @param list<DigestLine> $lines */
    public function __construct(
        public Organization $organization,
        public array $lines,
    ) {}

    public function envelope(): Envelope
    {
        $count = count($this->lines);

        return new Envelope(
            subject: $count === 1
                ? '1 Fehler in '.config('app.name')
                : "{$count} Fehler in ".config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.failure-digest');
    }
}
