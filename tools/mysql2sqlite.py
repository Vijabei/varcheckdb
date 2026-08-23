#!/usr/bin/env python3
"""Erzeugt db/schema.sqlite.sql aus db/schema.mysql.sql.

Das MySQL-Schema ist die einzige Quelle der Wahrheit. Die SQLite-Fassung
wird nur fuer die lokalen Tests gebraucht und deshalb generiert, damit die
beiden nicht auseinanderlaufen koennen.

Aufruf:  python3 tools/mysql2sqlite.py
"""

import pathlib
import re
import sys

SOURCE = pathlib.Path('db/schema.mysql.sql')
TARGET = pathlib.Path('db/schema.sqlite.sql')

HEADER = """-- AUTOMATISCH GENERIERT aus db/schema.mysql.sql - nicht von Hand aendern.
-- Neu erzeugen mit:  python3 tools/mysql2sqlite.py
-- Nur fuer die lokalen Tests; produktiv laeuft MariaDB.

PRAGMA foreign_keys = ON;
"""


def convert_table(name: str, body: str) -> tuple[str, list[str]]:
    """Wandelt einen CREATE-TABLE-Rumpf um und zieht Sekundaerindizes heraus."""
    columns: list[str] = []
    indexes: list[str] = []

    for line in body.split('\n'):
        line = line.strip().rstrip(',')
        if not line:
            continue

        # Eigenstaendige Indizes gibt es in SQLite nur als CREATE INDEX.
        match = re.match(r'^KEY\s+(\w+)\s*\((.+)\)$', line)
        if match:
            indexes.append(f'CREATE INDEX {match.group(1)} ON {name} ({match.group(2)});')
            continue

        match = re.match(r'^UNIQUE KEY\s+\w+\s*\((.+)\)$', line)
        if match:
            columns.append(f'  UNIQUE ({match.group(1)})')
            continue

        # Der Primaerschluessel steckt in SQLite in der Spaltendefinition.
        if re.match(r'^PRIMARY KEY \(id\)$', line):
            continue

        if line.startswith('CONSTRAINT '):
            columns.append('  ' + line)
            continue

        # Ab hier: normale Spalte.
        line = re.sub(
            r'^(\w+)\s+BIGINT UNSIGNED NOT NULL AUTO_INCREMENT$',
            r'\1 INTEGER PRIMARY KEY AUTOINCREMENT',
            line,
        )
        line = line.replace('BIGINT UNSIGNED', 'INTEGER')
        line = re.sub(r'TINYINT\(1\)', 'INTEGER', line)
        line = re.sub(r'\bINT\b(?!EGER)', 'INTEGER', line)
        line = re.sub(r'VARCHAR\((\d+)\)', r'TEXT', line)
        line = line.replace('DATETIME', 'TEXT')
        line = line.replace(' ON UPDATE CURRENT_TIMESTAMP', '')
        columns.append('  ' + re.sub(r'\s+', ' ', line))

    return 'CREATE TABLE %s (\n%s\n);' % (name, ',\n'.join(columns)), indexes


def main() -> int:
    if not SOURCE.is_file():
        print(f'{SOURCE} nicht gefunden', file=sys.stderr)
        return 1

    sql = SOURCE.read_text(encoding='utf-8')
    parts = [HEADER]

    for match in re.finditer(
        r'CREATE TABLE (\w+) \((.*?)\n\) ENGINE=.*?;', sql, re.DOTALL
    ):
        table, indexes = convert_table(match.group(1), match.group(2))
        parts.append(table)
        parts.extend(indexes)

    TARGET.write_text('\n\n'.join(parts) + '\n', encoding='utf-8')
    count = sum(1 for p in parts if p.startswith('CREATE TABLE'))
    print(f'{TARGET}: {count} Tabellen erzeugt')
    return 0


if __name__ == '__main__':
    sys.exit(main())
