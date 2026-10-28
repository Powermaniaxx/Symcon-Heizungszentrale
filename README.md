# Heizungszentrale für Symcon

Version 1.9.2: Die Überschrift „Heizungszentrale“ innerhalb der Kachel wird unter Symcon 9.0 ausgeblendet. Auf anderen Symcon-Versionen bleibt sie sichtbar. Die Erkennung erfolgt anhand der tatsächlich laufenden Kernel-Version; Untertitel und Bedienelemente bleiben erhalten.

Version 1.9.1: Vom 1. September bis einschließlich 14. November ist automatischer Winterbetrieb gesperrt (Zeitzone Europe/Berlin). Auch Kältephasen, externe Saisonvariablen und gespeicherte Winterzustände führen in diesem Zeitraum zum Herbstplan. Die Sperre hebt einen gespeicherten Winterzustand ohne weitere Mindesthaltezeit auf. Ab 15. November darf die Wetterautomatik Winter wieder vorschlagen; ihre Bestätigungszeiten gelten weiterhin. Eine ausdrücklich manuelle Winterwahl bleibt jederzeit möglich. Die Abwesenheitsabsenkung folgt entsprechend dem Herbstplan.

## Version 1.9.0 – lokale Lernoptimierung

Die bereits aktivierte Automatik lernt selbstständig pro Raum und Saison aus bestätigten Raumtemperaturen während anwesender Nutzung. Es werden keine manuellen Temperatureingaben als Wünsche übernommen und keine Daten an einen Lerndienst gesendet. Wochenpläne bleiben die Grundlage; Zeiten und gespeicherte Vorlagen werden nicht automatisch umgeschrieben. Homematic bleibt im automatischen Wochenplanbetrieb mit autonomem Geräteplan als Rückfalllösung.

Eine Heizphase muss bei frischen Ist-/Sollwerten mindestens zwei Stunden durchgehend mindestens 0,7 °C zu warm sein. Erst sieben verschiedene Beobachtungstage innerhalb von 28 Tagen erlauben eine Korrektur von −0,5 °C. Sieben bestätigte zu kalte Tage nehmen diese Korrektur zurück; positive Lernkorrekturen gibt es nicht. Änderungen sind höchstens einmal pro sieben Tagen möglich. Nachtwerte bleiben erhalten. Wetterkorrektur und Lernkorrektur werden nicht addiert. Ohne konfigurierte, bestätigte Anwesenheit oder bestätigten Geräte-Automatikmodus wird nicht gelernt. Gemeinsame Räume werden anhand des kälteren Sensors beurteilt.

Abweichende Sollwerte oder ein manueller Gerätemodus sperren den jeweiligen Beobachtungstag einschließlich bereits gesammelter Tagesbelege. Fehlende, ungültige oder über 20 Minuten alte Messwerte und Messlücken unterbrechen die Bestätigung. Vorlagenänderungen setzen das zugehörige Modell zurück. Nicht beobachtbare Eingriffe zwischen zwei Abfragen können grundsätzlich nicht erkannt werden. Diese Fassung lernt eine begrenzte thermische Korrektur während der Nutzung, keine vorhergesagten Anwesenheitszeiten.

Abwesenheit verwendet die passende Saisonvorlage mit 1,5 °C Absenkung, mindestens 14 °C; bereits niedrigere Frostschutzwerte werden niemals angehoben. Die automatische Sommer-Heizpause bleibt erhalten. Die separate gespeicherte Abwesenheitsvorlage wird im laufenden Abwesenheitsbetrieb dadurch ersetzt. Ein automatisch ermittelter Saisonwechsel benötigt zusätzlich 48 Stunden Bestätigung, maximal zwei Stunden Beobachtungslücke und sieben Tage Mindesthaltezeit. Manuelle Saisonwahl bleibt unmittelbar wirksam.

Aktivierung, Pause und lokale Modelle bleiben bei normalen Neustarts erhalten. Neue Instanzen und der Testmodus starten weiterhin ohne Gerätebefehle. Unmittelbar vor einem Schreibbefehl wird die automatische Fortsetzung gesperrt; erst ein erfolgreich abgeschlossener Lauf gibt sie frei. Nach einem unklaren Schreibabbruch oder Kommunikationsfehler muss die Automatik geprüft und wieder aktiviert werden. Dadurch wird ein unsicherer Befehl nach Neustart nicht automatisch wiederholt. Der lokale Diagnosebericht enthält `learning` und `seasonGate`.

