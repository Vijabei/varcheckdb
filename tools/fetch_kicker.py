#!/usr/bin/env python3
"""Erzeugt eine Importdatei aus der kicker.de-Universal-API.

Laeuft lokal auf dem Rechner des Admins, nicht auf dem Webserver.
Ergebnis ist eine normalisierte JSON-Datei, die im Adminbereich
von vijabei.net hochgeladen wird.

Aufruf:
    python3 tools/fetch_kicker.py 4530 2026-27 -o samples/kicker-4530-2026-27.json
"""

import argparse
import json
import os
import sys
import time
import urllib.error
import urllib.request
import xml.etree.ElementTree as ET

BASE = 'https://ovsyndication.kicker.de/API/universal/3.0'
DELAY_SECONDS = 1.0

# Eine erreichbare Kontaktadresse im User-Agent ist der Quelle gegenueber
# fair: sie kann sich melden, statt stumm zu sperren. Sie steht bewusst nicht
# im Quelltext, damit sie in einem oeffentlichen Repository nicht mitgelesen
# wird. Setzen mit:
#
#   export VARCHECKDB_CONTACT='deine@adresse.example'
CONTACT = os.environ.get('VARCHECKDB_CONTACT', '').strip()
USER_AGENT = (
    f'varcheckdb Spielplan-Import (Fanprojekt, Kontakt: {CONTACT})'
    if CONTACT
    else 'varcheckdb Spielplan-Import (Fanprojekt)'
)


def get_xml(path: str) -> ET.Element:
    request = urllib.request.Request(
        f'{BASE}/{path}',
        headers={'User-Agent': USER_AGENT, 'Accept': 'application/xml'},
    )
    with urllib.request.urlopen(request, timeout=30) as response:
        return ET.fromstring(response.read())


def season_info(league_id: int, season: str) -> ET.Element:
    return get_xml(f'LeagueSeasonInfo/3/ligid/{league_id}/saison/{season}')


def gameday(league_id: int, season: str, number: int) -> ET.Element:
    return get_xml(f'Gameday/3/ligid/{league_id}/saison/{season}/spieltag/{number}')


def to_int(value: str | None) -> int | None:
    if value is None or value == '':
        return None
    try:
        return int(value)
    except ValueError:
        return None


def match_status(element: ET.Element) -> str:
    """Leitet den internen Status aus completed/state/currentPeriod ab."""
    state = (element.get('state') or '').strip().lower()
    if state in ('abgesagt', 'annulliert'):
        return 'cancelled'
    if state in ('verlegt', 'ausgefallen'):
        return 'postponed'
    if element.get('completed') == '1':
        return 'finished'
    if to_int(element.get('currentPeriod')) not in (None, 0):
        return 'live'
    return 'scheduled'


def normalize_match(element: ET.Element) -> dict:
    home = element.find('homeTeam')
    guest = element.find('guestTeam')
    results = element.find('results')
    stadium = element.find('stadium')

    def result(name: str) -> int | None:
        return to_int(results.get(name)) if results is not None else None

    date = element.get('date') or ''
    return {
        'source_match_id': element.get('id'),
        'round': to_int(element.get('roundId')),
        'round_name': element.get('roundName'),
        'kickoff_date': date[:10],
        'kickoff_time': date[11:16],
        # kicker markiert selbst, ob die Ansetzung final ist
        'kickoff_confirmed': 1 if element.get('timeConfirmed') == '1' else 0,
        'home': home.get('longName') if home is not None else None,
        'home_short': home.get('shortName') if home is not None else None,
        'home_source_id': home.get('id') if home is not None else None,
        'home_logo': home.get('iconSmall') if home is not None else None,
        'away': guest.get('longName') if guest is not None else None,
        'away_short': guest.get('shortName') if guest is not None else None,
        'away_source_id': guest.get('id') if guest is not None else None,
        'away_logo': guest.get('iconSmall') if guest is not None else None,
        'home_goals': result('hergEnde'),
        'away_goals': result('aergEnde'),
        'home_goals_ht': result('hergHz'),
        'away_goals_ht': result('aergHz'),
        'status': match_status(element),
        'venue': stadium.get('city') if stadium is not None else None,
    }


