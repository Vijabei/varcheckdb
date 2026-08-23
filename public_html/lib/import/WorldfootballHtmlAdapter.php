<?php
declare(strict_types=1);

/**
 * Liest eine im Browser gespeicherte all-matches-Seite von worldfootball.net.
 *
 * Der Abruf erfolgt bewusst nicht durch den Server: worldfootball.net steht
 * hinter einer Cloudflare-Pruefung, die jeden nicht-browserartigen Zugriff
 * mit 403 beantwortet. Der Admin oeffnet die Seite selbst und laedt sie hoch.
 *
 * Die Seite liefert die Spieldaten als Attribute am Spiel-Container, nicht
 * als Tabellenspalten. Das ist deutlich robuster als Spaltenpositionen -
 * insbesondere ist data-datetime bereits UTC.
 *
 * Nicht enthalten sind Halbzeitstaende und der Terminstatus. Beide bleiben
 * null, damit ein Abgleich gegen worldfootball die aus kicker.de stammenden
 * Angaben nicht ueberschreibt.
 */
final class WorldfootballHtmlAdapter implements Adapter
{
    public function __construct(private readonly string $timezone = 'Europe/Berlin')
    {
    }

    public function sourceSlug(): string
    {
        return 'worldfootball';
    }

    public function parse(string $content): array
    {
        // Vor allem anderen: der Zeichensatz muss stimmen, sonst werden
        // aus Umlauten stillschweigend neue Mannschaftsnamen.
        $content = Encoding::toUtf8($content);

        if (!str_contains($content, 'data-match_id')) {
            throw new ImportException(
                'In der Datei sind keine Spiele zu finden. Erwartet wird die gespeicherte '
                . 'Seite "all-matches" von worldfootball.net.'
            );
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $content);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);

        $competitionId = $this->attribute($xpath, '//div[@data-competition_id]', 'data-competition_id');

        // Spieltag und Spiele stehen gleichrangig nebeneinander; die Zuordnung
        // ergibt sich allein aus der Reihenfolge im Dokument.
        $nodes = $xpath->query(
            '//div[@data-match_id] | //div[contains(@class, "round-head")]'
        );

        $rows = [];
        $round = null;
        $lineNo = 0;

        foreach ($nodes ?? [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            if (str_contains((string)$node->getAttribute('class'), 'round-head')) {
                $round = $this->roundNumber($node->textContent);
                continue;
            }

            $row = $this->readMatch($xpath, $node, $round, ++$lineNo);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        if ($rows === []) {
            throw new ImportException('Die Datei enthaelt keine auswertbaren Spiele.');
        }

        $notices = [];
        $withoutRound = count(array_filter($rows, static fn(ImportRow $r): bool => $r->round === null));
        if ($withoutRound > 0) {
            $notices[] = sprintf(
                '%d Spiele konnten keinem Spieltag zugeordnet werden und landen unter "Ohne Spieltag".',
                $withoutRound
            );
        }
        $notices[] = 'worldfootball.net liefert keine Halbzeitstaende und keinen Terminstatus. '
            . 'Diese Felder bleiben unangetastet.';

        return [
            'meta' => [
                'competition_name'  => $this->competitionName($xpath),
                'season'            => null,
                'season_start_year' => null,
                'timezone'          => $this->timezone,
                'source_competition_id' => $competitionId,
            ],
            'rows'    => $rows,
            'notices' => $notices,
        ];
    }

    private function readMatch(DOMXPath $xpath, DOMElement $node, ?int $round, int $lineNo): ?ImportRow
    {
        $home = $this->text($xpath, './/div[contains(@class, "team-name-home")]', $node);
        $away = $this->text($xpath, './/div[contains(@class, "team-name-away")]', $node);

        if ($home === null || $away === null) {
            return null;
        }

        // data-datetime ist UTC. Wir rechnen auf Ortszeit zurueck, damit alle
        // Adapter dieselbe Form liefern; ImportRow rechnet wieder nach UTC.
        $date = null;
        $time = null;
        $raw = trim($node->getAttribute('data-datetime'));
        if ($raw !== '') {
            try {
                $local = (new DateTimeImmutable($raw))->setTimezone(new DateTimeZone($this->timezone));
                $date = $local->format('Y-m-d');
                $time = $local->format('H:i');
            } catch (Exception) {
                // Unlesbares Datum bedeutet 'keine Aussage', nicht 'kein Termin'.
            }
        }

        [$homeGoals, $awayGoals] = $this->result($xpath, $node);

        return new ImportRow(
            round: $round,
            home: $home,
            away: $away,
            kickoffDate: $date,
            kickoffTime: $time,
            homeGoals: $homeGoals,
            awayGoals: $awayGoals,
            status: $this->status($node),
            sourceMatchId: $node->getAttribute('data-match_id') ?: null,
            homeSourceId: $this->teamId($xpath, './/div[contains(@class, "team-name-home")]//a', $node),
            awaySourceId: $this->teamId($xpath, './/div[contains(@class, "team-name-away")]//a', $node),
            lineNo: $lineNo,
        );
    }

    /** @return array{0: ?int, 1: ?int} */
    private function result(DOMXPath $xpath, DOMElement $node): array
    {
        $text = $this->text($xpath, './/div[contains(@class, "match-result")]', $node);

        if ($text === null || preg_match('/^(\d+)\s*:\s*(\d+)$/', $text, $m) !== 1) {
            return [null, null];
        }

        return [(int)$m[1], (int)$m[2]];
    }

    private function status(DOMElement $node): ?string
    {
        $classes = ' ' . $node->getAttribute('class') . ' ';

        return match (true) {
            str_contains($classes, ' finished ')  => 'finished',
            str_contains($classes, ' live ')      => 'live',
            str_contains($classes, ' postponed ') => 'postponed',
            str_contains($classes, ' cancelled ') => 'cancelled',
            str_contains($classes, ' upcoming ')  => 'scheduled',
            default                               => null,
        };
    }

    private function roundNumber(string $text): ?int
    {
        return preg_match('/(\d+)/', $text, $m) === 1 ? (int)$m[1] : null;
    }

    private function teamId(DOMXPath $xpath, string $query, DOMElement $context): ?string
    {
        $node = $xpath->query($query, $context)?->item(0);
        if (!$node instanceof DOMElement) {
            return null;
        }

        return preg_match('#/teams/te(\d+)/#', $node->getAttribute('href'), $m) === 1 ? $m[1] : null;
    }

    private function competitionName(DOMXPath $xpath): ?string
    {
        $title = $this->text($xpath, '//title');
        if ($title === null) {
            return null;
        }

        return trim(explode(':', explode('|', $title)[0])[0]) ?: null;
    }

    private function text(DOMXPath $xpath, string $query, ?DOMElement $context = null): ?string
    {
        $node = $context === null ? $xpath->query($query)?->item(0) : $xpath->query($query, $context)?->item(0);
        if ($node === null) {
            return null;
        }

        $text = trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');

        return $text === '' ? null : $text;
    }

    private function attribute(DOMXPath $xpath, string $query, string $name): ?string
    {
        $node = $xpath->query($query)?->item(0);

        return $node instanceof DOMElement ? ($node->getAttribute($name) ?: null) : null;
    }
}
