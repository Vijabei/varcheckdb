<?php
declare(strict_types=1);

/**
 * Gemeinsame Form aller Importquellen.
 *
 * Jeder Adapter liefert dieselbe normalisierte Zeilenstruktur, damit
 * TeamMatcher, Differ und Applier quellenunabhaengig bleiben. Ein neuer
 * Adapter muss nur parse() implementieren.
 */
interface Adapter
{
    /** Kurzname der Quelle, muss einem sources.slug entsprechen. */
    public function sourceSlug(): string;

    /**
     * Wandelt den hochgeladenen Dateiinhalt in normalisierte Zeilen.
     *
     * Rueckgabe:
     *   [
     *     'meta'    => ['competition_name' => ?string, 'season' => ?string,
     *                   'season_start_year' => ?int, 'timezone' => string],
     *     'rows'    => ImportRow[],
     *     'notices' => string[],
     *   ]
     *
     * @throws ImportException bei unlesbarer oder unpassender Datei
     */
    public function parse(string $content): array;
}

final class ImportException extends RuntimeException
{
}

/**
 * Eine Spielzeile, wie sie aus jeder Quelle herauskommt.
 *
 * Felder, die die Quelle nicht kennt, bleiben null und werden vom Differ
 * uebersprungen - null bedeutet 'keine Aussage', nicht 'leer setzen'.
 */
final class ImportRow
{
    public function __construct(
        public readonly ?int $round,
        public readonly ?string $home,
        public readonly ?string $away,
        public readonly ?string $kickoffDate = null,   // 'YYYY-MM-DD'
        public readonly ?string $kickoffTime = null,   // 'HH:MM'
        public readonly ?bool $kickoffConfirmed = null,
        public readonly ?int $homeGoals = null,
        public readonly ?int $awayGoals = null,
        public readonly ?int $homeGoalsHt = null,
        public readonly ?int $awayGoalsHt = null,
        public readonly ?string $status = null,
        public readonly ?string $venue = null,
        public readonly ?string $note = null,
        public readonly ?string $sourceMatchId = null,
        public readonly ?string $homeSourceId = null,
        public readonly ?string $awaySourceId = null,
        public readonly ?int $matchId = null,          // gesetzt beim CSV-Ruecklauf
        /** @var array<int, array<string, mixed>> Konkurrierende Angaben der Quelle */
        public readonly array $alternatives = [],
        public readonly int $lineNo = 0,
    ) {
    }

    public function hasConflict(): bool
    {
        return $this->alternatives !== [];
    }

    /** Anstoss als UTC-Zeitstempel, oder null wenn kein Datum vorliegt. */
    public function kickoffUtc(string $timezone): ?string
    {
        if ($this->kickoffDate === null || $this->kickoffDate === '') {
            return null;
        }

        $local = new DateTimeImmutable(
            $this->kickoffDate . ' ' . ($this->kickoffTime ?: '00:00'),
            new DateTimeZone($timezone)
        );

        return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** Stellt eine Zeile wieder her, die als JSON zwischengespeichert war. */
    public static function fromArray(array $data): self
    {
        $int = static fn(string $key): ?int =>
            ($data[$key] ?? null) === null || $data[$key] === '' ? null : (int)$data[$key];

        return new self(
            round: $int('round'),
            home: $data['home'] ?? null,
            away: $data['away'] ?? null,
            kickoffDate: $data['kickoff_date'] ?? null,
            kickoffTime: $data['kickoff_time'] ?? null,
            kickoffConfirmed: isset($data['kickoff_confirmed']) && $data['kickoff_confirmed'] !== null
                ? (bool)$data['kickoff_confirmed']
                : null,
            homeGoals: $int('home_goals'),
            awayGoals: $int('away_goals'),
            homeGoalsHt: $int('home_goals_ht'),
            awayGoalsHt: $int('away_goals_ht'),
            status: $data['status'] ?? null,
            venue: $data['venue'] ?? null,
            note: $data['note'] ?? null,
            sourceMatchId: isset($data['source_match_id']) ? (string)$data['source_match_id'] : null,
            matchId: $int('match_id'),
            alternatives: $data['alternatives'] ?? [],
            lineNo: (int)($data['line_no'] ?? 0),
        );
    }

    public function toArray(): array
    {
        return [
            'round'             => $this->round,
            'home'              => $this->home,
            'away'              => $this->away,
            'kickoff_date'      => $this->kickoffDate,
            'kickoff_time'      => $this->kickoffTime,
            'kickoff_confirmed' => $this->kickoffConfirmed,
            'home_goals'        => $this->homeGoals,
            'away_goals'        => $this->awayGoals,
            'home_goals_ht'     => $this->homeGoalsHt,
            'away_goals_ht'     => $this->awayGoalsHt,
            'status'            => $this->status,
            'venue'             => $this->venue,
            'note'              => $this->note,
            'source_match_id'   => $this->sourceMatchId,
            'match_id'          => $this->matchId,
            'alternatives'      => $this->alternatives,
            'line_no'           => $this->lineNo,
        ];
    }
}
