<?php
declare(strict_types=1);

/**
 * Uebernimmt eine bestaetigte Vorschau in die Datenbank.
 *
 * Laeuft vollstaendig in einer Transaktion: entweder der ganze Stapel geht
 * durch oder gar nichts. Jede einzelne Feldaenderung wird im change_log
 * protokolliert, damit nachvollziehbar bleibt, woher ein Wert stammt.
 */
final class Applier
{
    public function __construct(
        private readonly int $competitionSeasonId,
        private readonly int $sourceId,
        private readonly string $timezone,
    ) {
    }

    private ?int $manualSourceId = null;
    private ?bool $manualChannel = null;

    /**
     * @param array<int, array<string, mixed>> $diffRows Ergebnis von Differ::compare()['rows']
     * @param array<int, array<string, mixed>> $decisions Auswahl je Zeile, nach line_no
     * @return array{created:int, updated:int, skipped:int, batch_id:int}
     */
    public function apply(array $diffRows, TeamMatcher $matcher, array $decisions = [], ?string $filename = null): array
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();

        try {
            $batchId = Db::insert('import_batches', [
                'source_id'             => $this->sourceId,
                'competition_season_id' => $this->competitionSeasonId,
                'adapter'               => 'diff',
                'filename'              => $filename,
                'row_count'             => count($diffRows),
                'status'                => 'applied',
                'created_at'            => gmdate('Y-m-d H:i:s'),
                'applied_at'            => gmdate('Y-m-d H:i:s'),
            ]);

            $created = 0;
            $updated = 0;
            $skipped = 0;

            foreach ($diffRows as $row) {
                $decision = $decisions[$row['line_no']] ?? null;
                $outcome = $this->applyRow($row, $matcher, $decision, $batchId);

                match ($outcome) {
                    'create' => $created++,
                    'update' => $updated++,
                    default  => $skipped++,
                };
            }

            $pdo->commit();

            return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'batch_id' => $batchId];
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function applyRow(array $row, TeamMatcher $matcher, ?array $decision, int $batchId): string
    {
        $action = (string)$row['action'];

        // Der Adapter hat bei mehreren Terminangaben bereits gewaehlt. Nur
        // wenn der Admin ausdruecklich eine andere Angabe anhakt, wird sie
        // hier eingesetzt - und gilt dann als von ihm bestaetigt.
        if ($decision !== null && isset($decision['alternative'])) {
            $row = $this->chooseAlternative($row, $decision['alternative']);
            $action = $row['action'];
        }

        if ($action === 'skip' || $action === 'unchanged') {
            $this->logRow($batchId, $row, $action, $row['message'] ?? null);

            return 'skip';
        }

        $homeId = $matcher->resolve($row['home']);
        $awayId = $matcher->resolve($row['away']);
        if ($homeId === null || $awayId === null) {
            $this->logRow($batchId, $row, 'skip', 'Mannschaft nicht zugeordnet.');

            return 'skip';
        }

        // Geschuetzt wird nur, worueber ein Mensch tatsaechlich entschieden
        // hat. Bei einer Terminauswahl ist das der Anstoss - nicht Ergebnis
        // oder Status, die unstrittig aus der Quelle stammen.
        //
        // Kommt die Zeile dagegen aus einem Weg der Handpflege (CSV-Ruecklauf),
        // ist jede Abweichung eine bewusste Aenderung des Admins. Der Differ
        // meldet ohnehin nur tatsaechliche Unterschiede, unberuehrte Felder
        // sind also nicht betroffen.
        $confirmedFields = match (true) {
            $this->isManualChannel() => array_keys($row['changes']),
            $decision !== null && isset($decision['alternative']) && ($decision['confirm'] ?? false)
                => ['kickoff_utc', 'kickoff_is_confirmed'],
            default => [],
        };

        if ($action === 'create') {
            $matchId = $this->createMatch($row, $homeId, $awayId);
            $this->recordFields($matchId, array_keys($row['changes']), $confirmedFields);
            foreach ($row['changes'] as $field => $value) {
                $this->log($batchId, $matchId, $field, null, $this->scalar($value));
            }
            $this->logRow($batchId, $row, 'create', null, $matchId);

            return 'create';
        }

        $matchId = (int)$row['match_id'];
        $values = [];
        foreach ($row['changes'] as $field => $change) {
            $values[$field] = $change['to'];
            $this->log($batchId, $matchId, $field, $this->scalar($change['from']), $this->scalar($change['to']));
        }

        if ($values !== []) {
            $values['updated_at'] = gmdate('Y-m-d H:i:s');
            Db::update('matches', $matchId, $values);
            $this->recordFields($matchId, array_keys($row['changes']), $confirmedFields);
        }

        $this->logRow($batchId, $row, 'update', null, $matchId);

        return 'update';
    }

