-- Grunddaten. Nach dem Schema einspielen.
--
-- Hier stehen ausschliesslich Eintraege, ohne die die Anwendung nicht
-- arbeiten kann. Ligen, Saisons und Mannschaften legt jeder selbst an -
-- eine frisch installierte Datenbank enthaelt keine Beispieldaten, die
-- hinterher wieder weggeraeumt werden muessten.
--
-- Zur Benennung der Liga-Kuerzel siehe docs/ligen.md.
-- priority: kleiner = vertrauenswuerdiger. Entscheidet, ob ein Import ein
-- bestehendes Feld ueberschreiben darf.
--
-- 'csv' steht mit 10 gleichauf mit 'manual', weil der CSV-Ruecklauf kein
-- fremder Import ist, sondern Handpflege in grosser Zahl: der Admin
-- exportiert, aendert in der Tabellenkalkulation und laedt wieder hoch.
-- Stuende csv darunter, koennte er seine eigenen bestaetigten Werte nie
-- wieder per Tabelle korrigieren.

-- name ist der in der Oberflaeche sichtbare Text und beschreibt den Weg, auf
-- dem Daten hereinkommen - nicht das Portal, aus dem eine Datei stammt. Das
-- ist Sache des Betreibers und keine Eigenschaft dieser Anwendung.
--
-- slug bleibt als interne Kennung stehen; darauf verweisen Adapter und Tests.

INSERT INTO sources (slug, name, url, priority) VALUES
  ('manual',        'Manuelle Pflege',        NULL, 10),
  ('csv',           'CSV-Ruecklauf',          NULL, 10),
  ('kicker',        'JSON-Import',            NULL, 50),
  ('worldfootball', 'HTML-Spielplan',         NULL, 60);