Prüfung: PHP-Syntax sowie `php -n tests/weather-rules.php`, `php -n tests/learning.php` und `php -n tests/module-safety.php`. Die Tests verwenden keine realen Heizgeräte.

Ab Version 1.8.2 enthält der vorhandene lokale Diagnoseaufruf `SVHZ_GetCommunicationReport` zusätzlich den Betriebsstatus unter `control`. So kann der Hausstatus ab Version 0.78 Betriebsart, tatsächliche Aktivität, Testmodus und Saison direkt aus der Zentrale anzeigen. Die Bedienung erfolgt über deren bestehenden Modus-Befehl; eine separate Heizungssteuerung entsteht dadurch nicht.

Ab Version 1.8.1 zeigt die Oberfläche Betriebsart und tatsächliche Regelung getrennt. „Automatisch“ wählt die Betriebsart; erst „Automatik starten“ und die anschließende Bestätigung aktivieren die Regelung. Vorher stehen die Saison und Sollwerte als Vorschau in der Anzeige. Die zuletzt auf den Geräten gespeicherten Wochenpläne laufen unabhängig davon weiter.

Abgeschlossene Einlesevorgänge und das letzte Testergebnis sind standardmäßig eingeklappt. Sie können bei Bedarf über ihre Überschrift geöffnet werden. Laufende Einlesevorgänge und neue Fehler werden zunächst aufgeklappt; regelmäßige Aktualisierungen erhalten die selbst gewählte Ansicht.

Eine gemeinsame Visualisierung für Homematic-IP-Heizpläne, automatische Saisonwahl, eigene manuelle Profile und einen Testmodus. Erfordert Symcon ab 8.1, PHP mit cURL/SimpleXML und eine erreichbare CCU mit HmIP-XML-RPC.

## Entwicklungsstand

Version 1.8 macht die bisherige individuelle Installation konfigurierbar. Diese Fassung setzt weiterhin die ursprüngliche Struktur mit acht Endpunkten und sieben angezeigten Räumen voraus. Sie ist kein universeller Heizungsregler für beliebige Geräte und Gruppentopologien. Neue Instanzen bleiben ohne Zuordnung inaktiv. Zuerst im Testmodus prüfen.

## Installation

Die Repository-Adresse in der Symcon-Modulverwaltung hinzufügen. Danach eine Instanz „Heizungszentrale“ erstellen und die Zuordnungen konfigurieren. Bestehende lokale Installationen mit derselben Modul-ID müssen gesondert auf Git umgestellt werden; kein zweites Modul mit derselben ID parallel installieren und die vorhandene Instanz nicht löschen.

In `library.json` bleibt die Bibliotheks-ID unverändert. Auch die Modul-ID und der PHP-Präfix `SVHZ` bleiben erhalten. GitHub-Updates ersetzen die Quelldateien; Vorlagen, Einstellungen und manuelle Profile liegen in Symcon.

## Konfiguration

Alle Objekt-IDs und Geräteadressen werden ausschließlich in der eigenen Symcon-Instanz gespeichert. Das Repository enthält keine vorgegebenen privaten Zuordnungen.

- **CCUHost:** Hostname oder IP-Adresse ohne Protokoll und Port; Benutzer/Passwort bei Bedarf separat eintragen.
- **LivingGroup:** CCU-Heizgruppenadresse für den ersten logischen Raum. Der alte Eigenschaftsname bleibt aus Kompatibilitätsgründen erhalten. Die Gruppe verwendet Port 9292 mit `/groups`, physische Geräte Port 2010.
- **ZoneConfiguration:** JSON-Objekt mit den Schlüsseln `1` bis `8`. Jeder Eintrag enthält `[Name, Geräteinstanz-ID, Isttemperatur-ID, Solltemperatur-ID, Geräteprofil-ID]`. Alle IDs müssen positive Ganzzahlen sein. Die Geräteinstanz muss die Eigenschaft `Address` anbieten.
- Schlüssel `1` bezeichnet die Heizgruppe; seine Geräteinstanz ist das physische Quellgerät für den ursprünglichen Winterplan. Schlüssel `3` und `4` bilden gemeinsam einen angezeigten Raum und erhalten dieselben Pläne. Schlüssel `4` wird als Teilgerät angezeigt. Die übrigen Schlüssel sind eigenständige Räume.
- **SeasonVariable:** optionale Jahreszeit als Text oder formatierter Variablenwert. Ohne Zuordnung wird der Kalender verwendet.
- **OutsideVariable / WeatherTimestampVariable:** Außentemperatur und zugehöriger Messzeitpunkt als Unix-Zeit.
- **PresenceVariable:** optionale boolesche Anwesenheit, `false` bedeutet Abwesenheit.
- **ForecastMinVariable / ForecastMaxVariable:** optionale Vorhersagewerte, nur zur Anzeige.
- **RootID:** optionale Kategorie für den einmaligen Import alter Planervorlagen.

