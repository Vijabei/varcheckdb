<?php
declare(strict_types=1);

/**
 * Wer darf was, an welcher Liga.
 *
 * Zwei Ebenen:
 *
 *   Global      admin ist Webadmin und darf alles, ueberall.
 *               user  darf lesen, exportieren und Ligen anlegen.
 *
 *   Je Liga     owner   hat sie angelegt; pflegt, importiert, vergibt Rechte,
 *                       darf sie entfernen
 *               coadmin pflegt und importiert
 *
 * Wer eine Liga anlegt, wird ihr owner. Damit ist offene Anmeldung
 * unbedenklich: ein neues Konto kann niemandem etwas anhaben, weil
 * Schreibrechte immer an einer eigenen Liga haengen.
 *
 * Lesen und Exportieren brauchen keine Anmeldung - die oeffentliche
 * Schnittstelle liefert ohnehin alles.
 */
final class Access
{
    public const OWNER   = 'owner';
    public const COADMIN = 'coadmin';

    public const MEMBER_ROLES = [
        self::OWNER   => 'Besitzer',
        self::COADMIN => 'Co-Admin',
    ];

    public static function isWebadmin(?string $globalRole): bool
    {
        return $globalRole === Users::ROLE_ADMIN;
    }

    /** Jeder Angemeldete darf eine Liga anlegen und wird ihr Besitzer. */
    public static function mayCreateCompetition(?string $globalRole): bool
    {
        return $globalRole !== null;
    }

    /** Die Rolle an einer Liga, oder null. */
    public static function memberRole(?int $userId, int $competitionId): ?string
    {
        if ($userId === null) {
            return null;
        }

        $row = Db::one(
            'SELECT role FROM competition_members WHERE competition_id = ? AND user_id = ?',
            [$competitionId, $userId]
        );

        return $row === null ? null : (string)$row['role'];
    }

    /** Spiele aendern, importieren, CSV zurueckspielen. */
    public static function mayEdit(?int $userId, ?string $globalRole, int $competitionId): bool
    {
        if (self::isWebadmin($globalRole)) {
            return true;
        }

        return self::memberRole($userId, $competitionId) !== null;
    }

    /** Rechte vergeben, Liga entfernen, weitere Saisons anlegen. */
    public static function mayManage(?int $userId, ?string $globalRole, int $competitionId): bool
    {
        if (self::isWebadmin($globalRole)) {
            return true;
        }

        return self::memberRole($userId, $competitionId) === self::OWNER;
    }

    /** Dieselbe Pruefung, ausgehend von einer Saison. */
    public static function competitionOf(int $competitionSeasonId): ?int
    {
        $id = Db::value(
            'SELECT competition_id FROM competition_seasons WHERE id = ?',
            [$competitionSeasonId]
        );

        return $id === null ? null : (int)$id;
    }

    public static function mayEditSeason(?int $userId, ?string $globalRole, int $competitionSeasonId): bool
    {
        $competitionId = self::competitionOf($competitionSeasonId);

        return $competitionId !== null && self::mayEdit($userId, $globalRole, $competitionId);
    }

    public static function mayManageSeason(?int $userId, ?string $globalRole, int $competitionSeasonId): bool
    {
        $competitionId = self::competitionOf($competitionSeasonId);

        return $competitionId !== null && self::mayManage($userId, $globalRole, $competitionId);
    }

    /** Macht den Anlegenden zum Besitzer. */
    public static function makeOwner(int $competitionId, int $userId): void
    {
        self::grant($competitionId, $userId, self::OWNER, null);
    }

    public static function grant(int $competitionId, int $userId, string $role, ?int $grantedBy): void
    {
        if (!array_key_exists($role, self::MEMBER_ROLES)) {
            return;
        }

        $vorhanden = Db::value(
            'SELECT id FROM competition_members WHERE competition_id = ? AND user_id = ?',
            [$competitionId, $userId]
        );

        if ($vorhanden !== null) {
            Db::update('competition_members', (int)$vorhanden, ['role' => $role]);
        } else {
            Db::insert('competition_members', [
                'competition_id' => $competitionId,
                'user_id'        => $userId,
                'role'           => $role,
                'granted_by'     => $grantedBy,
                'created_at'     => gmdate('Y-m-d H:i:s'),
            ]);
        }

        self::log($competitionId, $userId, 'granted', null, $role, $grantedBy);
    }

    /**
     * Nimmt jemandem die Rechte an einer Liga.
     *
     * Der letzte Besitzer bleibt: eine Liga ohne Besitzer koennte nur noch
     * der Webadmin pflegen, und niemand koennte mehr Rechte vergeben.
     */
    public static function revoke(int $competitionId, int $userId, ?int $actorId = null): bool
    {
        $role = self::memberRole($userId, $competitionId);

        if ($role === null) {
            return false;
        }

        if ($role === self::OWNER && self::ownerCount($competitionId) <= 1) {
            return false;
        }

        Db::run(
            'DELETE FROM competition_members WHERE competition_id = ? AND user_id = ?',
            [$competitionId, $userId]
        );
        self::log($competitionId, $userId, 'revoked', $role, null, $actorId);

        return true;
    }

    public static function ownerCount(int $competitionId): int
    {
        return (int)Db::value(
            'SELECT COUNT(*) FROM competition_members WHERE competition_id = ? AND role = ?',
            [$competitionId, self::OWNER]
        );
    }

    /** Alle Mitglieder einer Liga, mit Benutzernamen. */
    public static function members(int $competitionId): array
    {
        return Db::all(
            'SELECT cm.*, u.username, u.active
               FROM competition_members cm
               JOIN users u ON u.id = cm.user_id
              WHERE cm.competition_id = ?
              ORDER BY cm.role, u.username',
            [$competitionId]
        );
    }

    /** Die Ligen, an denen jemand mitarbeitet. */
    public static function competitionsOf(int $userId): array
    {
        return Db::all(
            'SELECT c.id, c.name, c.slug, cm.role
               FROM competition_members cm
               JOIN competitions c ON c.id = cm.competition_id
              WHERE cm.user_id = ?
              ORDER BY c.name',
            [$userId]
        );
    }

    private static function log(
        int $competitionId,
        int $userId,
        string $field,
        ?string $from,
        ?string $to,
        ?int $actorId
    ): void {
        $actor = $actorId === null
            ? 'system'
            : (string)(Db::value('SELECT username FROM users WHERE id = ?', [$actorId]) ?? 'unbekannt');

        $wer = (string)(Db::value('SELECT username FROM users WHERE id = ?', [$userId]) ?? $userId);

        Db::insert('change_log', [
            'entity_type' => 'competition_member',
            'entity_id'   => $competitionId,
            'field'       => $field,
            'old_value'   => $from === null ? $wer : $wer . ': ' . $from,
            'new_value'   => $to === null ? null : $wer . ': ' . $to,
            'actor'       => $actor,
            'source_id'   => Db::value('SELECT id FROM sources WHERE slug = ?', ['manual']),
            'created_at'  => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
