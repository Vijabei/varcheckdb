<?php
declare(strict_types=1);

/**
 * Zeichensatz hochgeladener Dateien vereinheitlichen.
 *
 * Zwei haeufige Faelle aus der Praxis, die beide still falsche Daten
 * erzeugen wuerden:
 *
 *  - Im Browser gespeicherte Seiten sind unter Windows oft Windows-1252,
 *    auch wenn das meta-Tag utf-8 behauptet.
 *  - Excel schreibt CSV standardmaessig in Windows-1252.
 *
 * In beiden Faellen wuerde aus 'Vorwärts' ein 'Vorw?rts' - und damit ein
 * neuer, falscher Mannschaftsname samt Alias.
 */
final class Encoding
{
    public static function toUtf8(string $content): string
    {
        // Byte Order Mark entfernen, sonst steht sie im ersten Feldnamen.
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        if (mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }

        // Windows-1252 ist die passende Annahme: es umfasst ISO-8859-1 und
        // belegt zusaetzlich 0x80-0x9F, wo typografische Zeichen liegen.
        return mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }

    /** Nur zur Anzeige im Adminbereich: welcher Zeichensatz wurde erkannt? */
    public static function describe(string $content): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            return 'UTF-8 mit BOM';
        }

        return mb_check_encoding($content, 'UTF-8') ? 'UTF-8' : 'Windows-1252 (umgewandelt)';
    }
}
