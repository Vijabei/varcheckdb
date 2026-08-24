-- AUTOMATISCH GENERIERT aus db/schema.mysql.sql - nicht von Hand aendern.
-- Neu erzeugen mit:  python3 tools/mysql2sqlite.py
-- Nur fuer die lokalen Tests; produktiv laeuft MariaDB.

PRAGMA foreign_keys = ON;


CREATE TABLE competitions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  slug TEXT NOT NULL,
  name TEXT NOT NULL,
  gender TEXT NULL,
  age_group TEXT NULL,
  region TEXT NULL,
  level TEXT NULL,
  organizer TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (slug)
);

CREATE TABLE seasons (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL, -- '2026/27',
  start_year INTEGER NOT NULL, -- 2026 = OpenLigaDB leagueSeason,
  UNIQUE (start_year)
);

CREATE TABLE competition_seasons (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  competition_id INTEGER NOT NULL,
  season_id INTEGER NOT NULL,
  shortcut TEXT NOT NULL, -- OpenLigaDB leagueShortcut,
  name TEXT NOT NULL, -- OpenLigaDB leagueName,
  team_count INTEGER NULL,
  source_url TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (shortcut, season_id),
  UNIQUE (competition_id, season_id),
  CONSTRAINT fk_cs_competition FOREIGN KEY (competition_id) REFERENCES competitions (id),
  CONSTRAINT fk_cs_season      FOREIGN KEY (season_id)      REFERENCES seasons (id)
);

CREATE TABLE clubs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  short_name TEXT NULL,
  logo_url TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE teams (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  club_id INTEGER NULL,
  name TEXT NOT NULL,
  name_normalized TEXT NOT NULL,
  short_name TEXT NULL,
  logo_url TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (name_normalized),
  CONSTRAINT fk_teams_club FOREIGN KEY (club_id) REFERENCES clubs (id)
);

CREATE TABLE team_aliases (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  team_id INTEGER NOT NULL,
  alias TEXT NOT NULL,
  alias_normalized TEXT NOT NULL,
  source_id INTEGER NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (alias_normalized),
  CONSTRAINT fk_team_aliases_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE
);

CREATE TABLE venues (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  city TEXT NULL,
  address TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (name, city)
);

CREATE TABLE rounds (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  competition_season_id INTEGER NOT NULL,
  number INTEGER NOT NULL,
  name TEXT NOT NULL,
  UNIQUE (competition_season_id, number),
  CONSTRAINT fk_rounds_cs FOREIGN KEY (competition_season_id) REFERENCES competition_seasons (id)
);

CREATE TABLE users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT NOT NULL,
  username_normalized TEXT NOT NULL,
  password_hash TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'editor',
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at TEXT NULL,
  UNIQUE (username_normalized)
);

CREATE TABLE sources (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  slug TEXT NOT NULL,
  name TEXT NOT NULL,
  url TEXT NULL,
  priority INTEGER NOT NULL DEFAULT 100, -- kleiner = vertrauenswuerdiger,
  UNIQUE (slug)
);

CREATE TABLE matches (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  competition_season_id INTEGER NOT NULL,
  round_id INTEGER NOT NULL,
  home_team_id INTEGER NOT NULL,
  away_team_id INTEGER NOT NULL,
  kickoff_utc TEXT NULL,
  kickoff_tz TEXT NOT NULL DEFAULT 'Europe/Berlin',
  kickoff_is_confirmed INTEGER NOT NULL DEFAULT 0,
  home_goals INTEGER NULL,
  away_goals INTEGER NULL,
  home_goals_ht INTEGER NULL,
  away_goals_ht INTEGER NULL,
  status TEXT NOT NULL DEFAULT 'scheduled',
  venue_id INTEGER NULL,
  spectators INTEGER NULL,
  note TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (competition_season_id, round_id, home_team_id, away_team_id),
  CONSTRAINT fk_matches_cs    FOREIGN KEY (competition_season_id) REFERENCES competition_seasons (id),
  CONSTRAINT fk_matches_round FOREIGN KEY (round_id)     REFERENCES rounds (id),
  CONSTRAINT fk_matches_home  FOREIGN KEY (home_team_id) REFERENCES teams (id),
  CONSTRAINT fk_matches_away  FOREIGN KEY (away_team_id) REFERENCES teams (id),
  CONSTRAINT fk_matches_venue FOREIGN KEY (venue_id)     REFERENCES venues (id)
);

CREATE INDEX ix_matches_kickoff ON matches (kickoff_utc);

CREATE INDEX ix_matches_status ON matches (status);

CREATE TABLE match_field_sources (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  match_id INTEGER NOT NULL,
  field TEXT NOT NULL,
  source_id INTEGER NOT NULL,
  confidence TEXT NOT NULL DEFAULT 'imported',
  confirmed INTEGER NOT NULL DEFAULT 0,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (match_id, field),
  CONSTRAINT fk_mfs_match  FOREIGN KEY (match_id)  REFERENCES matches (id) ON DELETE CASCADE,
  CONSTRAINT fk_mfs_source FOREIGN KEY (source_id) REFERENCES sources (id)
);

CREATE TABLE source_mappings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  source_id INTEGER NOT NULL,
  entity_type TEXT NOT NULL, -- 'match' | 'team' | 'club' | 'competition_season',
  internal_id INTEGER NOT NULL,
  external_id TEXT NOT NULL,
  external_url TEXT NULL,
  last_seen_at TEXT NULL,
  UNIQUE (source_id, entity_type, external_id),
  CONSTRAINT fk_sm_source FOREIGN KEY (source_id) REFERENCES sources (id)
);

CREATE INDEX ix_sm_internal ON source_mappings (entity_type, internal_id);

CREATE TABLE import_batches (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  source_id INTEGER NOT NULL,
  competition_season_id INTEGER NULL,
  adapter TEXT NOT NULL,
  filename TEXT NULL,
  row_count INTEGER NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'pending', -- pending|applied|discarded,
  admin_note TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  applied_at TEXT NULL,
  CONSTRAINT fk_ib_source FOREIGN KEY (source_id) REFERENCES sources (id),
  CONSTRAINT fk_ib_cs     FOREIGN KEY (competition_season_id) REFERENCES competition_seasons (id)
);

CREATE TABLE import_rows (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  batch_id INTEGER NOT NULL,
  line_no INTEGER NOT NULL DEFAULT 0,
  raw_json TEXT NULL,
  parsed_json TEXT NULL, -- enthaelt ggf. 'alternatives' bei Konflikten,
  action TEXT NOT NULL DEFAULT 'unchanged', -- create|update|unchanged|conflict|skip,
  target_match_id INTEGER NULL,
  status TEXT NOT NULL DEFAULT 'pending',
  message TEXT NULL,
  CONSTRAINT fk_ir_batch FOREIGN KEY (batch_id) REFERENCES import_batches (id) ON DELETE CASCADE
);

CREATE INDEX ix_ir_batch ON import_rows (batch_id);

CREATE TABLE change_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  entity_type TEXT NOT NULL,
  entity_id INTEGER NOT NULL,
  field TEXT NOT NULL,
  old_value TEXT NULL,
  new_value TEXT NULL,
  actor TEXT NOT NULL DEFAULT 'admin',
  source_id INTEGER NULL,
  batch_id INTEGER NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX ix_cl_entity ON change_log (entity_type, entity_id);

CREATE INDEX ix_cl_created ON change_log (created_at);
