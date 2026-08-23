#!/usr/bin/env python3
"""Erzeugt die Testfixtures aus den vollstaendigen Quelldateien.

Warum es dieses Werkzeug gibt: die Fixtures unter tests/fixtures/ sind
zurechtgeschnittene Ausschnitte echter Quelldaten. Ohne ein festgehaltenes
Verfahren waere nach einer Markup-Aenderung bei worldfootball.net nicht mehr
nachvollziehbar, wie sie entstanden sind.

Die Quelldateien selbst liegen unter samples/ und sind bewusst nicht im
Repository (gross, fremdes Urheberrecht). Wie man sie beschafft, steht in
docs/datenquellen.md.

    python3 tools/make_fixtures.py           # Fixtures neu erzeugen
    python3 tools/make_fixtures.py --check   # nur pruefen, nichts schreiben

--check meldet einen Unterschied zwischen den abgelegten Fixtures und dem,
was aus den aktuellen Quelldateien entstehen wuerde. Genau daran erkennt man,
dass sich die Quelle geaendert hat.
"""

import argparse
import json
import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parent.parent
SAMPLES = ROOT / 'samples'
FIXTURES = ROOT / 'tests' / 'fixtures'

# Diese Spieltage decken die interessanten Faelle ab:
#   1  gespielte Partien mit Ergebnis und Halbzeitstand
#  11  ein Doppeleintrag von kicker.de
#  27  ein weiterer Doppeleintrag
ROUNDS = (1, 11, 27)

KICKER_SOURCE = SAMPLES / 'kicker-4530-2026-27.json'
WF_SOURCE = SAMPLES / 'worldfootball-co16640-all-matches.html'

WF_TEMPLATE = (
    '<!doctype html><html lang="en"><head><meta charset="utf-8">'
    '<title>Regionalliga West 2026/2027: Fixtures &amp; All Results | worldfootball.net</title>'
    '</head><body><div class="hs-block hs-gameplan">{body}</div></body></html>'
)


def build_kicker(rounds: tuple[int, ...]) -> str:
    """Schneidet die kicker-Importdatei auf die gewaehlten Spieltage zu."""
    data = json.loads(KICKER_SOURCE.read_text(encoding='utf-8'))

    trimmed = {k: v for k, v in data.items() if k not in ('matches', 'conflicts')}
    trimmed['conflicts'] = [c for c in data.get('conflicts', []) if c['round'] in rounds]
    trimmed['matches'] = [m for m in data['matches'] if m['round'] in rounds]

    return json.dumps(trimmed, ensure_ascii=False, indent=1) + '\n'


def build_worldfootball(rounds: tuple[int, ...]) -> str:
    """Schneidet die gespeicherte worldfootball-Seite auf die Spieltage zu.

    Zwei Eigenheiten der Seite bestimmen das Vorgehen:

    - Die Datei behauptet im meta-Tag utf-8, ist aber Windows-1252. Beim
      Speichern aus dem Browser unter Windows passiert das regelmaessig.
    - data-round_id traegt fuer alle Spiele denselben Wert und ist unbrauchbar.
      Der Spieltag ergibt sich nur aus den 'Matchday N'-Zwischenueberschriften
      und der Reihenfolge im Dokument. Deshalb wird linear gelesen.
    """
    html = WF_SOURCE.read_text(encoding='cp1252')

    blocks: list[str] = []
    current: int | None = None

    marker = re.compile(
        r'<div[^>]*(?:class="[^"]*round-head[^"]*"|data-match_id="\d+")[^>]*>'
    )

    for match in marker.finditer(html):
        if 'round-head' in match.group(0):
            end = html.index('</div>', match.end()) + len('</div>')
            number = re.search(r'(\d+)', html[match.end():end])
            current = int(number.group(1)) if number else None
            if current in rounds:
                blocks.append(html[match.start():end])
            continue

        # Spiel-Container bis zum zugehoerigen schliessenden Tag greifen.
        depth, position = 0, match.start()
        while True:
            nxt = re.compile(r'<div\b|</div>').search(html, position)
            if nxt is None:
                break
            depth += -1 if nxt.group(0) == '</div>' else 1
            position = nxt.end()
            if depth == 0:
                break

        if current in rounds:
            blocks.append(html[match.start():position])

    return WF_TEMPLATE.format(body='\n'.join(blocks))


