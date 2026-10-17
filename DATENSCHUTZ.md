# Fude: Hinweise für die Datenschutzerklärung

Diese Beschreibung gibt wieder, was das Plugin tut. Sie ist keine
Rechtsberatung; Rechtsgrundlage, Speicherdauer und Betroffenenrechte legt
jeder Betreiber für seine Seite selbst fest. Englische Kurzfassung:
[PRIVACY.md](PRIVACY.md).

## Was das Plugin tut

Eine vom Betreiber berechtigte Person schickt über einen Chat-Kanal
(Telegram, E-Mail) eine Nachricht, wahlweise mit Foto. Ein Bot standortßerhalb der
Website macht daraus einen Änderungsvorschlag und ruft dieses Plugin auf. Das
Plugin legt den Vorschlag als unveröffentlichte Kirby-Inhaltsversion ab,
liefert einen Vorschaulink und veröffentlicht erst nach ausdrücklicher
Bestätigung derselben Person.

## Was auf dem Webserver gespeichert wird

- **Der vorgeschlagene Inhalt** — die Textfelder und das verkleinerte Foto —
  als Kirby-Inhaltsversion oder Entwurfsseite, bis er veröffentlicht oder
  verworfen wird. Ein verworfener Entwurf samt Foto wird gelöscht, es sei
  denn, jemand hat ihn inzwischen im Panel bearbeitet oder veröffentlicht:
  Das bleibt als Arbeit der Redaktion stehen. Ein veröffentlichter Entwurf
  wird Teil der Website wie jede Panel-Änderung.
- **Ein Entwurfsdatensatz** (`storage/fude/drafts/`): Entwurfs-ID, Sammlung,
  Aktion, die übermittelten Feldwerte, der Dateiname des Fotos, das Konto,
  die Kanal-Kennung, die der Bot mitgibt (zum Beispiel `telegram:<Chat-ID>`
  oder `mail:<Adresse>`), und Zeitstempel. Gelöscht bei Veröffentlichung
  oder Verwerfen.
- **Ein Protokoll** (`storage/fude/log.jsonl`): eine Zeile je Aufruf mit
  Zeitpunkt, Route, Konto, Kanal-Kennung, Sammlung, Aktion, Schlüssel (Name
  oder Titel des Eintrags), Entwurfs-ID und Ergebnis. **Keine Feldwerte und
  keine Bilder.** Das Protokoll beginnt bei 4 MB von vorn.
- Kirbys eigene Protokollierung fehlgeschlagener Anmeldungen (PHP-Fehlerlog).

## Was nicht gespeichert wird

Keine IP-Adressen, keine User-Agents, kein Nachrichtentext über die erklärten
Felder hinaus, kein Originalfoto in voller Größe (nur die verkleinerte
Kopie), keine Zugangsdaten des Chat-Kanals.

## Wer was erreicht

Nur Konten mit der Rolle `fude` (oder den Rollen aus `fude.roles`), nur die
in `site/config/fude.php` erklärten Sammlungen und Felder, nur über HTTPS.
Das Bot-Konto kann sich nicht im Panel anmelden.

## Textbaustein

> Berechtigte Mitarbeiter können einzelne Inhalte dieser Website (zum
> Beispiel Teamliste und aktuelle Hinweise) per Nachricht an einen von uns
> betriebenen Assistenten ändern lassen. Der Assistent legt dazu einen
> Entwurf auf dem Webserver an, der erst nach Bestätigung veröffentlicht
> wird. Dabei werden die übermittelten Texte und Fotos sowie ein Protokoll
> (Zeitpunkt, Konto, Kanal-Kennung, geänderter Eintrag) gespeichert. Zur
> Formulierung des Entwurfs wird die Nachricht an einen KI-Dienst
> (Anthropic PBC) übermittelt; Einzelheiten dazu stehen im Abschnitt zum
> Assistenten.
