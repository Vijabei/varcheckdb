<?php
declare(strict_types=1);

/**
 * Wettbewerbe und Saisons anlegen, umbenennen und entfernen.
 *
 * Das Loeschen ist der heikle Teil: an einem Wettbewerb haengen Spiele,
 * Spieltage, Herkunftsvermerke und Importvorgaenge. Die Fremdschluessel
 * verhindern ein versehentliches Loeschen, aber sie geben keine brauchbare
 * Auskunft darueber, was verlorenginge. Deshalb zaehlt dependents() vorher
 * ab, was betroffen ist, und remove() raeumt in der richtigen Reihenfolge
 * und in einer Transaktion ab.
 *
 * Was bewusst stehenbleibt:
 *
 *  - Mannschaften und Vereine. Sie gehoeren keinem Wettbewerb; dieselbe
 *    Mannschaft spielt naechste Saison wieder.
 *  - Das Aenderungsprotokoll. Es ist die Aufzeichnung dessen, was geschehen
 *    ist; sie zu loeschen hiesse, die Spur zu verwischen. Stattdessen wird
 *    das Entfernen selbst protokolliert.
 */
final class Competitions
{
    /**
     * Das Kuerzel ist der leagueShortcut der oeffentlichen API und soll kurz
     * bleiben - es steht in jeder Adresse.
     */
    public const SHORTCUT_PATTERN = '/^[a-z][a-z0-9-]{1,15}$/';

    /**
     * Die Kurzform steht ebenfalls in Adressen, darf aber sprechend sein:
     * 'frauen-regionalliga-west' ist 24 Zeichen lang und genau richtig so.
     */
    public const SLUG_PATTERN = '/^[a-z][a-z0-9-]{1,63}$/';

    /**
     * Was haengt an dieser Saison eines Wettbewerbs?
     *
     * @return array<string, int>
     */
    public static function dependents(int $competitionSeasonId): array
    {
        $count = static fn(string $sql): int => (int)Db::value($sql, [$competitionSeasonId]);

        return [
            'matches' => $count('SELECT COUNT(*) FROM matches WHERE competition_season_id = ?'),
            'rounds'  => $count('SELECT COUNT(*) FROM rounds WHERE competition_season_id = ?'),
            'field_sources' => $count(
                'SELECT COUNT(*) FROM match_field_sources WHERE match_id IN
                 (SELECT id FROM matches WHERE competition_season_id = ?)'
            ),
            'import_batches' => $count('SELECT COUNT(*) FROM import_batches WHERE competition_season_id = ?'),
            'confirmed' => $count(
                'SELECT COUNT(*) FROM match_field_sources WHERE confirmed = 1 AND match_id IN
                 (SELECT id FROM matches WHERE competition_season_id = ?)'
            ),
        ];
    }

    /** Prueft eine Eingabe fuer einen neuen Wettbewerb. @return string[] */
    public static function validate(array $input, ?int $ignoreSeasonId = null): array
    {
        $errors = [];

        $slug = trim((string)($input['slug'] ?? ''));
        $shortcut = trim((string)($input['shortcut'] ?? ''));
        $name = trim((string)($input['name'] ?? ''));
        $startYear = (int)($input['start_year'] ?? 0);

        if ($name === '') {
            $errors[] = 'Der Name des Wettbewerbs fehlt.';
        }

        if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            $errors[] = 'Die Kurzform darf nur Kleinbuchstaben, Ziffern und Bindestriche '
                . 'enthalten, muss mit einem Buchstaben beginnen und hoechstens 64 Zeichen '
                . 'lang sein.';
        }

        if (preg_match(self::SHORTCUT_PATTERN, $shortcut) !== 1) {
            $errors[] = 'Das Kuerzel darf nur Kleinbuchstaben, Ziffern und Bindestriche '
                . 'enthalten, muss mit einem Buchstaben beginnen und 2 bis 16 Zeichen lang sein.';
        }

        if ($startYear < 1900 || $startYear > 2100) {
            $errors[] = 'Das Startjahr der Saison ist unplausibel.';
        }

        // Das Kuerzel ist der leagueShortcut der oeffentlichen API. Zwei
        // Wettbewerbe mit demselben Kuerzel in derselben Saison waeren von
        // aussen nicht unterscheidbar.
        if ($errors === [] && $startYear > 0) {
            $vorhanden = Db::one(
                'SELECT cs.id FROM competition_seasons cs
                   JOIN seasons s ON s.id = cs.season_id
                  WHERE cs.shortcut = ? AND s.start_year = ?',
                [$shortcut, $startYear]
            );

            if ($vorhanden !== null && (int)$vorhanden['id'] !== $ignoreSeasonId) {
                $errors[] = sprintf(
                    'Das Kuerzel "%s" ist fuer die Saison %d schon vergeben.',
                    $shortcut,
                    $startYear
                );
            }
        }

