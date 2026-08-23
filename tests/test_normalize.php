<?php
declare(strict_types=1);

T::group('Normalize - Vereinheitlichung');

T::same('borussia dortmund', Normalize::strict('Borussia Dortmund'), 'strict() macht klein');
T::same('djk suedwest koeln', Normalize::strict('DJK Südwest Köln'), 'strict() loest Umlaute auf');
T::same('1 ffc recklinghausen', Normalize::strict('1. FFC Recklinghausen'), 'strict() ersetzt Satzzeichen');
T::same('sv fortuna freudenberg bueschergrund', Normalize::strict('SV Fortuna Freudenberg-Büschergrund'), 'strict() behandelt Bindestriche');
T::same('', Normalize::strict(null), 'strict() vertraegt null');

T::group('Normalize - gelockerte Fassung');

T::same('duisburg', Normalize::loose('MSV Duisburg'), 'loose() entfernt Vereinskuerzel');
T::same('rhade', Normalize::loose('SSV Rhade 1925'), 'loose() entfernt Gruendungsjahr');
T::same('rhade', Normalize::loose('SSV Rhade'), 'kurze und lange Fassung fallen zusammen');
T::same('recklinghausen', Normalize::loose('1. FFC Recklinghausen'), 'loose() entfernt Ordnungszahl samt Kuerzel');
T::same('guetersloh', Normalize::loose('FSV Gütersloh 2009'), 'loose() entfernt Jahr am Ende');
T::same('schalke', Normalize::loose('FC Schalke 04'), 'loose() entfernt zweistelliges Jahr');

T::group('Normalize - Mannschaftskennung bleibt erhalten');

T::same('essen ii', Normalize::loose('SGS Essen II'), 'II wird nicht verworfen');
T::same('essen', Normalize::loose('SGS Essen'), 'Erste Mannschaft bleibt ohne Kennung');
T::ok(Normalize::loose('SGS Essen II') !== Normalize::loose('SGS Essen'), 'Reserve und Erste bleiben unterscheidbar');
T::same('bayer 04 leverkusen ii', Normalize::loose('Bayer 04 Leverkusen II'), 'Jahr in der Mitte bleibt stehen');
T::same('essen ii', Normalize::loose('SGS Essen 2'), 'arabische 2 wird zu ii vereinheitlicht');

T::group('Normalize - Aehnlichkeit');

T::same(0.0, Normalize::similarity('SGS Essen', 'SGS Essen II'), 'Erste und Reserve sind niemals aehnlich');
T::same(1.0, Normalize::similarity('SSV Rhade', 'SSV Rhade 1925'), 'Gruendungsjahr stoert die Aehnlichkeit nicht');
T::same(1.0, Normalize::similarity('Fortuna Köln', 'SC Fortuna Köln'), 'Vereinskuerzel stoert die Aehnlichkeit nicht');
T::ok(Normalize::similarity('Borussia Dortmund', 'MSV Duisburg') < 0.55, 'fremde Vereine bleiben unter der Schwelle');
T::ok(Normalize::similarity('Fortuna Freudenberg', 'SV Fortuna Freudenberg-Büschergrund') > 0.55, 'Langform wird als Vorschlag erkannt');
