# Benutzer und Rollen

## Zwei Rollen

| | Verwaltung | Pflege |
|---|---|---|
| Spiele ändern, einzeln und en bloc | ✓ | ✓ |
| CSV herunterladen und zurückspielen | ✓ | ✓ |
| Dateien importieren (JSON, HTML) | ✓ | — |
| Wettbewerbe anlegen und entfernen | ✓ | — |
| Benutzer verwalten | ✓ | — |

**Der CSV-Rücklauf gehört zur Pflege.** Er ist der Weg, auf dem viele
Änderungen auf einmal gemacht werden. Wer Spiele ändern darf, aber seine
eigene Tabelle nicht wieder hochladen kann, kann die Arbeit nicht tun.
Vollständige Importe aus fremden Dateien bleiben der Verwaltung.

**Das Entfernen eines Wettbewerbs bleibt der Verwaltung.** Es ist der einzige
Weg in der Anwendung, auf dem Daten unwiederbringlich verschwinden.

Bewusst kein Rechtesystem je Wettbewerb. Sobald jemand nur *seinen*
Wettbewerb pflegen dürfen soll, wird aus zwei Rollen ein Rechtesystem, und der
Aufwand steigt deutlich. Bis das gebraucht wird, bleibt es dabei.

## Der erste Zugang

Der Installer legt ihn an — Benutzername, Passwort, Rolle Verwaltung. Weitere
Zugänge entstehen im Adminbereich unter *Benutzer*; eine Selbstregistrierung
gibt es nicht.

Bei einer Installation, die vor der Einführung der Konten eingerichtet wurde,
ist die Benutzertabelle nach der Migration leer. Solange **kein aktiver
Verwalter** besteht, gilt weiterhin das Passwort aus `config.php`: damit kommst
du herein und legst den ersten Zugang an. Sobald ein aktiver Verwalter besteht,
verliert dieses Passwort seine Gültigkeit.

Es ist also ein Weg für den Anfang, keine dauerhafte Hintertür.

## Wer hat was geändert

Jede Änderung landet im Änderungsprotokoll mit dem Benutzernamen — nicht mit
„admin" oder „import". Welche Quelle beteiligt war, steht daneben:

```text
actor    entity_type  quelle   n
anna     match        kicker   90     Import, von anna bestätigt
anna     user         manual    1     anna hat berta angelegt
berta    match        manual    4     Handkorrektur von berta
```

Ein Import trägt den Namen dessen, der die Vorschau abgenommen hat. Er hat sie
gesehen und bestätigt; er verantwortet sie.

## Abgeschaltete Konten

Ein Konto lässt sich abschalten, statt es zu entfernen. Es kommt dann nicht
mehr herein, auch mit richtigem Passwort — und eine **laufende Sitzung endet
beim nächsten Seitenaufruf**. Wer abgeschaltet wird, arbeitet nicht bis zum
Abmelden weiter.

Entfernen ist ebenfalls möglich. Was diese Person geändert hat, bleibt im
Protokoll stehen; der Name bleibt dort als Text erhalten.

## Der letzte Verwalter

Der einzige aktive Zugang mit der Rolle Verwaltung lässt sich weder entfernen
noch abschalten noch herabstufen. Sonst käme niemand mehr an die
Benutzerverwaltung — und ohne Zugang zur Datenbank auch nicht mehr hinein.

Den eigenen Zugang kann man ohnehin nicht entfernen.

## Ausgesperrt

Es gibt bewusst kein Zurücksetzen per Mail: dafür bräuchte es einen
Mailversand, der eingerichtet und gepflegt sein will, und einen weiteren Weg
in die Anwendung hinein.

Kommt niemand mehr herein, hilft ein neuer Passwort-Hash direkt in der
Datenbank — über phpMyAdmin oder die Hetzner-Konsole. Den Hash erzeugt:

```bash
php -r 'echo password_hash("neues-passwort", PASSWORD_DEFAULT), "\n";'
```

```sql
UPDATE users
   SET password_hash = 'HIER_DER_HASH', active = 1
 WHERE username_normalized = 'dein_benutzername';
```

Steht dort noch gar kein Verwalter, genügt es, ihn auf `active = 0` zu setzen
— dann greift wieder das Passwort aus `config.php`, und du legst über den
Adminbereich einen neuen an.

## Was es nicht gibt

Kein Zurücksetzen per Mail, keine Zwei-Faktor-Anmeldung, keine
Selbstregistrierung, keine Rechte je Wettbewerb, keine Sitzungsverwaltung über
mehrere Geräte. Nichts davon wird gebraucht, solange eine Handvoll Leute eine
Handvoll Ligen pflegt.
