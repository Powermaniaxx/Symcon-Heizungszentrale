# Heizungszentrale für Symcon

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
