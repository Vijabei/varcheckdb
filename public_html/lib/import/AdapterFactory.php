<?php
declare(strict_types=1);

/**
 * Waehlt den passenden Adapter zur hochgeladenen Datei.
 *
 * Erkannt wird am Inhalt, nicht an der Dateiendung: eine aus dem Browser
 * gespeicherte Seite heisst mal .html, mal .htm, mal .txt.
 */
final class AdapterFactory
{
    /** @return array{adapter: ?Adapter, name: string, reason: string} */
    public static function detect(string $content, string $filename = ''): array
    {
        $head = substr(ltrim($content), 0, 4096);

        if (str_contains($head, 'varcheckdb-import/1') || str_contains($head, 'vijabei-import/1')) {
            return [
                'adapter' => new KickerJsonAdapter(),
                'name'    => 'JSON-Import',
                'reason'  => 'Die Datei traegt die Kennung "varcheckdb-import/1".',
            ];
        }

        if (str_contains($content, 'data-match_id')) {
            return [
                'adapter' => new WorldfootballHtmlAdapter(),
                'name'    => 'HTML-Spielplan',
                'reason'  => 'Die Datei enthaelt Spiel-Container mit data-match_id.',
            ];
        }

        if (self::looksLikeCsv($head)) {
            return [
                'adapter' => new CsvAdapter(),
                'name'    => 'CSV',
                'reason'  => 'Die Kopfzeile enthaelt Trennzeichen und die erwarteten Spalten.',
            ];
        }

        return [
            'adapter' => null,
            'name'    => 'unbekannt',
            'reason'  => sprintf(
                'Der Inhalt von "%s" passt zu keinem bekannten Format. Erwartet wird JSON '
                . 'mit der Kennung "varcheckdb-import/1", eine CSV-Datei mit den Spalten '
                . 'des Exports oder ein gespeicherter HTML-Spielplan.',
                $filename !== '' ? $filename : 'der Datei'
            ),
        ];
    }

    private static function looksLikeCsv(string $head): bool
    {
        $first = strtok(ltrim($head, "\xEF\xBB\xBF"), "\r\n");
        if ($first === false) {
            return false;
        }

        // Nicht jede Datei mit Kommas ist eine CSV. Verlangt wird die
        // Kopfzeile des eigenen Formats.
        $lower = strtolower($first);

        return str_contains($lower, 'home') && str_contains($lower, 'away');
    }
}
