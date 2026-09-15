<?php

namespace App\Exceptions;

use Exception;

/**
 * Anything that stops a scan from producing a verdict.
 *
 * Always caught by ScanRunner and turned into a recorded failure — never rendered to an
 * HTTP client, because no client is waiting for a scan. The message is therefore operator
 * text: it lands in `oci_scan_reports.error`, on the health page and in the log, and it has
 * to name the thing to go and look at.
 */
final class ScannerException extends Exception
{
    public static function unreachable(string $url, string $reason): self
    {
        return new self("Der Scanner unter {$url} ist nicht erreichbar: {$reason}");
    }

    public static function addressRefused(string $url): self
    {
        return new self("Die Scanner-Adresse {$url} ist nicht zugelassen. Tragen Sie den Host in "
            .'KONTORFIX_SCANNER_ALLOWED_HOSTS ein — die Adressprüfung wird dafür nicht gelockert.');
    }

    public static function rejected(string $endpoint, int $status): self
    {
        return new self("Der Scanner hat {$endpoint} mit HTTP {$status} beantwortet.");
    }

    public static function unreadableReport(string $mediaType): self
    {
        return new self("Der Scanner hat den Bericht als {$mediaType} geliefert. Gelesen wird nur "
            .'application/vnd.security.vulnerability.report; version=1.1.');
    }

    public static function notConfigured(): self
    {
        return new self('Es ist kein Scanner konfiguriert (KONTORFIX_SCANNER_URL).');
    }

    public static function unreadableBody(string $endpoint): self
    {
        return new self("Die Antwort des Scanners auf {$endpoint} war kein lesbares JSON-Objekt.");
    }
}
