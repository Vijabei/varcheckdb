# Benutzer und Rechte

## Der Gedanke

**Wer eine Liga anlegt, betreut sie** und entscheidet, wer daran mitarbeitet.
Nicht eine zentrale Stelle vergibt Rechte, sondern der, der die Arbeit
angefangen hat.

Daraus folgt, dass jeder sich anmelden kann: ein neues Konto kann an
bestehenden Ligen **nichts** ändern. Es kann eigene Ligen anlegen und die
öffentlichen Daten lesen — mehr nicht. Die offene Anmeldung ist also kein
Risiko, das durch eine Türsteherrolle aufgefangen werden müsste, sondern
ungefährlich, weil Schreibrechte immer an einer eigenen Liga hängen.

## Zwei Ebenen

**Global** — was jemand grundsätzlich ist:

| | Webadmin | Mitmachen |
|---|---|---|
| Lesen und exportieren | ✓ | ✓ |
| Eigene Ligen anlegen | ✓ | ✓ |
| An *fremden* Ligen arbeiten | ✓ | — |
| Benutzer verwalten | ✓ | — |
| Mannschaften aufräumen | ✓ | — |

**Je Liga** — was jemand an einer bestimmten Liga darf:

| | Besitzer | Co-Admin |
|---|---|---|
| Spiele ändern, einzeln und en bloc | ✓ | ✓ |
| Importieren, CSV zurückspielen | ✓ | ✓ |
| Co-Admins benennen und entlassen | ✓ | — |
| Liga entfernen | ✓ | — |

Der Webadmin steht über allem und braucht keinen Eintrag.

Die Mitgliedschaft hängt am **Wettbewerb**, nicht an der einzelnen Saison: wer
eine Liga betreut, betreut sie über die Jahre.

## Mitmachen

Konto anlegen unter `/admin/register.php`. Es wird **keine Mailadresse**
gespeichert — es geht hier nicht um Geld und nicht um private Daten. Das heißt
aber auch: ein vergessenes Passwort kann nur der Webadmin zurücksetzen.

Gegen automatisierte Massenanmeldungen gibt es eine Bremse: höchstens drei
Konten je Herkunft und Stunde. Gespeichert wird nur ein Hash der Adresse, denn
zum Zählen genügt er und eine IP ist ein personenbezogenes Datum.

## An einer fremden Liga mitarbeiten

Frag ihren Besitzer — er steht unter *Wettbewerbe* neben der Liga. Mit einem
Klick nimmt er dich als Co-Admin dazu.

Kommst du nicht weiter, hilft der Webadmin. Ein eingebauter Antragsweg mit
Postfach und Bearbeitungsständen wäre für eine Handvoll Leute mehr Apparat als
Nutzen.

## Eine Liga braucht immer einen Besitzer

Dem letzten Besitzer lassen sich die Rechte nicht entziehen. Sonst könnte nur
noch der Webadmin die Liga pflegen, und niemand könnte mehr Rechte vergeben.
Wer gehen will, macht vorher jemand anderen zum Besitzer.

Ligen aus der Zeit vor den Konten haben keinen Besitzer. Das ist kein Fehler:
der Webadmin kann sie pflegen und jemanden eintragen, der sie übernimmt.

## Wer hat was geändert

Jede Änderung landet im Änderungsprotokoll mit dem Benutzernamen — nicht mit
„admin" oder „import". Welche Quelle beteiligt war, steht daneben:

```text
actor   entity_type  quelle   n
anna    match        kicker   90    Import, von anna abgenommen
anna    user         manual    1    anna hat berta angelegt
berta   match        manual    4    Handkorrektur von berta
```

Ein Import trägt den Namen dessen, der die Vorschau abgenommen hat. Er hat sie
gesehen und verantwortet sie.

## Abgeschaltete Konten

Der Webadmin kann ein Konto abschalten, statt es zu entfernen. Es kommt dann
nicht mehr herein, auch mit richtigem Passwort — und eine **laufende Sitzung
endet beim nächsten Seitenaufruf**.

Wird ein Konto entfernt, verschwinden auch seine Mitgliedschaften. Was diese
Person geändert hat, bleibt im Protokoll stehen.

## Die öffentliche Schnittstelle

Sie braucht **keine Anmeldung**. Sie liest nur, und die Daten sollen gelesen
werden — das ist der Zweck des Projekts.

## Ausgesperrt

Es gibt bewusst kein Zurücksetzen per Mail: dafür bräuchte es einen
Mailversand, der eingerichtet und gepflegt sein will.

Kommt der Webadmin selbst nicht mehr herein, hilft ein neuer Passwort-Hash
direkt in der Datenbank — über phpMyAdmin oder die Hetzner-Konsole:

```bash
php -r 'echo password_hash("neues-passwort", PASSWORD_DEFAULT), "\n";'
```

```sql
UPDATE users
   SET password_hash = 'HIER_DER_HASH', active = 1
 WHERE username_normalized = 'dein_benutzername';
```

Gibt es gar keinen aktiven Webadmin mehr, greift wieder das Passwort aus
`config.php`, und du legst über den Adminbereich einen neuen an.

## Was es nicht gibt

Kein Zurücksetzen per Mail, keine Zwei-Faktor-Anmeldung, kein Antragsweg mit
Postfach, keine Rechte feiner als Besitzer und Co-Admin. Nichts davon wird
gebraucht, solange eine überschaubare Zahl von Leuten eine überschaubare Zahl
von Ligen pflegt.