Beispielstruktur mit Platzhaltern – vor Verwendung alle IDs ersetzen:

```json
{
  "1": ["Heizgruppe", 101, 102, 103, 104],
  "2": ["Raum A", 201, 202, 203, 204],
  "3": ["Gemeinsamer Raum", 301, 302, 303, 304],
  "4": ["Zusatzgerät", 401, 402, 403, 404],
  "5": ["Raum B", 501, 502, 503, 504],
  "6": ["Raum C", 601, 602, 603, 604],
  "7": ["Raum D", 701, 702, 703, 704],
  "8": ["Raum E", 801, 802, 803, 804]
}
```

## Betrieb

Fünf Vorlagen je Raum: Frühjahr, Sommer, Herbst, Winter und Abwesenheit. Im Gerät werden Platz 2 für Normal und Platz 3 für Abwesenheit genutzt; Platz 1 bleibt unberührt. Lesen und Bearbeiten von Vorlagen sendet keine Heizbefehle. Echte Übertragungen benötigen den vorbereiteten Echtbetrieb. Beim Übertragen werden Gerätepläne gesichert und zurückgelesen. Bei Kommunikationsfehlern pausiert die Steuerung ohne automatische Wiederholung des unklaren Schreibvorgangs.

Im Automatikmodus wählt die Jahreszeit die Grundsaison. In Frühjahr/Herbst können ausreichend vollständige Messreihen auf Winter (72-Stunden-Mittel unter 12 °C) oder Sommer (120-Stunden-Mittel über 15 °C) umschalten. Rückschaltschwellen von 13 beziehungsweise 14 °C sorgen für Hysterese.

Automatischer Sommer: durchgehend 14 °C, auch bei Abwesenheit. Ein AUS mit garantiertem Frostschutz wird nicht behauptet. Bei Rückkehr zu Herbst werden dessen gespeicherte Pläne wieder verwendet.

Zusatzkorrektur nur in automatischem Frühjahr/Herbst: nach 30 Minuten bestätigten Messwerten ab 19 °C werden die wärmsten Heizphasen um 0,5 °C abgesenkt. Rückkehr nach 30 Minuten bis 18,5 °C. Wetterwerte dürfen höchstens 20 Minuten alt sein; größere Lücken starten die Bestätigung neu. Der Raum muss zum Einschalten mindestens 0,5 °C über seinem regulären Soll liegen. Sobald er unter dieses Soll fällt, eine Nachtphase beginnt oder Messwerte fehlen, gilt sofort der reguläre Plan. Vor einem erneuten Einschalten werden 30 Minuten gewartet. Im gemeinsamen Raum schützt der kältere Messwert beide Geräte.

Gespeicherte Wochenpläne bleiben unverändert. Nur der temporär übertragene Geräteplan wird korrigiert. Nachtwerte, gleichmäßige Tage, Winter und manuelle Steuerung erhalten keine Zusatzkorrektur. Warmwasser wird nicht gesteuert.

Manuelle Änderungen und benannte Gesamtprofile werden getrennt von den Automatikvorlagen gespeichert. Tagespläne lassen sich auf So–Do, Fr + Sa oder die gesamte Woche kopieren.

## Bestehende Automatik ablösen

`CentralProfileVariable`, `LegacyStateVariable`, `LegacySourceMarker` und `LegacyScripts` sind optionale Kompatibilitätseinstellungen für den ursprünglichen individuellen Umstieg. Sie dürfen nur nach Prüfung der eigenen alten Steuerung konfiguriert werden. Ohne diese Zuordnungen werden keine beliebigen fremden Skripte gesucht oder abgeschaltet. Andere aktive Heizungssteuerungen müssen vor einem neuen Echtbetrieb geklärt sein.

