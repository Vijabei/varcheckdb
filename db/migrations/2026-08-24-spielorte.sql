-- @erledigt-wenn: SELECT COUNT(*) FROM information_schema.COLUMNS
--                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venues'
--                    AND COLUMN_NAME = 'capacity'

-- Spielorte mit Fassungsvermoegen, dafuer weniger am Wettbewerb.
--
-- Der Spielort haengt nicht am Verein, sondern am Spiel: eine Mannschaft
-- weicht aus, spielt ein Heimspiel auf dem Platz des Gegners oder traegt ein
-- Endspiel auf neutralem Boden aus. venue_id und spectators stehen deshalb
-- schon immer an matches; hier kommt nur dazu, wie viele Zuschauer der Ort
-- ueberhaupt fasst - damit beim Eintragen sichtbar ist, was moeglich waere.
--
-- Region, Ebene und Veranstalter am Wettbewerb entfallen. Sie waren beim
-- Anlegen auszufuellen, ohne je gelesen zu werden: eine Gesamtstruktur des
-- Spielbetriebs bildet diese Datenbank nicht ab, und drei Pflichtfelder ohne
-- Zweck halten eher jemanden vom Anlegen ab, als dass sie nuetzen.

ALTER TABLE venues
  ADD COLUMN capacity INT UNSIGNED NULL AFTER address;

ALTER TABLE competitions
  DROP COLUMN region,
  DROP COLUMN level,
  DROP COLUMN organizer;

SELECT COUNT(*) AS spielorte, COUNT(capacity) AS mit_fassungsvermoegen FROM venues;
