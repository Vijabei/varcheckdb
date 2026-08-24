-- Zwei Ligen als Testbestand.
--
-- Stand frueher in db/seed.sql und wurde damit bei jeder Installation
-- angelegt. Eine frische Datenbank soll aber leer sein, deshalb liegen die
-- Beispiele jetzt hier: sie sind Vorbedingung der Tests, nicht Grunddaten.
INSERT INTO seasons (name, start_year) VALUES
  ('2026/27', 2026),
  ('2025/26', 2025);

INSERT INTO competitions (slug, name, gender, age_group) VALUES
  ('frauen-regionalliga-west', 'Frauen-Regionalliga West', 'women', 'senior'),
  ('frauen-westfalenliga',     'Frauen-Westfalenliga',     'women', 'senior');

INSERT INTO competition_seasons (competition_id, season_id, shortcut, name, team_count, source_url)
SELECT c.id, s.id, 'frlw', 'Frauen-Regionalliga West 2026/2027', 16, NULL
FROM competitions c, seasons s
WHERE c.slug = 'frauen-regionalliga-west' AND s.start_year = 2026;

INSERT INTO competition_seasons (competition_id, season_id, shortcut, name, team_count, source_url)
SELECT c.id, s.id, 'fwfl', 'Frauen-Westfalenliga 2026/2027', 14, NULL
FROM competitions c, seasons s
WHERE c.slug = 'frauen-westfalenliga' AND s.start_year = 2026;
