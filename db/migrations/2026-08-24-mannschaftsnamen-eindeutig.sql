-- Ein Mannschaftsname kommt genau einmal vor.
--
-- Bis hierher war ein Name nur zusammen mit Geschlecht und Altersklasse
-- eindeutig. Damit konnte 'Arminia Bielefeld' zweimal existieren: einmal fuer
-- die Frauen, einmal fuer die Maenner. Kuenftig ist es ein Eintrag, der in
-- beiden Wettbewerben steht; welcher gemeint ist, sagt das Spiel. Wo eine
-- Unterscheidung noetig ist, traegt der Name sie bereits - 'Arminia Bielefeld
-- U19', 'SGS Essen II'.
--
-- Nur noetig fuer Installationen, die vor dem 24.08.2026 eingerichtet wurden.
-- Neue Installationen bringen das Schema bereits so mit.

-- ---------------------------------------------------------------- 1. Pruefen
--
-- Gibt es denselben Namen mehrfach? Dann muss vor dem Umbau entschieden
-- werden, welcher Eintrag bleibt - die Spiele der uebrigen muessen umgehaengt
-- werden. Liefert diese Abfrage nichts, kann Schritt 2 direkt laufen.

SELECT name_normalized,
       COUNT(*)                  AS eintraege,
       GROUP_CONCAT(id)          AS ids,
       GROUP_CONCAT(name)        AS namen
  FROM teams
 GROUP BY name_normalized
HAVING COUNT(*) > 1;

-- Falls es Treffer gibt, je Gruppe so vorgehen (BEHALTEN und DOPPELT
-- einsetzen), sonst ueberspringen:
--
--   UPDATE matches      SET home_team_id = BEHALTEN WHERE home_team_id = DOPPELT;
--   UPDATE matches      SET away_team_id = BEHALTEN WHERE away_team_id = DOPPELT;
--   UPDATE team_aliases SET team_id      = BEHALTEN WHERE team_id      = DOPPELT;
--   DELETE FROM teams WHERE id = DOPPELT;

-- ----------------------------------------------------------------- 2. Umbau

ALTER TABLE teams DROP INDEX uq_teams_normalized;
ALTER TABLE teams ADD UNIQUE KEY uq_teams_normalized (name_normalized);
ALTER TABLE teams DROP COLUMN gender;
ALTER TABLE teams DROP COLUMN age_group;

-- ---------------------------------------------------------------- 3. Kontrolle

SELECT COUNT(*) AS mannschaften FROM teams;
SHOW INDEX FROM teams WHERE Key_name = 'uq_teams_normalized';