def deduplicate(matches: list[dict]) -> tuple[list[dict], list[dict]]:
    """Fasst Mehrfacheintraege derselben Paarung zusammen.

    kicker.de fuehrt fuer manche Spieltage zwei Datensaetze je Paarung mit
    unterschiedlicher Anstosszeit: den urspruenglichen und den geaenderten.
    Die Metadaten (modifiedAt, timeConfirmed) sind bei beiden gleich.

    Massgeblich ist der Datensatz mit der hoeheren Quell-ID. Geprueft gegen
    die von Hand gepflegte OpenLigaDB-Liga rlw-frauen/2026 trifft diese Regel
    in 15 von 15 Faellen zu; worldfootball.net traf nur 7 von 15 und hinkt
    den Aenderungen also hinterher.

    Die verworfene Angabe wird trotzdem mitgeliefert, damit die Vorschau sie
    anzeigen und der Admin sie waehlen kann.
    """
    groups: dict[tuple, list[dict]] = {}
    for match in matches:
        groups.setdefault((match['round'], match['home'], match['away']), []).append(match)

    unique: list[dict] = []
    conflicts: list[dict] = []

    for key, group in groups.items():
        # Absteigend: der neuere Datensatz steht vorn und wird uebernommen.
        group.sort(key=lambda m: to_int(m['source_match_id']) or 0, reverse=True)
        chosen = group[0]

        distinct = {
            (m['kickoff_date'], m['kickoff_time'], m['home_goals'], m['away_goals'])
            for m in group
        }
        if len(distinct) > 1:
            chosen = dict(chosen)
            chosen['has_conflict'] = True
            conflicts.append({
                'round': key[0],
                'home': key[1],
                'away': key[2],
                # Der uebernommene zuerst; die uebrigen sind die verworfenen.
                'alternatives': [
                    {
                        'source_match_id': m['source_match_id'],
                        'kickoff_date': m['kickoff_date'],
                        'kickoff_time': m['kickoff_time'],
                        'home_goals': m['home_goals'],
                        'away_goals': m['away_goals'],
                    }
                    for m in group
                ],
            })

        unique.append(chosen)

    unique.sort(key=lambda m: (m['round'] or 0, m['kickoff_date'] or '', m['kickoff_time'] or ''))
    return unique, conflicts


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('league_id', type=int, help='kicker-Liga-ID, z.B. 4530')
    parser.add_argument('season', help='Saison im Format 2026-27')
    parser.add_argument('-o', '--output', required=True, help='Zieldatei (.json)')
    args = parser.parse_args()

    season_api = args.season.replace('/', '-')
    season_label = season_api.replace('-', '/')

    try:
        info = season_info(args.league_id, season_api)
    except urllib.error.HTTPError as error:
        print(f'Liga {args.league_id} / Saison {season_api}: HTTP {error.code}', file=sys.stderr)
        return 1

    gamedays = info.find('gamedays')
    days = list(gamedays) if gamedays is not None else []
    numbers = sorted(
        n for n in (to_int(g.get('id')) for g in days) if n is not None
    )
    print(f'{info.get("longName")} — {len(numbers)} Spieltage', file=sys.stderr)

    matches: list[dict] = []
    for index, number in enumerate(numbers):
        if index:
            time.sleep(DELAY_SECONDS)
        try:
            day = gameday(args.league_id, season_api, number)
        except urllib.error.HTTPError as error:
            print(f'  Spieltag {number}: HTTP {error.code} — uebersprungen', file=sys.stderr)
            continue
        found = [normalize_match(m) for m in day.findall('match')]
        matches.extend(found)
        print(f'  Spieltag {number:2d}: {len(found):2d} Rohsaetze', file=sys.stderr)

    matches, conflicts = deduplicate(matches)
    if conflicts:
        print(f'\n{len(conflicts)} Paarungen mit zwei Angaben - jeweils die neuere uebernommen:', file=sys.stderr)
        for c in conflicts:
            a = c['alternatives']
            gewaehlt = f"{a[0]['kickoff_date']} {a[0]['kickoff_time']}"
            verworfen = ', '.join(f"{x['kickoff_date']} {x['kickoff_time']}" for x in a[1:])
            art = 'Uhrzeit' if all(x['kickoff_date'] == a[0]['kickoff_date'] for x in a) else 'verlegt'
            print(f"  ST {c['round']:2d}  {c['home']} - {c['away']}: {gewaehlt}  (statt {verworfen}, {art})", file=sys.stderr)

    document = {
        'format': 'varcheckdb-import/1',
        'source': 'kicker',
        'source_league_id': args.league_id,
        'competition_name': info.get('longName'),
        'competition_slug': (info.get('longName') or '').lower().replace(' ', '-'),
        'season': season_label,
        'season_start_year': to_int(season_api.split('-')[0]),
        'timezone': 'Europe/Berlin',
        'fetched_at': time.strftime('%Y-%m-%dT%H:%M:%S%z'),
        'conflicts': conflicts,
        'matches': matches,
    }

    with open(args.output, 'w', encoding='utf-8') as handle:
        json.dump(document, handle, ensure_ascii=False, indent=2)

    print(f'\n{len(matches)} Spiele -> {args.output}', file=sys.stderr)
    return 0


if __name__ == '__main__':
    sys.exit(main())