def verify(kicker: str, worldfootball: str, rounds: tuple[int, ...]) -> list[str]:
    """Plausibilitaetspruefung, bevor irgendetwas geschrieben wird."""
    problems: list[str] = []

    data = json.loads(kicker)
    got = sorted({m['round'] for m in data['matches']})
    if got != sorted(rounds):
        problems.append(f'kicker: Spieltage {got}, erwartet {sorted(rounds)}')
    if len(data['matches']) != 8 * len(rounds):
        problems.append(f'kicker: {len(data["matches"])} Spiele, erwartet {8 * len(rounds)}')
    if not data['conflicts']:
        problems.append('kicker: kein Konflikt enthalten - der Konfliktpfad waere ungetestet')

    count = len(re.findall(r'data-match_id=', worldfootball))
    if count != 8 * len(rounds):
        problems.append(f'worldfootball: {count} Spiele, erwartet {8 * len(rounds)}')

    heads = re.findall(r'round-head[^>]*>([^<]*)<', worldfootball)
    if len(heads) != len(rounds):
        problems.append(f'worldfootball: {len(heads)} Spieltag-Ueberschriften, erwartet {len(rounds)}')

    # Der Umlaut ist der Kanarienvogel fuer den Zeichensatz.
    if 'Vorwärts Spoho 98' not in worldfootball:
        problems.append('worldfootball: Umlaute sind beschaedigt (Zeichensatz)')
    if '�' in worldfootball or '�' in kicker:
        problems.append('Ersetzungszeichen U+FFFD im Ergebnis - Zeichensatz stimmt nicht')

    return problems


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true',
                        help='nur vergleichen, nichts schreiben')
    parser.add_argument('--rounds', default=','.join(str(r) for r in ROUNDS),
                        help='Spieltage, kommagetrennt')
    args = parser.parse_args()

    rounds = tuple(int(r) for r in args.rounds.split(','))

    missing = [p for p in (KICKER_SOURCE, WF_SOURCE) if not p.is_file()]
    if missing:
        for path in missing:
            print(f'Quelldatei fehlt: {path.relative_to(ROOT)}', file=sys.stderr)
        print('\nSiehe docs/datenquellen.md, wie die Dateien beschafft werden.', file=sys.stderr)
        return 2

    kicker = build_kicker(rounds)
    worldfootball = build_worldfootball(rounds)

    problems = verify(kicker, worldfootball, rounds)
    if problems:
        print('Die erzeugten Fixtures sind nicht plausibel:', file=sys.stderr)
        for problem in problems:
            print(f'  - {problem}', file=sys.stderr)
        return 1

    targets = {
        FIXTURES / 'kicker-sample.json': kicker.encode('utf-8'),
        FIXTURES / 'worldfootball-sample.html': worldfootball.encode('utf-8'),
        # Absichtlich falsch kodiert: bildet den Praxisfall nach, dass der
        # Browser die Seite als Windows-1252 speichert.
        FIXTURES / 'worldfootball-cp1252.html': worldfootball.encode('cp1252'),
    }

    changed = []
    for path, content in targets.items():
        current = path.read_bytes() if path.is_file() else None
        if current != content:
            changed.append(path)
        if not args.check:
            path.write_bytes(content)

    if args.check:
        if changed:
            print('Die abgelegten Fixtures weichen von den Quelldateien ab:', file=sys.stderr)
            for path in changed:
                print(f'  - {path.relative_to(ROOT)}', file=sys.stderr)
            print('\nNeu erzeugen mit: python3 tools/make_fixtures.py', file=sys.stderr)
            return 1
        print(f'Fixtures sind aktuell (Spieltage {", ".join(str(r) for r in rounds)}).')
        return 0

    for path in targets:
        marker = 'geaendert' if path in changed else 'unveraendert'
        print(f'  {path.relative_to(ROOT)}  ({marker})')
    print(f'\n{8 * len(rounds)} Spiele je Quelle, Spieltage {", ".join(str(r) for r in rounds)}.')

    return 0


if __name__ == '__main__':
    sys.exit(main())
