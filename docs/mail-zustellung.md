# Mailzustellung einrichten

Die Anwendung verschickt genau zwei Sorten Mail: die Bestätigung einer
Adresse und den Verweis zum Zurücksetzen eines Passworts. Beide sind
wertlos, wenn sie im Spam landen — niemand sucht dort nach einer Mail, die
er in fünf Sekunden erwartet.

Drei Einträge im DNS entscheiden darüber. **SPF** sagt, wer im Namen der
Domain versenden darf. **DKIM** unterschreibt jede Mail. **DMARC** sagt dem
Empfänger, was er tun soll, wenn eine Mail keine der beiden Prüfungen
besteht — und liefert Berichte darüber zurück.

## Stand für vijabei.net

Erhoben am 24.08.2026:

| | |
|---|---|
| MX | `www457.your-server.de` — Hetzner nimmt Mail an |
| SPF | `v=spf1 +a +mx ?all` — vorhanden |
| DKIM | nicht geprüft, siehe unten |
| DMARC | **fehlt** |

Selbst prüfen:

```bash
dig +short TXT vijabei.net
dig +short TXT _dmarc.vijabei.net
```

Ohne `dig` geht es auch über einen öffentlichen Auflöser:

```bash
curl -s -H 'accept: application/dns-json' \
  'https://cloudflare-dns.com/dns-query?name=_dmarc.vijabei.net&type=TXT'
```

## 1. SPF prüfen

`v=spf1 +a +mx ?all` bedeutet: der Webserver (`+a`) und der Mailserver
(`+mx`) dürfen versenden, und für alle anderen wird **keine Aussage**
getroffen (`?all`). Das ist die Hetzner-Voreinstellung und reicht, solange
nur über Hetzner versendet wird.

`?all` ist allerdings schwach. Empfänger können daraus nichts ableiten, wenn
jemand Fremdes im Namen der Domain versendet. Sobald sicher ist, dass keine
anderen Absender gebraucht werden — kein Newsletter-Dienst, kein
Kontaktformular über Dritte — ist `~all` die bessere Wahl:

```text
vijabei.net.  TXT  "v=spf1 +a +mx ~all"
```

`~all` heißt „vermutlich nicht berechtigt" und lässt die Mail durch, markiert
sie aber. `-all` wäre hart und würde sie abweisen — erst umstellen, wenn die
DMARC-Berichte über Wochen zeigen, dass nichts Legitimes betroffen ist.

**Wichtig:** Der Absender der Anwendung muss auf der Domain liegen, sonst
greift SPF nicht. Ohne Angabe in `config.php` wird
`noreply@<domain aus base_url>` verwendet — das passt. Eine Absenderadresse
bei einem Freemail-Anbieter würde die Prüfung reißen.

## 2. DKIM einschalten

DKIM unterschreibt jede ausgehende Mail; der Empfänger prüft die Signatur
gegen einen öffentlichen Schlüssel im DNS. Es ist der wirksamste der drei
Einträge.

Bei Hetzner wird DKIM in der Verwaltungsoberfläche des Hostings eingeschaltet
(konsoleH, unter *E-Mail* → Domain → Einstellungen). Der nötige DNS-Eintrag
wird dabei erzeugt; liegt die Domain bei Hetzner, wird er meist automatisch
gesetzt.

Ob DKIM greift, sieht man am einfachsten an einer Testmail: im Kopf der
empfangenen Nachricht steht dann `dkim=pass`.

## 3. DMARC setzen

Erst beobachten, nicht gleich abweisen. `p=none` ändert nichts an der
Behandlung, sorgt aber dafür, dass Berichte kommen:

```text
_dmarc.vijabei.net.  TXT  "v=DMARC1; p=none; rua=mailto:postmaster@vijabei.net; adkim=r; aspf=r; pct=100"
```

Was die Teile bedeuten:

| | |
|---|---|
| `p=none` | nur beobachten, nichts abweisen |
| `rua=mailto:…` | wohin die Sammelberichte gehen |
| `adkim=r`, `aspf=r` | lockere Zuordnung: eine Subdomain als Absender genügt |
| `pct=100` | gilt für alle Mails |

Die Adresse in `rua` muss Mail annehmen können. Ein eigenes Postfach dafür
ist bequemer als das eigene Hauptpostfach — die Berichte kommen täglich von
jedem größeren Anbieter.

### Nach ein paar Wochen schärfen

Die Berichte sind XML in einer ZIP-Datei und von Hand kaum lesbar. Es gibt
kostenlose Auswertungsdienste; für ein Fanprojekt genügt auch, sie zu
überfliegen. Interessant ist nur eine Frage: **kommt Mail von Absendern, die
du nicht kennst?**

Wenn über mehrere Wochen alles aus Hetzner kommt und `dkim=pass` sowie
`spf=pass` zeigt, kann man schärfen:

```text
_dmarc.vijabei.net.  TXT  "v=DMARC1; p=quarantine; rua=mailto:postmaster@vijabei.net; pct=100"
```

`p=quarantine` schiebt unpassende Mail in den Spam-Ordner statt sie
durchzulassen. `p=reject` weist sie ganz ab — das ist das Ziel, aber erst,
wenn du sicher bist. Ein zu früh gesetztes `reject` bringt deine eigenen
Mails zum Verschwinden, und du merkst es erst, wenn sich jemand beschwert.

## 4. Prüfen, ob es wirkt

Eine Testmail an ein Gmail-Postfach schicken, dort *Original anzeigen*
öffnen. Oben stehen drei Zeilen, die alle `PASS` zeigen sollten:

```text
SPF:   PASS mit Domain vijabei.net
DKIM:  'PASS' mit Domain vijabei.net
DMARC: 'PASS'
```

Es gibt auch Dienste, an die man eine Mail schickt und einen Bericht
zurückbekommt — etwa mail-tester.com. Für einen einmaligen Test reicht das.

## Wenn kein Versand möglich ist

Manche Hostingpakete versenden gar nicht. Dann in `config.php`:

```php
'mail' => ['enabled' => false],
```

Konten lassen sich weiterhin anlegen und die Anwendung arbeitet normal; nur
der Selbst-Reset steht still, und die Seite sagt das offen — für jede Adresse
gleich, damit sich daran nicht ablesen lässt, wer hier ein Konto hat.

Passwörter setzt dann der Webadmin zurück, siehe [benutzer.md](benutzer.md).
