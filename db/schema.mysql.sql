-- vijabei.net Spieldatenbank - Schema fuer MariaDB/MySQL
-- Einspielen ueber phpMyAdmin oder:
--   mysql -u BENUTZER -p DATENBANK < db/schema.mysql.sql

SET NAMES utf8mb4;
-- ---------------------------------------------------------------- Stammdaten

CREATE TABLE competitions (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug        VARCHAR(64)  NOT NULL,
  name        VARCHAR(191) NOT NULL,
  gender      VARCHAR(16)      NULL,
  age_group   VARCHAR(32)      NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_competitions_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE seasons (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(16) NOT NULL,               -- '2026/27'
  start_year  INT         NOT NULL,               -- 2026 = OpenLigaDB leagueSeason
  PRIMARY KEY (id),
  UNIQUE KEY uq_seasons_start_year (start_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE competition_seasons (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  competition_id  BIGINT UNSIGNED NOT NULL,
  season_id       BIGINT UNSIGNED NOT NULL,
  shortcut        VARCHAR(32)  NOT NULL,          -- OpenLigaDB leagueShortcut
  name            VARCHAR(191) NOT NULL,          -- OpenLigaDB leagueName
  team_count      INT              NULL,
  source_url      VARCHAR(512)     NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cs_shortcut_season (shortcut, season_id),
  UNIQUE KEY uq_cs_competition_season (competition_id, season_id),
  CONSTRAINT fk_cs_competition FOREIGN KEY (competition_id) REFERENCES competitions (id),
  CONSTRAINT fk_cs_season      FOREIGN KEY (season_id)      REFERENCES seasons (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE clubs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(191) NOT NULL,
  short_name  VARCHAR(64)      NULL,
  logo_url    VARCHAR(512)     NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ein Mannschaftsname kommt genau einmal vor.
--
-- 'Arminia Bielefeld' ist ein Eintrag und wird sowohl im Frauen- als auch im
-- Maennerwettbewerb verwendet; welcher Wettbewerb gemeint ist, sagt das Spiel.
-- Geschlecht und Altersklasse stehen deshalb nicht an der Mannschaft: sie
-- waeren bei einer geteilten Mannschaft schlicht falsch. Wo eine Unterscheidung
-- noetig ist, steht sie im Namen - 'Arminia Bielefeld U19', 'SGS Essen II'.
CREATE TABLE teams (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  club_id         BIGINT UNSIGNED  NULL,
  name            VARCHAR(191) NOT NULL,
  name_normalized VARCHAR(191) NOT NULL,
  short_name      VARCHAR(64)      NULL,
  logo_url        VARCHAR(512)     NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_teams_normalized (name_normalized),
  CONSTRAINT fk_teams_club FOREIGN KEY (club_id) REFERENCES clubs (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ein Namensstring zeigt auf genau ein Team. Damit findet der TeamMatcher
-- 'Arminia Bielefeld', 'DSC Arminia Bielefeld' und 'DSC Arminia' zusammen.
CREATE TABLE team_aliases (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  team_id          BIGINT UNSIGNED NOT NULL,
  alias            VARCHAR(191) NOT NULL,
  alias_normalized VARCHAR(191) NOT NULL,
  source_id        BIGINT UNSIGNED  NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_team_aliases_normalized (alias_normalized),
  CONSTRAINT fk_team_aliases_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE venues (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(191) NOT NULL,
  city       VARCHAR(128)     NULL,
  address    VARCHAR(255)     NULL,
  capacity   INT UNSIGNED     NULL,             -- Fassungsvermoegen, NULL = unbekannt
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_venues_name_city (name, city)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Spieltag. number = 0 bedeutet 'noch keinem Spieltag zugeordnet'.
CREATE TABLE rounds (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  competition_season_id  BIGINT UNSIGNED NOT NULL,
  number                 INT          NOT NULL,
  name                   VARCHAR(64)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rounds_cs_number (competition_season_id, number),
  CONSTRAINT fk_rounds_cs FOREIGN KEY (competition_season_id) REFERENCES competition_seasons (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Welche Migrationen schon gelaufen sind.
--
-- Bei einer frischen Installation bringt das Schema alles mit; tools/migrate.php
-- erkennt das und traegt die Migrationen als erledigt ein, ohne sie auszufuehren.
CREATE TABLE schema_migrations (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(191) NOT NULL,
  applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  detected   TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_migration (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- Benutzer

-- Zwei globale Rollen:
--
--   admin  Webadmin  - darf alles, ueberall
--   user   Mitmachen - darf Ligen anlegen und lesen; was er darin darf,
--                      entscheidet die Mitgliedschaft am Wettbewerb
--
-- Jeder kann sich anmelden. Das ist unbedenklich, weil ein neues Konto
-- niemandem etwas anhaben kann: Schreibrechte gelten nur fuer eigene Ligen.
CREATE TABLE users (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username            VARCHAR(64)  NOT NULL,
  username_normalized VARCHAR(64)  NOT NULL,
  email               VARCHAR(191)     NULL,
  email_normalized    VARCHAR(191)     NULL,
  email_verified_at   DATETIME         NULL,
  password_hash       VARCHAR(255) NOT NULL,
  role                VARCHAR(16)  NOT NULL DEFAULT 'user',
  active              TINYINT(1)   NOT NULL DEFAULT 1,
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at       DATETIME         NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username_normalized),
  UNIQUE KEY uq_users_email (email_normalized)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Wer darf an einem Wettbewerb arbeiten.
--
-- Die Mitgliedschaft haengt am Wettbewerb, nicht an der einzelnen Saison: wer
-- eine Liga betreut, betreut sie ueber die Jahre.
--
--   owner    hat die Liga angelegt; darf alles, auch Rechte vergeben und
--            die Liga entfernen
--   coadmin  darf pflegen und importieren, aber keine Rechte vergeben und
--            die Liga nicht entfernen
--
-- Der Webadmin steht ueber allem und braucht keinen Eintrag.
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

-- Einmalige Marken fuer Mailbestaetigung und Passwort-Ruecksetzung.
--
-- Gespeichert wird nur der Hash: wer die Datenbank liest, kann damit keine
-- fremde Marke einloesen. Sie gelten kurz und nur einmal.
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

-- Anmeldeversuche, um automatisierte Massenanmeldungen zu bremsen.
--
-- Gespeichert wird nur ein Hash der Adresse, nicht die Adresse selbst: fuer
-- die Zaehlung genuegt er, und eine IP ist ein personenbezogenes Datum.
CREATE TABLE signup_attempts (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip_hash    CHAR(64)     NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_signup (ip_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------ Quellen

CREATE TABLE sources (
  id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug     VARCHAR(32)  NOT NULL,
  name     VARCHAR(128) NOT NULL,
  url      VARCHAR(512)     NULL,
  priority INT          NOT NULL DEFAULT 100,   -- kleiner = vertrauenswuerdiger
  PRIMARY KEY (id),
  UNIQUE KEY uq_sources_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------- Spiele

CREATE TABLE matches (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  competition_season_id  BIGINT UNSIGNED NOT NULL,
  round_id               BIGINT UNSIGNED NOT NULL,
  home_team_id           BIGINT UNSIGNED NOT NULL,
  away_team_id           BIGINT UNSIGNED NOT NULL,
  kickoff_utc            DATETIME         NULL,
  kickoff_tz             VARCHAR(64)  NOT NULL DEFAULT 'Europe/Berlin',
  kickoff_is_confirmed   TINYINT(1)   NOT NULL DEFAULT 0,
  home_goals             INT              NULL,
  away_goals             INT              NULL,
  home_goals_ht          INT              NULL,
  away_goals_ht          INT              NULL,
  status                 VARCHAR(16)  NOT NULL DEFAULT 'scheduled',
  venue_id               BIGINT UNSIGNED  NULL,
  spectators             INT              NULL,
  note                   VARCHAR(255)     NULL,
  created_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_matches_pairing (competition_season_id, round_id, home_team_id, away_team_id),
  KEY ix_matches_kickoff (kickoff_utc),
  KEY ix_matches_status (status),
  CONSTRAINT fk_matches_cs    FOREIGN KEY (competition_season_id) REFERENCES competition_seasons (id),
  CONSTRAINT fk_matches_round FOREIGN KEY (round_id)     REFERENCES rounds (id),
  CONSTRAINT fk_matches_home  FOREIGN KEY (home_team_id) REFERENCES teams (id),
  CONSTRAINT fk_matches_away  FOREIGN KEY (away_team_id) REFERENCES teams (id),
  CONSTRAINT fk_matches_venue FOREIGN KEY (venue_id)     REFERENCES venues (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vertrauensmodell, feldgenau: ein Import darf ein Feld nur ueberschreiben,
-- wenn hier nicht confirmed = 1 mit einer hoeherwertigen Quelle steht.
CREATE TABLE match_field_sources (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  match_id   BIGINT UNSIGNED NOT NULL,
  field      VARCHAR(32)  NOT NULL,
  source_id  BIGINT UNSIGNED NOT NULL,
  confidence VARCHAR(16)  NOT NULL DEFAULT 'imported',
  confirmed  TINYINT(1)   NOT NULL DEFAULT 0,
  updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mfs_match_field (match_id, field),
  CONSTRAINT fk_mfs_match  FOREIGN KEY (match_id)  REFERENCES matches (id) ON DELETE CASCADE,
  CONSTRAINT fk_mfs_source FOREIGN KEY (source_id) REFERENCES sources (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------ Datenquellen


CREATE TABLE source_mappings (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_id    BIGINT UNSIGNED NOT NULL,
  entity_type  VARCHAR(32)  NOT NULL,           -- 'match' | 'team' | 'club' | 'competition_season'
  internal_id  BIGINT UNSIGNED NOT NULL,
  external_id  VARCHAR(191) NOT NULL,
  external_url VARCHAR(512)     NULL,
  last_seen_at DATETIME         NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sm_source_entity_external (source_id, entity_type, external_id),
  KEY ix_sm_internal (entity_type, internal_id),
  CONSTRAINT fk_sm_source FOREIGN KEY (source_id) REFERENCES sources (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------- Import

CREATE TABLE import_batches (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_id             BIGINT UNSIGNED NOT NULL,
  competition_season_id BIGINT UNSIGNED  NULL,
  adapter               VARCHAR(64)  NOT NULL,
  filename              VARCHAR(255)     NULL,
  row_count             INT          NOT NULL DEFAULT 0,
  status                VARCHAR(16)  NOT NULL DEFAULT 'pending',  -- pending|applied|discarded
  admin_note            VARCHAR(255)     NULL,
  created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  applied_at            DATETIME         NULL,
  PRIMARY KEY (id),
  CONSTRAINT fk_ib_source FOREIGN KEY (source_id) REFERENCES sources (id),
  CONSTRAINT fk_ib_cs     FOREIGN KEY (competition_season_id) REFERENCES competition_seasons (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE import_rows (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  batch_id        BIGINT UNSIGNED NOT NULL,
  line_no         INT          NOT NULL DEFAULT 0,
  raw_json        TEXT             NULL,
  parsed_json     TEXT             NULL,   -- enthaelt ggf. 'alternatives' bei Konflikten
  action          VARCHAR(16)  NOT NULL DEFAULT 'unchanged', -- create|update|unchanged|conflict|skip
  target_match_id BIGINT UNSIGNED  NULL,
  status          VARCHAR(16)  NOT NULL DEFAULT 'pending',
  message         VARCHAR(255)     NULL,
  PRIMARY KEY (id),
  KEY ix_ir_batch (batch_id),
  CONSTRAINT fk_ir_batch FOREIGN KEY (batch_id) REFERENCES import_batches (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE change_log (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entity_type VARCHAR(32)  NOT NULL,
  entity_id   BIGINT UNSIGNED NOT NULL,
  field       VARCHAR(32)  NOT NULL,
  old_value   VARCHAR(255)     NULL,
  new_value   VARCHAR(255)     NULL,
  actor       VARCHAR(64)  NOT NULL DEFAULT 'admin',
  source_id   BIGINT UNSIGNED  NULL,
  batch_id    BIGINT UNSIGNED  NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_cl_entity (entity_type, entity_id),
  KEY ix_cl_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
