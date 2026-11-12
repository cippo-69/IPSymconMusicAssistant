<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/MAHelper.php';

/**
 * Music Assistant Kachel (HTML-SDK)
 *
 * Bedienoberfläche im Stil von Music Assistant: Jetzt läuft, Suche (Musik/Radio), Ausgabegeräte mit
 * Lautstärke- und Klangreglern. Arbeitet direkt mit dem Server, unabhängig von Player-Instanzen.
 */
class MusicAssistantRemote extends IPSModuleStrict
{
    use MAHelper;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Players', '[]');      // leer = alle sichtbaren Player
        $this->RegisterPropertyString('MediaTypes', '["playlist","radio","track","album","artist"]');
        $this->RegisterPropertyInteger('SearchLimit', 12);
        $this->RegisterPropertyBoolean('ShowTone', true);
        $this->RegisterPropertyInteger('AccentColor', 0xFD5D05);

        $this->RegisterAttributeString('State', '{}');

        $this->SetVisualizationType(1);
        $this->ConnectParent('{2A922FEF-DCDA-49B9-ABD0-2F074655BADC}');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->SetReceiveDataFilter('.*"Event":"(player_updated|player_added|player_removed|connection)".*');
        if ($this->HasActiveParent()) {
            $this->LoadPlayers();
        }
        $this->UpdateVisualizationValue(json_encode(['type' => 'full', 'state' => $this->BuildState()]));
    }

    public function GetVisualizationTile(): string
    {
        $initial = '<script>handleMessage(' . json_encode(json_encode(['type' => 'full', 'state' => $this->BuildState()])) . ');</script>';
        $accent = sprintf('#%06X', $this->ReadPropertyInteger('AccentColor') & 0xFFFFFF);
        $style = '<style>:root{--ma-accent:' . $accent . ';}</style>';
        return file_get_contents(__DIR__ . '/module.html') . $style . $initial;
    }

    public function ReceiveData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        $event = (string) ($data['Event'] ?? '');
        if ($event === 'connection') {
            if (!empty($data['Data']['connected'])) {
                $this->LoadPlayers();
            }
            $this->UpdateVisualizationValue(json_encode(['type' => 'full', 'state' => $this->BuildState()]));
            return '';
        }
        $pid = (string) ($data['ObjectID'] ?? '');
        if (!$this->IsWanted($pid)) {
            return '';
        }
        $state = json_decode($this->ReadAttributeString('State'), true);
        if ($event === 'player_removed') {
            unset($state['players'][$pid]);
            $this->WriteAttributeString('State', json_encode($state));
            $this->UpdateVisualizationValue(json_encode(['type' => 'remove', 'id' => $pid]));
            return '';
        }
        if (!is_array($data['Data'] ?? null)) {
            return '';
        }
        $slim = $this->SlimPlayer($data['Data']);
        if (isset($state['players'][$pid]['tone'])) {
            $slim['tone'] = $state['players'][$pid]['tone'];
        }
        // nur senden, wenn sich etwas Sichtbares geändert hat (elapsed_time ändert sich ständig)
        $old = $state['players'][$pid] ?? null;
        $state['players'][$pid] = $slim;
        $this->WriteAttributeString('State', json_encode($state));
        if ($old !== $slim) {
            $this->UpdateVisualizationValue(json_encode(['type' => 'player', 'player' => $slim]));
        }
        return '';
    }

    /**
     * Aufrufe aus der Kachel: Ident = Aktion, Value = JSON
     */
    public function RequestAction(string $Ident, mixed $Value): void
    {
        $v = is_string($Value) ? (json_decode($Value, true) ?? $Value) : $Value;
        $pid = is_array($v) ? (string) ($v['id'] ?? '') : '';
        switch ($Ident) {
            case 'refresh':
                $this->LoadPlayers();
                $this->UpdateVisualizationValue(json_encode(['type' => 'full', 'state' => $this->BuildState()]));
                break;
            case 'cmd':
                // play, pause, play_pause, stop, next, previous
                $cmd = (string) ($v['cmd'] ?? '');
                if (in_array($cmd, ['play', 'pause', 'play_pause', 'stop', 'next', 'previous'], true)) {
                    $this->MACall('players/cmd/' . $cmd, ['player_id' => $pid]);
                    if (in_array($cmd, ['pause', 'play_pause', 'stop', 'next', 'previous'], true)) {
                        $this->MANotifyManual($pid);
                    }
                }
                break;
            case 'volume':
                $this->MACall('players/cmd/volume_set', ['player_id' => $pid, 'volume_level' => max(0, min(100, (int) ($v['value'] ?? 0)))]);
                break;
            case 'mute':
                $this->MACall('players/cmd/volume_mute', ['player_id' => $pid, 'muted' => (bool) ($v['value'] ?? false)]);
                break;
            case 'power':
                $this->MACall('players/cmd/power', ['player_id' => $pid, 'powered' => (bool) ($v['value'] ?? false)]);
                break;
            case 'tone':
                $this->ChangeTone($pid, (array) ($v['change'] ?? []));
                break;
            case 'search':
                $this->Search((string) ($v['query'] ?? ''), (array) ($v['types'] ?? []));
                break;
            case 'playMedia':
                $targets = array_values(array_filter((array) ($v['targets'] ?? []), 'is_string'));
                $this->PlayMediaOn((string) ($v['uri'] ?? ''), $targets, (string) ($v['option'] ?? 'replace'));
                break;
            default:
                $this->SendDebug('RequestAction', 'unbekannt: ' . $Ident, 0);
        }
    }

    /**
     * Medien auf mehreren Playern starten (jeder Player bekommt eine eigene Warteschlange).
     */
    public function PlayMediaOn(string $URI, array $PlayerIDs, string $Option): bool
    {
        if ($URI === '' || count($PlayerIDs) === 0) {
            return false;
        }
        $ok = true;
        foreach ($PlayerIDs as $pid) {
            $this->MANotifyManual($pid);
            $args = ['queue_id' => $pid, 'media' => $URI];
            if ($Option !== '') {
                $args['option'] = $Option;
            }
            $ok = ($this->MACall('player_queues/play_media', $args) !== false) && $ok;
        }
        $this->UpdateVisualizationValue(json_encode(['type' => 'toast', 'text' => $ok ? $this->Translate('Playback started') : $this->Translate('Playback could not be started')]));
        return $ok;
    }

    // ---------- intern ----------

    private function LoadPlayers(): void
    {
        $players = $this->MACall('players/all');
        if (!is_array($players)) {
            return;
        }
        $state = ['players' => []];
        foreach ($players as $p) {
            $pid = (string) ($p['player_id'] ?? '');
            if (!$this->IsWanted($pid) || !empty($p['hide_in_ui']) || !empty($p['hide_player_in_ui'])) {
                continue;
            }
            $slim = $this->SlimPlayer($p);
            if ($this->ReadPropertyBoolean('ShowTone')) {
                $dsp = $this->MACall('config/players/dsp/get', ['player_id' => $pid]);
                if (is_array($dsp)) {
                    $slim['tone'] = $this->MAToneValues($dsp);
                }
            }
            $state['players'][$pid] = $slim;
        }
        $this->WriteAttributeString('State', json_encode($state));
    }

    private function BuildState(): array
    {
        $state = json_decode($this->ReadAttributeString('State'), true);
        $order = $this->WantedList();
        $players = array_values((array) ($state['players'] ?? []));
        if (count($order) > 0) {
            usort($players, function ($a, $b) use ($order) {
                return array_search($a['id'], $order) <=> array_search($b['id'], $order);
            });
        } else {
            usort($players, function ($a, $b) {
                return strcasecmp($a['name'], $b['name']);
            });
        }
        $info = $this->MACall('local/server_info');
        return [
            'connected' => is_array($info) && !empty($info['connected']),
            'players'   => $players,
            'showTone'  => $this->ReadPropertyBoolean('ShowTone'),
            'types'     => json_decode($this->ReadPropertyString('MediaTypes'), true)
        ];
    }

    private function SlimPlayer(array $p): array
    {
        $m = is_array($p['current_media'] ?? null) ? $p['current_media'] : [];
        $state = (string) ($p['playback_state'] ?? $p['state'] ?? 'idle');
        return [
            'id'        => (string) ($p['player_id'] ?? ''),
            'name'      => (string) ($p['display_name'] ?? $p['name'] ?? ''),
            'available' => (bool) ($p['available'] ?? false),
            'state'     => $state,
            'volume'    => isset($p['volume_level']) ? (int) round((float) $p['volume_level']) : null,
            'muted'     => (bool) ($p['volume_muted'] ?? false),
            'power'     => ($p['power_control'] ?? 'none') !== 'none' ? (bool) ($p['powered'] ?? false) : null,
            'title'     => $state === 'idle' ? '' : (string) ($m['title'] ?? ''),
            'artist'    => $state === 'idle' ? '' : (string) ($m['artist'] ?? ''),
            'image'     => $state === 'idle' ? '' : (string) ($m['image_url'] ?? ''),
            'provider'  => (string) ($p['provider'] ?? '')
        ];
    }

    private function ChangeTone(string $PlayerID, array $Change): void
    {
        $map = ['enabled' => 'enabled', 'bass' => 'bass_level', 'mid' => 'mid_level', 'treble' => 'treble_level', 'gain' => 'output_gain'];
        $c = [];
        foreach ($map as $from => $to) {
            if (array_key_exists($from, $Change)) {
                $c[$to] = $Change[$from];
            }
        }
        $dsp = $this->MASetTone($PlayerID, $c);
        if (!is_array($dsp)) {
            $this->UpdateVisualizationValue(json_encode(['type' => 'toast', 'text' => $this->Translate('Tone control could not be saved')]));
            return;
        }
        $state = json_decode($this->ReadAttributeString('State'), true);
        if (isset($state['players'][$PlayerID])) {
            $state['players'][$PlayerID]['tone'] = $this->MAToneValues($dsp);
            $this->WriteAttributeString('State', json_encode($state));
            $this->UpdateVisualizationValue(json_encode(['type' => 'player', 'player' => $state['players'][$PlayerID]]));
        }
    }

    private function Search(string $Query, array $Types): void
    {
        $Query = trim($Query);
        if ($Query === '') {
            return;
        }
        $allowed = ['playlist', 'radio', 'track', 'album', 'artist'];
        $Types = array_values(array_intersect($Types, $allowed));
        if (count($Types) === 0) {
            $Types = array_values(array_intersect((array) json_decode($this->ReadPropertyString('MediaTypes'), true), $allowed));
        }
        $result = $this->MACall('music/search', ['search_query' => $Query, 'media_types' => $Types, 'limit' => $this->ReadPropertyInteger('SearchLimit')], 20);
        if (!is_array($result)) {
            $this->UpdateVisualizationValue(json_encode(['type' => 'search', 'query' => $Query, 'error' => $this->Translate('Search failed'), 'groups' => []]));
            return;
        }
        $info = $this->MACall('local/server_info');
        $base = is_array($info) ? (string) ($info['base_url'] ?? '') : '';
        $groups = [];
        $keys = ['playlist' => 'playlists', 'radio' => 'radio', 'track' => 'tracks', 'album' => 'albums', 'artist' => 'artists'];
        foreach ($Types as $type) {
            $items = [];
            foreach ((array) ($result[$keys[$type]] ?? []) as $it) {
                $sub = '';
                if (!empty($it['artists']) && is_array($it['artists'])) {
                    $sub = implode(', ', array_map(fn ($a) => (string) ($a['name'] ?? ''), $it['artists']));
                } elseif (!empty($it['owner'])) {
                    $sub = (string) $it['owner'];
                }
                $items[] = [
                    'uri'   => (string) ($it['uri'] ?? ''),
                    'name'  => (string) ($it['name'] ?? ''),
                    'sub'   => $sub,
                    'image' => $this->MAImage($it, $base),
                    'type'  => $type
                ];
            }
            $groups[] = ['type' => $type, 'items' => $items];
        }
        $this->UpdateVisualizationValue(json_encode(['type' => 'search', 'query' => $Query, 'groups' => $groups]));
    }

    private function WantedList(): array
    {
        $list = json_decode($this->ReadPropertyString('Players'), true);
        $ids = [];
        foreach ((array) $list as $row) {
            if (!empty($row['PlayerID'])) {
                $ids[] = (string) $row['PlayerID'];
            }
        }
        return $ids;
    }

    private function IsWanted(string $PlayerID): bool
    {
        if ($PlayerID === '') {
            return false;
        }
        $list = $this->WantedList();
        return count($list) === 0 || in_array($PlayerID, $list, true);
    }
}
