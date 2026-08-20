<x-mail::message>
# Fehlerübersicht

Seit der letzten Übersicht sind folgende Fehler aufgetreten:

<x-mail::table>
| Art | Betroffen | Anzahl | Zuletzt | Meldung |
|:----|:----------|-------:|:--------|:--------|
@foreach ($lines as $line)
@php
    // A GFM table row ends at the first newline, so a multi-line summary (git stderr
    // routinely is one) would truncate the row and dump every following line as raw text
    // in the mail body; a literal "|" would insert extra columns. Both are neutralised
    // before Str::limit() runs, not after — limiting first could still leave a bare
    // newline or pipe inside the kept slice.
    $safeSummary = \Illuminate\Support\Str::limit(
        str_replace(['|', "\r\n", "\r", "\n"], ['\\|', ' ', ' ', ' '], $line->latestSummary),
        120,
    );
    $typeLabel = \App\Enums\NotificationEvent::tryFrom($line->type)?->label() ?? $line->type;
@endphp
| {{ $typeLabel }} | {{ $line->subjectLabel }} | {{ $line->count }} | {{ $line->latestAt->format('d.m.Y H:i') }} | {{ $safeSummary }} |
@endforeach
</x-mail::table>

Du bekommst diese Nachricht, weil deine Adresse als Empfänger hinterlegt ist.

<x-mail::button :url="url('/admin/packages')">Zur Verwaltung</x-mail::button>
</x-mail::message>
