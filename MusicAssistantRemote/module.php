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
        $this->RegisterAttributeString('Favorites', '{}');
        $this->RegisterAttributeString('ExtVars', '[]');

        $this->SetVisualizationType(1);
    }

    public function GetCompatibleParents(): string
    {
        return json_encode(['type' => 'connect', 'moduleIDs' => ['{2A922FEF-DCDA-49B9-ABD0-2F074655BADC}']]);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->SetReceiveDataFilter('.*"Event":"(player_updated|player_added|player_removed|connection)".*');
        // eigene Lautstärke-/Stumm-Variablen (z. B. Gerätelautstärke eines TVs) beobachten
        foreach ((array) json_decode($this->ReadAttributeString('ExtVars'), true) as $vid) {
            $this->UnregisterMessage((int) $vid, VM_UPDATE);
        }
        $watch = [];
        foreach ($this->ExtVars() as $ext) {
            foreach ($ext as $vid) {
                if ($vid > 0 && IPS_VariableExists($vid)) {
                    $this->RegisterMessage($vid, VM_UPDATE);
                    $watch[] = $vid;
                }
            }
        }
        $this->WriteAttributeString('ExtVars', json_encode($watch));
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
        $slim = $this->ApplyExt($this->SlimPlayer($data['Data']));
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
                $vol = max(0, min(100, (int) ($v['value'] ?? 0)));
                $ext = $this->ExtVars()[$pid]['vol'] ?? 0;
                if ($ext > 0 && IPS_VariableExists($ext)) {
                    RequestAction($ext, $vol);
                } else {
                    $this->MACall('players/cmd/volume_set', ['player_id' => $pid, 'volume_level' => $vol]);
                }
                break;
            case 'mute':
                $mute = (bool) ($v['value'] ?? false);
                $ext = $this->ExtVars()[$pid]['mute'] ?? 0;
                if ($ext > 0 && IPS_VariableExists($ext)) {
                    RequestAction($ext, $mute);
                } else {
                    $this->MACall('players/cmd/volume_mute', ['player_id' => $pid, 'muted' => $mute]);
                }
                break;
            case 'power':
                $this->MACall('players/cmd/power', ['player_id' => $pid, 'powered' => (bool) ($v['value'] ?? false)]);
                break;
            case 'tone':
                $this->ChangeTone($pid, (array) ($v['change'] ?? []));
                break;
            case 'master':
                $this->SetMaster($pid, (bool) ($v['value'] ?? false));
                break;
            case 'favAdd':
                $this->FavAdd((array) ($v['item'] ?? []));
                break;
            case 'favRename':
                $this->FavChange((string) ($v['type'] ?? ''), (int) ($v['slot'] ?? -1), 'rename', (string) ($v['name'] ?? ''));
                break;
            case 'favDelete':
                $this->FavChange((string) ($v['type'] ?? ''), (int) ($v['slot'] ?? -1), 'delete');
                break;
            case 'favMove':
                $this->FavChange((string) ($v['type'] ?? ''), (int) ($v['slot'] ?? -1), ((int) ($v['dir'] ?? 0)) < 0 ? 'up' : 'down');
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
            if (!$this->IsWanted($pid) || !empty($p['hide_in_ui'])) {
                continue;
            }
            $slim = $this->ApplyExt($this->SlimPlayer($p));
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
            'types'     => json_decode($this->ReadPropertyString('MediaTypes'), true),
            'favs'      => $this->Favorites()
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
            'uri'       => $state === 'idle' ? '' : (string) ($m['uri'] ?? ''),
            'mediaType' => $state === 'idle' ? '' : (string) ($m['media_type'] ?? ''),
            'provider'  => (string) ($p['provider'] ?? ''),
            'canGroup'  => array_values(array_map('strval', (array) ($p['can_group_with'] ?? []))),
            'members'   => array_values(array_filter(array_map('strval', (array) ($p['group_members'] ?? [])), fn ($m) => $m !== ($p['player_id'] ?? ''))),
            'syncedTo'  => (string) ($p['synced_to'] ?? '')
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

    /**
     * Gerät als Master: alle anderen synchronisierbaren Geräte der Kachel spielen synchron mit.
     * Aus: Gruppe auflösen.
     */
    public function SetMaster(string $PlayerID, bool $On): bool
    {
        $state = json_decode($this->ReadAttributeString('State'), true);
        $players = (array) ($state['players'] ?? []);
        if (!isset($players[$PlayerID])) {
            return false;
        }
        $ok = true;
        if ($On) {
            // andere Master zuerst auflösen
            foreach ($players as $id => $p) {
                if ($id !== $PlayerID && !empty($p['members'])) {
                    $ok = ($this->MACall('players/cmd/set_members', ['target_player' => (string) $id, 'player_ids_to_remove' => $p['members']]) !== false) && $ok;
                }
            }
            $add = [];
            foreach ($players[$PlayerID]['canGroup'] ?? [] as $id) {
                if ($id !== $PlayerID && isset($players[$id]) && !empty($players[$id]['available'])) {
                    $add[] = $id;
                }
            }
            if (count($add) === 0) {
                $this->UpdateVisualizationValue(json_encode(['type' => 'toast', 'text' => $this->Translate('No other player can play in sync with this one')]));
                return false;
            }
            $ok = ($this->MACall('players/cmd/set_members', ['target_player' => $PlayerID, 'player_ids_to_add' => $add]) !== false) && $ok;
        } else {
            $members = $players[$PlayerID]['members'] ?? [];
            if (count($members) > 0) {
                $ok = $this->MACall('players/cmd/set_members', ['target_player' => $PlayerID, 'player_ids_to_remove' => $members]) !== false;
            }
        }
        if (!$ok) {
            $this->UpdateVisualizationValue(json_encode(['type' => 'toast', 'text' => $this->Translate('Sync could not be changed')]));
        }
        return $ok;
    }

    // ---------- Favoriten: je Art 20 Plätze ----------

    private const FAV_TYPES = ['playlist', 'radio', 'track', 'album', 'artist'];
    private const FAV_SLOTS = 20;

    /**
     * Favorit abspielen. Slot 1–20, PlayerIDs kommagetrennt (leer = alle Geräte der Kachel).
     */
    public function PlayFavorite(string $Type, int $Slot, string $PlayerIDs): bool
    {
        $fav = $this->Favorites()[$Type][$Slot - 1] ?? null;
        if (!is_array($fav) || empty($fav['uri'])) {
            return false;
        }
        $ids = array_values(array_filter(array_map('trim', explode(',', $PlayerIDs))));
        if (count($ids) === 0) {
            $ids = $this->WantedList();
        }
        return $this->PlayMediaOn((string) $fav['uri'], $ids, 'replace');
    }

    public function GetFavorites(): string
    {
        return json_encode($this->Favorites());
    }

    private function Favorites(): array
    {
        $f = json_decode($this->ReadAttributeString('Favorites'), true);
        $out = [];
        foreach (self::FAV_TYPES as $t) {
            $list = array_values((array) ($f[$t] ?? []));
            $list = array_slice(array_pad($list, self::FAV_SLOTS, null), 0, self::FAV_SLOTS);
            $out[$t] = array_map(fn ($x) => (is_array($x) && !empty($x['uri'])) ? $x : null, $list);
        }
        return $out;
    }

    private function SaveFavorites(array $Favs): void
    {
        $this->WriteAttributeString('Favorites', json_encode($Favs));
        $this->UpdateVisualizationValue(json_encode(['type' => 'favs', 'favs' => $Favs]));
    }

    private function FavAdd(array $Item): void
    {
        $type = (string) ($Item['type'] ?? '');
        $uri = (string) ($Item['uri'] ?? '');
        if (!in_array($type, self::FAV_TYPES, true) || $uri === '') {
            return;
        }
        $favs = $this->Favorites();
        foreach ($favs[$type] as $f) {
            if (is_array($f) && $f['uri'] === $uri) {
                $this->UpdateVisualizationValue(json_encode(['type' => 'toast', 'text' => $this->Translate('Already in favorites')]));
                return;
            }
        }
        $slot = array_search(null, $favs[$type], true);
        if ($slot === false) {
            $this->UpdateVisualizationValue(json_encode(['type' => 'toast', 'text' => $this->Translate('All 20 slots are taken')]));
            return;
        }
        $favs[$type][$slot] = [
            'uri'   => $uri,
            'name'  => mb_substr(trim((string) ($Item['name'] ?? '')), 0, 80),
            'sub'   => mb_substr(trim((string) ($Item['sub'] ?? '')), 0, 80),
            'image' => (string) ($Item['image'] ?? '')
        ];
        $this->SaveFavorites($favs);
        $this->UpdateVisualizationValue(json_encode(['type' => 'toast', 'text' => sprintf($this->Translate('Saved to slot %d'), $slot + 1)]));
    }

    private function FavChange(string $Type, int $Slot, string $Action, string $Name = ''): void
    {
        if (!in_array($Type, self::FAV_TYPES, true) || $Slot < 0 || $Slot >= self::FAV_SLOTS) {
            return;
        }
        $favs = $this->Favorites();
        $list = $favs[$Type];
        switch ($Action) {
            case 'rename':
                if (is_array($list[$Slot]) && trim($Name) !== '') {
                    $list[$Slot]['name'] = mb_substr(trim($Name), 0, 80);
                }
                break;
            case 'delete':
                $list[$Slot] = null;
                break;
            case 'up':
            case 'down':
                $to = $Slot + ($Action === 'up' ? -1 : 1);
                if ($to < 0 || $to >= self::FAV_SLOTS) {
                    return;
                }
                [$list[$Slot], $list[$to]] = [$list[$to], $list[$Slot]];
                break;
        }
        $favs[$Type] = $list;
        $this->SaveFavorites($favs);
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

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message !== VM_UPDATE) {
            return;
        }
        $state = json_decode($this->ReadAttributeString('State'), true);
        foreach ($this->ExtVars() as $pid => $ext) {
            if (in_array($SenderID, $ext, true) && isset($state['players'][$pid])) {
                $slim = $this->ApplyExt($state['players'][$pid]);
                if ($slim !== $state['players'][$pid]) {
                    $state['players'][$pid] = $slim;
                    $this->WriteAttributeString('State', json_encode($state));
                    $this->UpdateVisualizationValue(json_encode(['type' => 'player', 'player' => $slim]));
                }
            }
        }
    }

    /**
     * Je Player optionale Symcon-Variablen für Lautstärke (0–100) und Stumm, die statt Music Assistant geregelt werden.
     */
    private function ExtVars(): array
    {
        $out = [];
        foreach ((array) json_decode($this->ReadPropertyString('Players'), true) as $row) {
            $pid = (string) ($row['PlayerID'] ?? '');
            if ($pid !== '') {
                $out[$pid] = ['vol' => (int) ($row['VolumeVariable'] ?? 0), 'mute' => (int) ($row['MuteVariable'] ?? 0)];
            }
        }
        return $out;
    }

    private function ApplyExt(array $Slim): array
    {
        $ext = $this->ExtVars()[$Slim['id'] ?? ''] ?? null;
        if (!$ext) {
            return $Slim;
        }
        if ($ext['vol'] > 0 && IPS_VariableExists($ext['vol'])) {
            $Slim['volume'] = (int) round((float) GetValue($ext['vol']));
        }
        if ($ext['mute'] > 0 && IPS_VariableExists($ext['mute'])) {
            $Slim['muted'] = (bool) GetValue($ext['mute']);
        }
        return $Slim;
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
