-- Grunddaten. Nach dem Schema einspielen.
--
-- shortcut ist der leagueShortcut der OpenLigaDB-kompatiblen Ausgabe und
-- damit Teil der oeffentlichen Schnittstelle. Nachtraeglich geaendert brechen
-- fremde Abfragen, deshalb vor dem ersten Import festlegen.
--
-- Benennungsregel: Geschlechtspraefix, dann die Liga, ohne Trennzeichen.
--
--   f = Frauen, m = Maenner
--
--   frlw   Frauen-Regionalliga West
--   mrlw   Maenner-Regionalliga West
--   fwfl   Frauen-Westfalenliga
--
-- Das Praefix wird auch bei Maennerligen gesetzt, obwohl 'rlw' kuerzer waere:
-- eine Regel mit Ausnahme ist keine Regel, und die Abkuerzung bliebe sonst
-- raten, sobald beide Ligen nebeneinanderstehen.
-- priority: kleiner = vertrauenswuerdiger. Entscheidet, ob ein Import ein
-- bestehendes Feld ueberschreiben darf.
--
-- 'csv' steht mit 10 gleichauf mit 'manual', weil der CSV-Ruecklauf kein
-- fremder Import ist, sondern Handpflege in grosser Zahl: der Admin
-- exportiert, aendert in der Tabellenkalkulation und laedt wieder hoch.
-- Stuende csv darunter, koennte er seine eigenen bestaetigten Werte nie
-- wieder per Tabelle korrigieren.

INSERT INTO sources (slug, name, url, priority) VALUES
  ('manual',        'Manuelle Pflege',   NULL,                              10),
  ('csv',           'CSV-Ruecklauf',     NULL,                              10),
  ('kicker',        'kicker.de',         'https://www.kicker.de/',          50),
  ('worldfootball', 'worldfootball.net', 'https://www.worldfootball.net/',  60);

INSERT INTO seasons (name, start_year) VALUES
  ('2026/27', 2026),
  ('2025/26', 2025);

INSERT INTO competitions (slug, name, gender, age_group, region, level, organizer) VALUES
  ('frauen-regionalliga-west', 'Frauen-Regionalliga West', 'women', 'senior', 'West',      'Regionalliga', 'WDFV'),
  ('frauen-westfalenliga',     'Frauen-Westfalenliga',     'women', 'senior', 'Westfalen', 'Verbandsliga', 'FLVW');

INSERT INTO competition_seasons (competition_id, season_id, shortcut, name, team_count, source_url)
SELECT c.id, s.id, 'frlw', 'Frauen-Regionalliga West 2026/2027', 16,
       'https://www.kicker.de/frauen-regionalliga-west/spieltag/2026-27'
FROM competitions c, seasons s
WHERE c.slug = 'frauen-regionalliga-west' AND s.start_year = 2026;

INSERT INTO competition_seasons (competition_id, season_id, shortcut, name, team_count, source_url)
SELECT c.id, s.id, 'fwfl', 'Frauen-Westfalenliga 2026/2027', 14, NULL
FROM competitions c, seasons s
WHERE c.slug = 'frauen-westfalenliga' AND s.start_year = 2026;
