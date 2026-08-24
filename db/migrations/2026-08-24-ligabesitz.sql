-- @erledigt-wenn: SELECT COUNT(*) FROM information_schema.TABLES
--                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'competition_members'

-- Besitz je Liga statt globaler Rollen.
--
-- Bisher: zwei globale Rollen (Verwaltung, Pflege), die ueberall galten.
-- Kuenftig: wer eine Liga anlegt, betreut sie und entscheidet, wer daran
-- mitarbeitet. Damit ist offene Anmeldung unbedenklich - ein neues Konto
-- kann an bestehenden Ligen nichts aendern.
--
-- Nur noetig fuer Installationen mit Benutzerkonten aus der Zwischenstufe.

CREATE TABLE competition_members (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  competition_id BIGINT UNSIGNED NOT NULL,
  user_id        BIGINT UNSIGNED NOT NULL,
  role           VARCHAR(16)  NOT NULL DEFAULT 'coadmin',
  granted_by     BIGINT UNSIGNED  NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_member (competition_id, user_id),
  KEY ix_member_user (user_id),
  CONSTRAINT fk_member_competition FOREIGN KEY (competition_id) REFERENCES competitions (id) ON DELETE CASCADE,
  CONSTRAINT fk_member_user        FOREIGN KEY (user_id)        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE signup_attempts (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip_hash    CHAR(64)     NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_signup (ip_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Die alte Rolle 'editor' heisst jetzt 'user'.
UPDATE users SET role = 'user' WHERE role = 'editor';

-- Bestehende Ligen haben noch keinen Besitzer. Der Webadmin kommt ohnehin
-- ueberall heran; wer eine Liga uebernehmen soll, wird hier eingetragen.
-- Die IDs zeigt:
--
--   SELECT id, username, role FROM users;
--   SELECT id, slug, name FROM competitions;
--
--   INSERT INTO competition_members (competition_id, user_id, role)
--   VALUES (LIGA_ID, BENUTZER_ID, 'owner');
--
-- Ohne Eintrag bleibt eine Liga besitzerlos: nur der Webadmin kann sie
-- pflegen. Das ist kein Fehler, sondern der Ausgangszustand.

SELECT c.id AS liga_id, c.slug, c.name,
       (SELECT COUNT(*) FROM competition_members m WHERE m.competition_id = c.id) AS mitglieder
  FROM competitions c ORDER BY c.name;