    /** Setzt die vom Admin angehakte Terminangabe anstelle der gewaehlten ein. */
    private function chooseAlternative(array $row, array $alternative): array
    {
        $kickoff = null;
        if (!empty($alternative['kickoff_date'])) {
            $local = new DateTimeImmutable(
                $alternative['kickoff_date'] . ' ' . ($alternative['kickoff_time'] ?: '00:00'),
                new DateTimeZone($this->timezone)
            );
            $kickoff = $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }

        $existing = $row['match_id'] === null
            ? null
            : Db::one('SELECT * FROM matches WHERE id = ?', [$row['match_id']]);

        // Die uebrigen Felder der Quelle bleiben erhalten; die Auswahl
        // kommt hinzu, ersetzt sie aber nicht.
        $changes = $row['changes'] ?? [];

        if ($kickoff !== null && (string)($existing['kickoff_utc'] ?? '') !== $kickoff) {
            $changes['kickoff_utc'] = $existing === null
                ? $kickoff
                : ['from' => $existing['kickoff_utc'] ?? null, 'to' => $kickoff];

            // Ein Mensch hat sich entschieden, also steht der Termin fest.
            $changes['kickoff_is_confirmed'] = $existing === null
                ? 1
                : ['from' => $existing['kickoff_is_confirmed'] ?? 0, 'to' => 1];
        }

        $row['changes'] = $changes;
        $row['action'] = $existing === null ? 'create' : ($changes === [] ? 'unchanged' : 'update');

        return $row;
    }

    private function createMatch(array $row, int $homeId, int $awayId): int
    {
        $values = [
            'competition_season_id' => $this->competitionSeasonId,
            'round_id'              => $this->roundId((int)($row['round'] ?? 0)),
            'home_team_id'          => $homeId,
            'away_team_id'          => $awayId,
            'kickoff_tz'            => $this->timezone,
            'status'                => 'scheduled',
            'kickoff_is_confirmed'  => 0,
            'created_at'            => gmdate('Y-m-d H:i:s'),
            'updated_at'            => gmdate('Y-m-d H:i:s'),
        ];

        foreach ($row['changes'] as $field => $value) {
            $values[$field] = $this->scalar($value);
        }

        return Db::insert('matches', $values);
    }

    private function roundId(int $number): int
    {
        $existing = Db::value(
            'SELECT id FROM rounds WHERE competition_season_id = ? AND number = ?',
            [$this->competitionSeasonId, $number]
        );

        if ($existing !== null) {
            return (int)$existing;
        }

        return Db::insert('rounds', [
            'competition_season_id' => $this->competitionSeasonId,
            'number'                => $number,
            'name'                  => $number === 0 ? 'Ohne Spieltag' : $number . '. Spieltag',
        ]);
    }

    /**
     * @param string[] $fields          alle geschriebenen Felder
     * @param string[] $confirmedFields die davon vom Menschen entschiedenen
     *
     * Eine bestaetigte Angabe geht auf das Konto der manuellen Pflege, nicht
     * der Importquelle: entschieden hat ein Mensch. Nur so schuetzt die
     * Entscheidung sich auch gegen den naechsten Lauf derselben Quelle.
     */
    private function recordFields(int $matchId, array $fields, array $confirmedFields): void
    {
        foreach ($fields as $field) {
            $confirmed = in_array($field, $confirmedFields, true);

            // Bei einer Terminauswahl steht 'manual' dahinter; kommt die
            // Aenderung aus dem CSV-Ruecklauf, bleibt csv als Herkunft stehen.
            FieldSource::set(
                $matchId,
                $field,
                ($confirmed && !$this->isManualChannel()) ? $this->manualSourceId() : $this->sourceId,
                $confirmed
            );
        }
    }

    /** Ist diese Quelle ein Weg der Handpflege statt eines fremden Imports? */
    private function isManualChannel(): bool
    {
        return $this->manualChannel ??= in_array(
            (string)Db::value('SELECT slug FROM sources WHERE id = ?', [$this->sourceId]),
            ['manual', 'csv'],
            true
        );
    }

    private function manualSourceId(): int
    {
        return $this->manualSourceId ??= (int)Db::value(
            'SELECT id FROM sources WHERE slug = ?',
            ['manual']
        );
    }

    private function log(int $batchId, int $matchId, string $field, mixed $from, mixed $to): void
    {
        // Das Protokoll haelt Werte als Text fest, damit Tore, Zeitstempel und
        // Status in derselben Spalte vergleichbar bleiben.
        $text = static fn(mixed $v): ?string => $v === null ? null : (string)$v;

        Db::insert('change_log', [
            'entity_type' => 'match',
            'entity_id'   => $matchId,
            'field'       => $field,
            'old_value'   => $text($from),
            'new_value'   => $text($to),
            'actor'       => 'import',
            'source_id'   => $this->sourceId,
            'batch_id'    => $batchId,
            'created_at'  => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function logRow(int $batchId, array $row, string $action, ?string $message, ?int $matchId = null): void
    {
        Db::insert('import_rows', [
            'batch_id'        => $batchId,
            'line_no'         => (int)($row['line_no'] ?? 0),
            'parsed_json'     => json_encode($row, JSON_UNESCAPED_UNICODE),
            'action'          => $action,
            'target_match_id' => $matchId ?? $row['match_id'] ?? null,
            'status'          => 'applied',
            'message'         => $message === null ? null : mb_substr($message, 0, 255),
        ]);
    }

    /** Holt den Zielwert aus einer Diff-Angabe ['from' => ..., 'to' => ...]. */
    private function scalar(mixed $value): mixed
    {
        if (is_array($value) && array_key_exists('to', $value)) {
            return $value['to'];
        }

        return is_array($value) ? null : $value;
    }
}
