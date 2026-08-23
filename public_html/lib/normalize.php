<?php
declare(strict_types=1);

/**
 * Namensnormalisierung fuer den Abgleich von Mannschaften zwischen Quellen.
 *
 * Zwei Stufen mit Absicht:
 *
 *  - strict() bildet den Schluessel, unter dem ein Name eindeutig einem Team
 *    zugeordnet ist (Spalte teams.name_normalized bzw. team_aliases.alias_normalized).
 *    Hier wird nur vereinheitlicht, nicht weggelassen.
 *
 *  - loose() darf zusaetzlich Vereinskuerzel und Gruendungsjahre entfernen.
 *    Das Ergebnis wird ausschliesslich zum Ranken von Vorschlaegen benutzt,
 *    niemals fuer eine automatische Zuordnung.
 *
 * Der Grund fuer die Trennung: 'SGS Essen II' und 'SGS Essen' sind
 * verschiedene Mannschaften desselben Vereins. Eine Normalisierung, die
 * beide zusammenfuehrt, verdirbt die Daten unrettbar. Ein Vorschlag, den
 * ein Mensch bestaetigt, ist dagegen harmlos.
 */
final class Normalize
{
    private const UMLAUTS = [
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ç' => 'c', 'ñ' => 'n',
    ];

    /** Vereinskuerzel ohne eigene Aussagekraft, nur am Namensanfang. */
    private const CLUB_PREFIXES = [
        'fc', 'sc', 'sv', 'tsv', 'tus', 'tsg', 'vfb', 'vfl', 'vfr', 'ssv', 'msv',
        'djk', 'fsv', 'sg', 'sgs', 'dsc', 'bv', 'bvb', 'fsc', 'rw', 'sus',
        'spvgg', 'ffc', 'tb', 'tv', 'psv', 'ksv', 'asv', 'esv', 'post',
    ];

    private const NOISE = ['ev', 'frauen', 'damen', 'women'];

    /** Vereinheitlicht, ohne Bestandteile zu verwerfen. */
    public static function strict(?string $name): string
    {
        $value = mb_strtolower(trim((string)$name), 'UTF-8');
        $value = strtr($value, self::UMLAUTS);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';

        return trim($value);
    }

    /** Wie strict(), entfernt zusaetzlich Kuerzel und Jahreszahlen. */
    public static function loose(?string $name): string
    {
        $tokens = array_values(array_filter(explode(' ', self::strict($name)), 'strlen'));

        // Mannschaftskennung am Ende sichern: sie trennt Reserve von Erster.
        $suffix = '';
        while ($tokens !== []) {
            $last = end($tokens);
            if ($last === 'ii' || $last === '2') {
                $suffix = 'ii';
            } elseif ($last === 'iii' || $last === '3') {
                $suffix = 'iii';
            } elseif (preg_match('/^u\d{2}$/', $last) === 1) {
                $suffix = $last;
            } else {
                break;
            }
            array_pop($tokens);
        }

        // Gruendungsjahre am Ende: 'SSV Rhade 1925' == 'SSV Rhade'
        while ($tokens !== [] && preg_match('/^(\d{2}|\d{4})$/', (string)end($tokens)) === 1) {
            array_pop($tokens);
        }

        // Kuerzel am Anfang, auch hinter einer fuehrenden Ordnungszahl ('1. FFC ...')
        $stripLeading = static function (array $tokens): array {
            while ($tokens !== [] && in_array($tokens[0], self::CLUB_PREFIXES, true)) {
                array_shift($tokens);
            }

            return $tokens;
        };

        $tokens = $stripLeading($tokens);
        while ($tokens !== [] && preg_match('/^\d{1,2}$/', $tokens[0]) === 1) {
            array_shift($tokens);
            $tokens = $stripLeading($tokens);
        }

        $tokens = array_values(array_filter(
            $tokens,
            static fn(string $t): bool => !in_array($t, self::NOISE, true)
        ));

        if ($suffix !== '') {
            $tokens[] = $suffix;
        }

        // Alles weggefallen? Dann ist die strenge Fassung die bessere Auskunft.
        return $tokens === [] ? self::strict($name) : implode(' ', $tokens);
    }

    /**
     * Aehnlichkeit zweier Namen zwischen 0 und 1, nur zum Ranken von Vorschlaegen.
     *
     * Eine abweichende Mannschaftskennung (II, III, U17) setzt den Wert hart
     * herab: das sind verschiedene Mannschaften, auch wenn der Rest gleich ist.
     */
    public static function similarity(string $a, string $b): float
    {
        $looseA = self::loose($a);
        $looseB = self::loose($b);

        if ($looseA === '' || $looseB === '') {
            return 0.0;
        }

        if (self::teamSuffix($looseA) !== self::teamSuffix($looseB)) {
            return 0.0;
        }

        if ($looseA === $looseB) {
            return 1.0;
        }

        similar_text($looseA, $looseB, $percent);

        return round($percent / 100, 4);
    }

    private static function teamSuffix(string $looseName): string
    {
        $tokens = explode(' ', $looseName);
        $last = (string)end($tokens);

        if ($last === 'ii' || $last === 'iii' || preg_match('/^u\d{2}$/', $last) === 1) {
            return $last;
        }

        return '';
    }
}