        return $errors;
    }

    /**
     * Legt Wettbewerb und Saison an, soweit sie noch nicht bestehen.
     *
     * @return int die id des competition_seasons-Eintrags
     */
    public static function create(array $input, string $actor = 'admin'): int
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();

        try {
            $slug = trim((string)$input['slug']);
            $startYear = (int)$input['start_year'];

            $competitionId = Db::value('SELECT id FROM competitions WHERE slug = ?', [$slug]);

            if ($competitionId === null) {
                $competitionId = Db::insert('competitions', [
                    'slug'      => $slug,
                    'name'      => trim((string)$input['name']),
                    'gender'    => $input['gender'] ?? null,
                    'age_group' => $input['age_group'] ?? null,
                    'created_at' => gmdate('Y-m-d H:i:s'),
                ]);
            }

            $seasonId = Db::value('SELECT id FROM seasons WHERE start_year = ?', [$startYear]);

            if ($seasonId === null) {
                $seasonId = Db::insert('seasons', [
                    'name'       => self::seasonName($startYear),
                    'start_year' => $startYear,
                ]);
            }

            $competitionSeasonId = Db::insert('competition_seasons', [
                'competition_id' => (int)$competitionId,
                'season_id'      => (int)$seasonId,
                'shortcut'       => trim((string)$input['shortcut']),
                'name'           => sprintf(
                    '%s %d/%d',
                    trim((string)$input['name']),
                    $startYear,
                    $startYear + 1
                ),
                'team_count' => ($input['team_count'] ?? '') === '' ? null : (int)$input['team_count'],
                'source_url' => null,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);

            self::log('competition_season', $competitionSeasonId, 'created', null, trim((string)$input['shortcut']), $actor);

            $pdo->commit();

            return $competitionSeasonId;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Entfernt eine Saison eines Wettbewerbs samt allem, was daran haengt.
     *
     * @return array<string, int> was tatsaechlich entfernt wurde
     */
    public static function remove(int $competitionSeasonId, string $actor = 'admin'): array
    {
        $entry = Db::one(
            'SELECT cs.*, c.id AS competition_id, c.slug, s.id AS season_id
               FROM competition_seasons cs
               JOIN competitions c ON c.id = cs.competition_id
               JOIN seasons s      ON s.id = cs.season_id
              WHERE cs.id = ?',
            [$competitionSeasonId]
        );

        if ($entry === null) {
            return [];
        }

        $entfernt = self::dependents($competitionSeasonId);

        $pdo = Db::pdo();
        $pdo->beginTransaction();

        try {
            // Reihenfolge folgt den Fremdschluesseln von innen nach aussen.
            // match_field_sources und import_rows haengen mit ON DELETE
            // CASCADE an matches beziehungsweise import_batches.
            Db::run('DELETE FROM matches WHERE competition_season_id = ?', [$competitionSeasonId]);
            Db::run('DELETE FROM rounds WHERE competition_season_id = ?', [$competitionSeasonId]);
            Db::run('DELETE FROM import_batches WHERE competition_season_id = ?', [$competitionSeasonId]);
            Db::run(
                'DELETE FROM source_mappings WHERE entity_type = ? AND internal_id = ?',
                ['competition_season', $competitionSeasonId]
            );
            Db::run('DELETE FROM competition_seasons WHERE id = ?', [$competitionSeasonId]);

            // Wettbewerb und Saison nur entfernen, wenn nichts mehr daran haengt.
            $entfernt['competition'] = 0;
            if ((int)Db::value(
                'SELECT COUNT(*) FROM competition_seasons WHERE competition_id = ?',
                [$entry['competition_id']]
            ) === 0) {
                Db::run('DELETE FROM competitions WHERE id = ?', [$entry['competition_id']]);
                $entfernt['competition'] = 1;
            }

            $entfernt['season'] = 0;
            if ((int)Db::value(
                'SELECT COUNT(*) FROM competition_seasons WHERE season_id = ?',
                [$entry['season_id']]
            ) === 0) {
                Db::run('DELETE FROM seasons WHERE id = ?', [$entry['season_id']]);
                $entfernt['season'] = 1;
            }

            // Das Protokoll bleibt stehen; das Entfernen selbst kommt hinein.
            self::log(
                'competition_season',
                $competitionSeasonId,
                'removed',
                sprintf('%s (%d Spiele)', $entry['shortcut'], $entfernt['matches']),
                null,
                $actor
            );

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $entfernt;
    }

    /** Mannschaften, die nach dem Entfernen in keinem Spiel mehr vorkommen. */
    public static function orphanedTeams(): array
    {
        return Db::all(
            'SELECT t.id, t.name FROM teams t
              WHERE NOT EXISTS (
                    SELECT 1 FROM matches m
                     WHERE m.home_team_id = t.id OR m.away_team_id = t.id)
              ORDER BY t.name'
        );
    }

    /** Entfernt eine Mannschaft samt ihrer Namensvarianten. */
    public static function removeTeam(int $teamId, string $actor = 'admin'): bool
    {
        $inUse = (int)Db::value(
            'SELECT COUNT(*) FROM matches WHERE home_team_id = ? OR away_team_id = ?',
            [$teamId, $teamId]
        );

        if ($inUse > 0) {
            return false;
        }

        $name = (string)Db::value('SELECT name FROM teams WHERE id = ?', [$teamId]);

        // team_aliases haengt mit ON DELETE CASCADE an teams.
        Db::run('DELETE FROM teams WHERE id = ?', [$teamId]);
        self::log('team', $teamId, 'removed', $name, null, $actor);

        return true;
    }

    /** '2026/27' aus 2026. */
    public static function seasonName(int $startYear): string
    {
        return sprintf('%d/%02d', $startYear, ($startYear + 1) % 100);
    }

    private static function log(
        string $entityType,
        int $entityId,
        string $field,
        ?string $from,
        ?string $to,
        string $actor = 'admin'
    ): void {
        Db::insert('change_log', [
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'field'       => $field,
            'old_value'   => $from,
            'new_value'   => $to,
            'actor'       => $actor,
            'source_id'   => Db::value('SELECT id FROM sources WHERE slug = ?', ['manual']),
            'created_at'  => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
