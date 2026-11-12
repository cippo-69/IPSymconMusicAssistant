# IP-Symcon – Music Assistant

Integration von [Music Assistant](https://www.music-assistant.io) (Version 2.x, eigenständiger Server oder Home-Assistant-Add-on) in IP-Symcon.

> Status: in Entwicklung (0.1). Getestet mit IP-Symcon 9.0 und Music Assistant 2.10.6.

## Funktionen
- **Server (Splitter):** dauerhafte WebSocket-Verbindung zur Music-Assistant-API, Anmeldung per Token, Ereignisse in Echtzeit.
- **Konfigurator:** listet alle Ausgabegeräte (AirPlay, Chromecast, DLNA, Squeezelite, Sendspin, Gruppen …) und legt sie als Instanzen an.
- **Player:** Wiedergabe (Zurück/Stopp/Abspielen/Pause/Weiter), Lautstärke als Schieberegler, Stumm, Ein/Aus (falls vom Gerät unterstützt), Titel/Interpret/Album/Cover, Klangregelung über den DSP von Music Assistant (Bass, Mitten, Höhen, Verstärkung).
- **Kachel:** Bedienoberfläche im Music-Assistant-Stil für die Kachel-Visualisierung (Jetzt läuft, Suche Musik/Radio, Geräte mit Lautstärke- und Klangreglern, Abspielen auf mehrere Geräte).
- **Hintergrundmusik (Automatik):** spielt zu Öffnungszeiten passende Playlisten (Tagesprofile mit Lautstärke und gewichteten Stichworten, Ausschlusslisten, Jahreszeitenfilter, Wechsel nach Zeit, keine Wiederholung, eigene Playlisten mit Vorrang). Handbedienung pausiert die Automatik bis zum nächsten Profil.

## Voraussetzungen
- IP-Symcon ab 8.0 (Darstellungen; IPSModuleStrict)
- Music Assistant 2.x mit Benutzeranmeldung; ein dauerhafter Zugangsschlüssel (Long-lived token)

## Installation
1. Symcon-Konsole → Kern-Instanzen → Modulverwaltung → `https://github.com/cippo-69/IPSymconMusicAssistant` hinzufügen.
2. Instanz **Music Assistant Server** anlegen: Host, Port (Standard 8095) und Token eintragen. Der WebSocket Client wird automatisch angelegt.
3. Instanz **Music Assistant Konfigurator** anlegen und die gewünschten Geräte erstellen.
4. Optional: **Music Assistant Kachel** und **Music Assistant Hintergrundmusik** anlegen (Ziel-Geräte per Player-ID).

## Befehle (Auszug)
```php
MAP_PlayMedia($id, 'radiobrowser://radio/<uuid>', 'replace'); // Medien abspielen
MAP_SetVolume($id, 25);
MAP_SetMute($id, true);
MAP_Play($id); MAP_Pause($id); MAP_Stop($id); MAP_Next($id); MAP_Previous($id);
MAP_SetShuffle($id, true); MAP_SetRepeat($id, 'all');
MAS_SendCommand($serverId, 'music/search', '{"search_query":"Jazz","media_types":["playlist"],"limit":5}');
```

## Hinweise
- Klangregelung: Music Assistant wendet den DSP je nach Ausgabeweg unterschiedlich an (siehe [DSP-Doku](https://www.music-assistant.io/dsp/)). Für Geräte ohne Unterstützung die Option „Klangregelung anzeigen“ abschalten.
- Zugangsdaten werden nur in der Instanzkonfiguration gespeichert, nicht im Code.

## Lizenz
MIT
