-- @erledigt-wenn: SELECT COUNT(*) FROM information_schema.COLUMNS
--                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
--                    AND COLUMN_NAME = 'email'

-- Mailadresse und Passwort-Ruecksetzung.
--
-- Ohne Mailadresse kann ein vergessenes Passwort nur der Webadmin
-- zuruecksetzen - damit wird er zur Anlaufstelle fuer den haeufigsten
-- Supportfall. Mit Adresse geht es selbst.
--
-- Nur noetig fuer Installationen mit Benutzerkonten aus einer frueheren Stufe.

ALTER TABLE users
  ADD COLUMN email             VARCHAR(191) NULL AFTER username_normalized,
  ADD COLUMN email_normalized  VARCHAR(191) NULL AFTER email,
  ADD COLUMN email_verified_at DATETIME     NULL AFTER email_normalized,
  ADD UNIQUE KEY uq_users_email (email_normalized);

CREATE TABLE user_tokens (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    BIGINT UNSIGNED NOT NULL,
  kind       VARCHAR(16)  NOT NULL,
  token_hash CHAR(64)     NOT NULL,
  expires_at DATETIME     NOT NULL,
  used_at    DATETIME         NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_token (token_hash),
  KEY ix_token_user (user_id, kind),
  CONSTRAINT fk_token_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bestehende Konten haben noch keine Adresse. Sie koennen sich weiter
-- anmelden und arbeiten; nur der Selbst-Reset steht still, bis eine Adresse
-- eingetragen und bestaetigt ist.
--
-- Fuer den eigenen Zugang lohnt es, das gleich zu tun - sonst kommt
-- ausgerechnet der Webadmin bei einem vergessenen Passwort nicht mehr herein:
--
--   UPDATE users
--      SET email = 'du@example.org',
--          email_normalized = 'du@example.org',
--          email_verified_at = UTC_TIMESTAMP()
--    WHERE username_normalized = 'dein_benutzername';

SELECT id, username, role, COALESCE(email, '—') AS mailadresse FROM users ORDER BY id;