Sicherungen werden lokal im Symcon-Skriptverzeichnis unter `Heizungszentrale-Sicherungen` abgelegt. Zugangsdaten, Sicherungen, persönliche Zuordnungen und Geräte-Diagnoseausgaben gehören nicht in dieses öffentliche Repository.


## Version 1.10.0 · Build 20

- **In die Automatik übernehmen** am aktuellen Sollwert speichert ausdrücklich den frischen Geräte-Sollwert im aktuellen Abschnitt des aktuellen Wochentags der aktiven Saison. Eine manuelle Änderung alleine verändert keine Automatikvorlage. Bei unterschiedlichen Sollwerten der gekoppelten Geräte wird die Übernahme gesperrt. Während Abwesenheit und Sommerpause erfolgt keine direkte Sollwertübernahme; hierfür den Saisonplan bearbeiten.
- Der gleichnamige Button im Planeditor übernimmt die angezeigte Woche mit Temperaturen und Zeiten in die ausgewählte Saison und den ausgewählten Raum. Im manuellen Modus wird auch dessen Arbeitsplan gespeichert. Andere Räume/Saisons bleiben erhalten. Bestätigung zeigt den Umfang; veraltete Daten werden abgewiesen. Vorheriger Plan wird lokal gesichert. Aktive Automatik berücksichtigt Änderungen beim nächsten Steuerungslauf; Sommerpause und Abwesenheitsregeln behalten Vorrang.
- Beim tatsächlichen Symcon-Kernelstart wird Automatik gewählt, auch nach manueller Auswahl oder Pause. Eine bereits eingerichtete Zentrale wird innerhalb des nächsten Minutentakts über den bestehenden Aktivierungs-/Geräteprüfpfad wieder aktiviert. Testmodus bleibt ohne Gerätebefehle, Ersteinrichtung startet keine automatische Migration. Fehler pausieren die Steuerung; keine wiederholten unklaren Schreibversuche. Ein Speichern der Instanzkonfiguration ist kein Neustart und hebt eine Pause nicht auf.
- **Herbst-Abwesenheit:** Saisonplan minus 1,5 °C, mindestens 14 °C; bereits niedrigere Frostwerte bleiben erhalten. Übrige Räume werden zusätzlich auf höchstens 15 °C begrenzt. Wohnzimmer erhält mindestens 18 °C, Schlafzimmer durchgehend 14 °C. Die Ausnahmen erkennen die konfigurierten Raumnamen `Wohnzimmer`/`Living` bzw. `Schlafzimmer`/`Bedroom` (auch als Teil des Namens). Zuordnungen 3/4 nutzen weiterhin gemeinsam den Plan und Namen von 3. Andere Jahreszeiten behalten die bestehende Abwesenheitsregel. Gespeicherte Vorlagen werden durch Abwesenheit nicht verändert.
- **Absenkungen · Verlauf** zeigt Berliner Datum/Uhrzeit, Raum, Grund, vorherigen und neuen Sollwert. „Plan bestätigt“ folgt erst nach bestätigter Planübertragung und aktivem Geräteprofil; das beweist noch keine Temperaturänderung. „Sollwert beobachtet“ bezeichnet eine frische gesunkene Symcon-Sollwertrückmeldung, die zum aktuellen Laufzeitplan passt, keine Raumtemperaturmessung und keinen lückenlosen Gerätenachweis. Unbekannte Vorwerte werden ausdrücklich benannt. Simulationen erzeugen keine Bestätigungen. Die letzten 200 Ereignisse werden dauerhaft gespeichert; es gibt keinen rückwirkenden Verlauf. Raumkarten zeigen weiterhin aktuelle Zielwerte und nächste Umschaltzeiten.

Validierung: PHP-Syntax und Tests für Wetter, Lernen, Kaltstart-Vorheizen, Saisonübernahme, Konflikte, Herbst-Ausnahmen, Neustart und Verlauf mit simulierten Symcon-Schnittstellen. Keine reale Symcon-Instanz oder CCU angesprochen. Rollback auf den gesicherten vorherigen Code setzt bereits gespeicherte Vorlagen und Gerätepläne nicht automatisch zurück; dafür die lokalen Sicherungen verwenden.
