<x-mail::message>
# Fehlerübersicht

Seit der letzten Übersicht sind folgende Fehler aufgetreten:

<x-mail::table>
| Betroffen | Anzahl | Zuletzt | Meldung |
|:----------|-------:|:--------|:--------|
@foreach ($lines as $line)
| {{ $line->subjectLabel }} | {{ $line->count }} | {{ $line->latestAt->format('d.m.Y H:i') }} | {{ \Illuminate\Support\Str::limit($line->latestSummary, 120) }} |
@endforeach
</x-mail::table>

Du bekommst diese Nachricht, weil deine Adresse als Empfänger hinterlegt ist.

<x-mail::button :url="url('/admin/packages')">Zur Verwaltung</x-mail::button>
</x-mail::message>
