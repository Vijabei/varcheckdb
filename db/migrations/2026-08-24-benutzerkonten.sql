-- Benutzerkonten statt eines gemeinsamen Passworts.
--
-- Bis hierher gab es ein einziges Passwort in config.php und keine Benutzer.
-- Das traegt, solange eine Person pflegt; sobald zwei Leute pflegen, fehlt die
-- Antwort auf "wer war das?".
--
-- Nur noetig fuer Installationen, die vor dem 24.08.2026 eingerichtet wurden.
-- Neue Installationen legen den ersten Benutzer im Installer an.

CREATE TABLE users (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username            VARCHAR(64)  NOT NULL,
  username_normalized VARCHAR(64)  NOT NULL,
  password_hash       VARCHAR(255) NOT NULL,
  role                VARCHAR(16)  NOT NULL DEFAULT 'editor',
  active              TINYINT(1)   NOT NULL DEFAULT 1,
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at       DATETIME         NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username_normalized)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kein Benutzer wird hier angelegt. Solange die Tabelle leer ist, gilt
-- weiterhin das Passwort aus config.php - damit kommst du herein und legst
-- den ersten Benutzer im Adminbereich an. Sobald ein aktiver Benutzer mit
-- der Rolle admin besteht, verliert das Passwort aus config.php seine
-- Gueltigkeit. Es ist also ein Weg fuer den Anfang, keine Hintertuer.
--
-- Ausgesperrt? Dann hier ein neues Passwort setzen. Den Hash erzeugt:
--
--   php -r 'echo password_hash("neues-passwort", PASSWORD_DEFAULT), "\n";'
--
--   UPDATE users SET password_hash = 'HIER_DER_HASH', active = 1
--    WHERE username_normalized = 'DEIN_BENUTZERNAME';
