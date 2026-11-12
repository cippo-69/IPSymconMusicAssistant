<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/MAHelper.php';

/**
 * Music Assistant Hintergrundmusik (Automatik)
 *
 * Spielt zu Öffnungszeiten automatisch passende Playlisten auf einem oder mehreren Playern:
 * Tagesprofile mit Lautstärke und gewichteten Stichworten, Ausschlusslisten, Jahreszeitenfilter,
 * Wechsel nach Zeit, keine Wiederholung der letzten Playlisten, eigene Playlisten „<Präfix> <Profil>“ mit Vorrang.
 * Handbedienung (andere Musik, Stopp) pausiert die Automatik bis zum nächsten Tagesprofil.
 */
class MusicAssistantAutomation extends IPSModuleStrict
{
    use MAHelper;

    private const PRESENTATION_WEB = '{9DE1D610-5106-97FB-714D-1AADEDF8377A}';

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Players', '[]');
        $this->RegisterPropertyString('Hours', json_encode([
            ['Day' => 1, 'From' => '09:00', 'To' => '18:00'],
            ['Day' => 2, 'From' => '09:00', 'To' => '18:00'],
            ['Day' => 3, 'From' => '09:00', 'To' => '18:00'],
            ['Day' => 4, 'From' => '09:00', 'To' => '18:00'],
            ['Day' => 5, 'From' => '09:00', 'To' => '18:00'],
            ['Day' => 6, 'From' => '09:00', 'To' => '14:00']
        ]));
        $this->RegisterPropertyString('Profiles', json_encode([
            ['Name' => 'Morning', 'Days' => '1,2,3,4,5', 'From' => '00:00', 'To' => '12:30', 'Volume' => 30,
                'Words' => 'acoustic:4, soul:4, jazz:4, cafe:4, bossa:4, lounge:3, morning:3, chill:2, songwriter:3'],
            ['Name' => 'Afternoon', 'Days' => '1,2,3,4,5', 'From' => '12:30', 'To' => '16:00', 'Volume' => 33,
                'Words' => 'soul:4, lounge:4, indie:3, groove:3, jazz:3, chill:3, songwriter:3, roadtrip:3'],
            ['Name' => 'Late afternoon', 'Days' => '1,2,3,4,5', 'From' => '16:00', 'To' => '23:59', 'Volume' => 37,
                'Words' => 'feel good:5, 80s:4, 90s:4, funk:4, groove:4, soul:3, indie:3, classics:3'],
            ['Name' => 'Saturday', 'Days' => '6', 'From' => '00:00', 'To' => '23:59', 'Volume' => 37,
                'Words' => 'feel good:5, soul:4, funk:4, groove:4, indie:3, roadtrip:4']
        ]));
        $this->RegisterPropertyString('Exclude', 'rap, hip-hop, hip hop, trap, drill, metal, punk, hardstyle, hardcore, techno, rave, party, workout, fitness, gym, kids, sleep, meditation, karaoke, halloween, horror, sad');
        $this->RegisterPropertyString('ExcludeRegex', '');
        $this->RegisterPropertyString('Season', 'summer:6,7,8; beach:6,7,8; spring:3,4,5; autumn:9,10,11; winter:12,1,2; christmas:12; xmas:12; advent:11,12; easter:3,4; valentine:2');
        $this->RegisterPropertyString('Providers', 'qobuz');
        $this->RegisterPropertyString('OwnPrefix', 'Velologe');
        $this->RegisterPropertyInteger('Rotate', 60);
        $this->RegisterPropertyInteger('History', 20);
        $this->RegisterPropertyInteger('TopN', 8);
        $this->RegisterPropertyInteger('RescanDays', 7);
        $this->RegisterPropertyBoolean('PowerOffWhenClosed', true);

        $this->RegisterAttributeString('State', '{}');
        $this->RegisterAttributeString('Catalog', '{}');
        $this->RegisterAttributeString('Bans', '{}');

        $this->RegisterTimer('Tick', 0, 'MAA_Tick($_IPS[\'TARGET\']);');

        $this->ConnectParent('{2A922FEF-DCDA-49B9-ABD0-2F074655BADC}');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->RegisterVariableString('Card', $this->Translate('Background music'), ['PRESENTATION' => self::PRESENTATION_WEB, 'HTML_TYPE' => 0, 'PADDING' => true], 0);
        if ($this->RegisterVariableBoolean('Auto', $this->Translate('Automatic'), ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH, 'ICON_TRUE' => 'wand-magic-sparkles'], 1)) {
            $this->SetValue('Auto', true);
        }
        $this->EnableAction('Auto');
        $this->RegisterVariableInteger('Skip', $this->Translate('Other playlist'), $this->Button('forward-step', $this->Translate('Play another playlist')), 2);
        $this->EnableAction('Skip');
        $this->RegisterVariableInteger('Ban', $this->Translate('Block playlist'), $this->Button('ban', $this->Translate('Never play this playlist again')), 3);
        $this->EnableAction('Ban');
        $this->RegisterVariableString('Status', $this->Translate('Status'), ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'circle-info'], 4);
        $this->RegisterVariableString('Profile', $this->Translate('Day profile'), ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'clock'], 5);
        $this->RegisterVariableString('Playlist', $this->Translate('Current playlist'), ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'list-music'], 6);
        $this->RegisterVariableInteger('Rescan', $this->Translate('Reload catalog'), $this->Button('arrows-rotate', $this->Translate('Reload playlist catalog')), 7);
        $this->EnableAction('Rescan');

        $this->SetReceiveDataFilter('.*"Event":"connection".*');
        $this->SetTimerInterval('Tick', count($this->PlayerIDs()) > 0 ? 60 * 1000 : 0);
        $this->SetStatus(count($this->PlayerIDs()) > 0 ? IS_ACTIVE : 104);
        $this->Render('');
    }

    public function ReceiveData(string $JSONString): string
    {
        return '';
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        $st = $this->GetState();
        switch ($Ident) {
            case 'Auto':
                $this->SetValue('Auto', (bool) $Value);
                $st['manual'] = false;
                $st['profile'] = '';
                $this->SaveState($st);
                if ($Value) {
                    $this->Run(true);
                } else {
                    $this->Render($this->Translate('Automatic off'));
                }
                break;
            case 'Skip':
                $st['manual'] = false;
                $this->SaveState($st);
                if ($this->IsOpen()) {
                    $this->StartPlaylist($this->CurrentProfile(), false);
                }
                break;
            case 'Ban':
                if (!empty($st['playlist_uri'])) {
                    $bans = json_decode($this->ReadAttributeString('Bans'), true) ?: [];
                    $bans[$st['playlist_uri']] = ['name' => $st['playlist'] ?? '', 'date' => date('Y-m-d')];
                    $this->WriteAttributeString('Bans', json_encode($bans));
                    if ($this->IsOpen()) {
                        $this->StartPlaylist($this->CurrentProfile(), false);
                    }
                }
                break;
            case 'Rescan':
                $this->Render($this->Translate('Loading catalog …'));
                $this->Scan();
                $this->Render('');
                break;
            default:
                throw new Exception('Ungültiger Ident: ' . $Ident);
        }
    }

    // ---------- öffentliche Funktionen ----------

    public function Tick(): void
    {
        $this->Run(false);
    }

    /**
     * Von Kachel/Player aufrufen, wenn jemand auf einem Ziel-Player von Hand etwas startet oder stoppt.
     */
    public function NotifyManual(string $PlayerID): void
    {
        if (!in_array($PlayerID, $this->PlayerIDs(), true)) {
            return;
        }
        $st = $this->GetState();
        if (($st['profile'] ?? '') !== '' && !($st['manual'] ?? false)) {
            $st['manual'] = true;
            $this->SaveState($st);
            $this->Render('');
        }
    }

    public function GetCandidates(string $Profile): string
    {
        return json_encode($this->Candidates($Profile === '' ? $this->CurrentProfile() : $Profile));
    }

    public function ResetBans(): void
    {
        $this->WriteAttributeString('Bans', '{}');
    }

    // ---------- Ablauf ----------

    private function Run(bool $Force): void
    {
        if (!IPS_SemaphoreEnter('MAA_' . $this->InstanceID, $Force ? 30000 : 0)) {
            return;
        }
        try {
            $this->RunLocked($Force);
        } finally {
            IPS_SemaphoreLeave('MAA_' . $this->InstanceID);
        }
    }

    private function RunLocked(bool $Force): void
    {
        $st = $this->GetState();
        $open = $this->IsOpen();
        $prof = $open ? $this->CurrentProfile() : '';

        $catalog = json_decode($this->ReadAttributeString('Catalog'), true) ?: [];
        if ((!$open || count($catalog['items'] ?? []) === 0) && (time() - (int) ($catalog['scanned'] ?? 0)) > $this->ReadPropertyInteger('RescanDays') * 86400) {
            $this->Scan();
        }

        if (!$this->GetValue('Auto')) {
            $this->Render('');
            return;
        }

        if (!$open) {
            if (($st['profile'] ?? '') !== '') {
                foreach ($this->PlayerIDs() as $pid) {
                    $this->MACall('players/cmd/stop', ['player_id' => $pid]);
                    if ($this->ReadPropertyBoolean('PowerOffWhenClosed')) {
                        $this->MACall('players/cmd/power', ['player_id' => $pid, 'powered' => false]);
                    }
                }
                $st['profile'] = '';
                $st['manual'] = false;
                $this->SaveState($st);
            }
            $this->Render('');
            return;
        }

        // Handbetrieb: Ziel-Player gestoppt/pausiert, obwohl die Automatik läuft
        if (($st['profile'] ?? '') === $prof && !($st['manual'] ?? false) && !$Force && !empty($st['started'])
            && (time() - (int) $st['started']) > 120) {
            $stopped = 0;
            $ids = $this->PlayerIDs();
            foreach ($ids as $pid) {
                $p = $this->MACall('players/get', ['player_id' => $pid]);
                if (is_array($p) && ($p['available'] ?? false) && ($p['playback_state'] ?? $p['state'] ?? '') !== 'playing') {
                    $stopped++;
                }
            }
            if ($stopped === count($ids) && $stopped > 0) {
                $st['manual'] = true;
                $this->SaveState($st);
            }
        }

        $newProfile = ($st['profile'] ?? '') !== $prof;
        if ($newProfile) {
            $st['manual'] = false;
            $this->SaveState($st);
        }
        if ($st['manual'] ?? false) {
            $this->Render('');
            return;
        }
        $due = (time() - (int) ($st['started'] ?? 0)) >= $this->ReadPropertyInteger('Rotate') * 60;
        if ($Force || $newProfile || $due) {
            $this->StartPlaylist($prof, $newProfile || $Force);
        } else {
            $this->Render('');
        }
    }

    private function StartPlaylist(string $Profile, bool $SetVolume): void
    {
        $cands = $this->Candidates($Profile);
        if (count($cands) === 0) {
            $this->Render($this->Translate('No matching playlist in catalog – reload catalog'));
            return;
        }
        $pick = null;
        foreach ($cands as $c) {
            if ($c['own_profile']) {
                $pick = $c;
                break;
            }
        }
        if ($pick === null) {
            $top = array_slice($cands, 0, max(1, $this->ReadPropertyInteger('TopN')));
            $pick = $top[array_rand($top)];
        }

        $profile = $this->ProfileByName($Profile);
        $ok = false;
        foreach ($this->PlayerIDs() as $pid) {
            $p = $this->MACall('players/get', ['player_id' => $pid]);
            if (is_array($p) && ($p['power_control'] ?? 'none') !== 'none' && !($p['powered'] ?? false)) {
                $this->MACall('players/cmd/power', ['player_id' => $pid, 'powered' => true]);
            }
            if ($SetVolume && $profile !== null && (int) $profile['Volume'] > 0) {
                $this->MACall('players/cmd/volume_set', ['player_id' => $pid, 'volume_level' => (int) $profile['Volume']]);
            }
            $r = $this->MACall('player_queues/play_media', ['queue_id' => $pid, 'media' => $pick['uri'], 'option' => 'replace', 'shuffle' => true], 30);
            if ($r !== false) {
                $ok = true;
                $this->MACall('players/cmd/repeat', ['player_id' => $pid, 'repeat_mode' => 'all']);
            }
        }
        if (!$ok) {
            $this->Render(sprintf($this->Translate('Playlist "%s" could not be started'), $pick['name']));
            return;
        }
        $st = $this->GetState();
        $hist = array_values(array_filter((array) ($st['history'] ?? []), fn ($u) => $u !== $pick['uri']));
        array_unshift($hist, $pick['uri']);
        $st = array_merge($st, [
            'profile'      => $Profile,
            'playlist'     => $pick['name'],
            'playlist_uri' => $pick['uri'],
            'started'      => time(),
            'manual'       => false,
            'history'      => array_slice($hist, 0, max(0, $this->ReadPropertyInteger('History')))
        ]);
        $this->SaveState($st);
        $this->SetValueIfChanged('Playlist', $pick['name']);
        $this->Render('');
    }

    // ---------- Katalog ----------

    /**
     * Katalog: Playlisten aus der Bibliothek (eigene/gespeicherte) und Suchtreffer zu allen Stichworten der Profile.
     */
    private function Scan(): void
    {
        $providers = $this->ProviderDomains();
        $items = [];
        $add = function (array $pl, bool $own) use (&$items, $providers) {
            $uri = (string) ($pl['uri'] ?? '');
            $name = trim((string) ($pl['name'] ?? ''));
            if ($uri === '' || $name === '' || !$this->ProviderAllowed($pl, $providers)) {
                return;
            }
            if (!isset($items[$uri])) {
                $items[$uri] = ['uri' => $uri, 'name' => $name, 'own' => $own];
            } elseif ($own) {
                $items[$uri]['own'] = true;
            }
        };

        // Bibliothek
        for ($offset = 0; $offset < 1000; $offset += 200) {
            $lib = $this->MACall('music/playlists/library_items', ['limit' => 200, 'offset' => $offset], 30);
            $list = is_array($lib) ? (isset($lib['items']) ? $lib['items'] : $lib) : [];
            foreach ($list as $pl) {
                if (is_array($pl)) {
                    $add($pl, true);
                }
            }
            if (count($list) < 200) {
                break;
            }
        }

        // Suche nach allen Stichworten
        $words = [];
        foreach ($this->Profiles() as $p) {
            foreach (array_keys($this->ParseWords((string) $p['Words'])) as $w) {
                $words[$w] = true;
            }
        }
        foreach (array_keys($words) as $w) {
            $res = $this->MACall('music/search', ['search_query' => $w, 'media_types' => ['playlist'], 'limit' => 25], 30);
            foreach ((array) ($res['playlists'] ?? []) as $pl) {
                if (is_array($pl)) {
                    $add($pl, false);
                }
            }
        }

        if (count($items) > 0) {
            $this->WriteAttributeString('Catalog', json_encode(['scanned' => time(), 'items' => array_values($items)]));
            $this->LogMessage(sprintf('Katalog eingelesen: %d Playlisten', count($items)), KL_MESSAGE);
        } else {
            $this->LogMessage('Katalog leer – Musikquelle in Music Assistant angemeldet?', KL_WARNING);
        }
    }

    private function Candidates(string $Profile): array
    {
        $catalog = json_decode($this->ReadAttributeString('Catalog'), true) ?: [];
        $bans = json_decode($this->ReadAttributeString('Bans'), true) ?: [];
        $hist = (array) ($this->GetState()['history'] ?? []);
        $profile = $this->ProfileByName($Profile);
        $words = $profile !== null ? $this->ParseWords((string) $profile['Words']) : [];
        $prefix = mb_strtolower(trim($this->ReadPropertyString('OwnPrefix')));
        $exclude = $this->ParseList($this->ReadPropertyString('Exclude'));
        $regex = array_filter(array_map('trim', preg_split('/\R/', $this->ReadPropertyString('ExcludeRegex')) ?: []));
        $season = $this->ParseSeason($this->ReadPropertyString('Season'));
        $month = (int) date('n');

        $out = [];
        foreach ((array) ($catalog['items'] ?? []) as $it) {
            $n = mb_strtolower($it['name']);
            if (isset($bans[$it['uri']])) {
                continue;
            }
            $ownProfile = $it['own'] && $prefix !== '' && preg_match('/^' . preg_quote($prefix . ' ' . mb_strtolower($Profile), '/') . '/u', $n);
            if (!$ownProfile) {
                foreach ($exclude as $x) {
                    if (preg_match('/(^|[^\p{L}])' . preg_quote($x, '/') . '([^\p{L}]|$)/u', $n)) {
                        continue 2;
                    }
                }
                foreach ($regex as $rx) {
                    if (@preg_match($rx, $n)) {
                        continue 2;
                    }
                }
                foreach ($season as $w => $months) {
                    if (str_contains($n, $w) && !in_array($month, $months, true)) {
                        continue 2;
                    }
                }
                if (in_array($it['uri'], $hist, true)) {
                    continue;
                }
            }
            $score = 0;
            foreach ($words as $w => $g) {
                if (str_contains($n, $w)) {
                    $score += $g;
                }
            }
            if ($it['own'] && $prefix !== '' && str_starts_with($n, $prefix)) {
                $score += 10;
            }
            if ($ownProfile || $score > 0) {
                $out[] = $it + ['score' => $score + ($ownProfile ? 100 : 0), 'own_profile' => (bool) $ownProfile];
            }
        }
        usort($out, fn ($a, $b) => $b['score'] <=> $a['score']);
        return $out;
    }

    // ---------- Zeiten / Profile ----------

    private function IsOpen(?int $ts = null): bool
    {
        $ts = $ts ?? time();
        $day = (int) date('N', $ts);
        $hm = date('H:i', $ts);
        foreach ((array) json_decode($this->ReadPropertyString('Hours'), true) as $h) {
            if ((int) ($h['Day'] ?? 0) === $day && $hm >= (string) $h['From'] && $hm < (string) $h['To']) {
                return true;
            }
        }
        return false;
    }

    private function CurrentProfile(?int $ts = null): string
    {
        $ts = $ts ?? time();
        $day = (int) date('N', $ts);
        $hm = date('H:i', $ts);
        $profiles = $this->Profiles();
        foreach ($profiles as $p) {
            $days = array_map('intval', $this->ParseList((string) $p['Days']));
            if (in_array($day, $days, true) && $hm >= (string) $p['From'] && $hm < (string) $p['To']) {
                return (string) $p['Name'];
            }
        }
        return (string) ($profiles[0]['Name'] ?? '');
    }

    private function NextOpen(): string
    {
        $days = ['', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
        $t0 = time() - (time() % 600) + 600;
        for ($i = 0; $i < 8 * 24 * 6; $i++) {
            $t = $t0 + $i * 600;
            if ($this->IsOpen($t)) {
                return $days[(int) date('N', $t)] . ' ' . date('H:i', $t);
            }
        }
        return '–';
    }

    private function Profiles(): array
    {
        return array_values(array_filter((array) json_decode($this->ReadPropertyString('Profiles'), true), fn ($p) => !empty($p['Name'])));
    }

    private function ProfileByName(string $Name): ?array
    {
        foreach ($this->Profiles() as $p) {
            if ((string) $p['Name'] === $Name) {
                return $p;
            }
        }
        return null;
    }

    private function PlayerIDs(): array
    {
        $ids = [];
        foreach ((array) json_decode($this->ReadPropertyString('Players'), true) as $row) {
            if (!empty($row['PlayerID'])) {
                $ids[] = (string) $row['PlayerID'];
            }
        }
        return $ids;
    }

    // ---------- Parser ----------

    private function ParseList(string $Text): array
    {
        return array_values(array_filter(array_map(fn ($s) => mb_strtolower(trim($s)), preg_split('/[,;\n]/', $Text) ?: []), fn ($s) => $s !== ''));
    }

    private function ParseWords(string $Text): array
    {
        $out = [];
        foreach (preg_split('/[,\n]/', $Text) ?: [] as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/^(.*?):\s*(-?\d+)\s*$/u', $part, $m)) {
                $out[mb_strtolower(trim($m[1]))] = (int) $m[2];
            } else {
                $out[mb_strtolower($part)] = 1;
            }
        }
        return $out;
    }

    private function ParseSeason(string $Text): array
    {
        $out = [];
        foreach (preg_split('/[;\n]/', $Text) ?: [] as $part) {
            if (preg_match('/^\s*(.+?)\s*:\s*([\d,\s]+)$/u', $part, $m)) {
                $out[mb_strtolower($m[1])] = array_map('intval', array_filter(array_map('trim', explode(',', $m[2])), 'strlen'));
            }
        }
        return $out;
    }

    private function ProviderDomains(): array
    {
        return $this->ParseList($this->ReadPropertyString('Providers'));
    }

    private function ProviderAllowed(array $Item, array $Domains): bool
    {
        if (count($Domains) === 0) {
            return true;
        }
        foreach ((array) ($Item['provider_mappings'] ?? []) as $m) {
            if (in_array(mb_strtolower((string) ($m['provider_domain'] ?? '')), $Domains, true)) {
                return true;
            }
        }
        $uri = mb_strtolower((string) ($Item['uri'] ?? ''));
        foreach ($Domains as $d) {
            if (str_starts_with($uri, $d)) {
                return true;
            }
        }
        return false;
    }

    // ---------- Anzeige ----------

    private function Render(string $Message): void
    {
        $st = $this->GetState();
        $auto = (bool) $this->GetValue('Auto');
        $open = $this->IsOpen();
        $prof = $open ? $this->CurrentProfile() : '';
        if (!$auto) {
            $state = $this->Translate('Automatic off');
        } elseif (!$open) {
            $state = $this->Translate('Closed');
        } elseif ($st['manual'] ?? false) {
            $state = $this->Translate('Manual override');
        } else {
            $state = $this->Translate('Automatic active');
        }
        $this->SetValueIfChanged('Status', $state);
        $this->SetValueIfChanged('Profile', $prof !== '' ? $prof : '–');
        if (!$open) {
            $this->SetValueIfChanged('Playlist', '');
        }

        $next = '';
        if ($auto && $open && !($st['manual'] ?? false) && !empty($st['started'])) {
            $next = sprintf($this->Translate('Next playlist approx. %s'), date('H:i', (int) $st['started'] + $this->ReadPropertyInteger('Rotate') * 60));
        } elseif ($auto && $open && ($st['manual'] ?? false)) {
            $next = $this->Translate('Automatic resumes with the next day profile');
        } elseif (!$open) {
            $next = sprintf($this->Translate('Start %s'), $this->NextOpen());
        }
        $catalog = json_decode($this->ReadAttributeString('Catalog'), true) ?: [];
        $h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $pl = $auto && $open ? (string) ($st['playlist'] ?? '') : '';
        $active = $state === $this->Translate('Automatic active');

        $html = '<style>.maa{height:100%;display:flex;flex-direction:column;justify-content:center;gap:2.2vmin;padding:2vmin}'
            . '.maa .bd{align-self:flex-start;font-size:clamp(9px,3.4vmin,13px);font-weight:600;letter-spacing:.06em;text-transform:uppercase;'
            . 'color:#fff;background:' . ($active ? '#FD5D05' : '#8a8a8a') . ';padding:.35em .8em;border-radius:99px}'
            . '.maa .pr{font-size:clamp(11px,4.5vmin,16px);opacity:.8}'
            . '.maa .l1{font-weight:700;font-size:clamp(15px,8vmin,30px);line-height:1.15;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}'
            . '.maa .l2{font-size:clamp(10px,4vmin,14px);opacity:.7}.maa .m{font-size:clamp(10px,3.8vmin,13px);color:#FD5D05}</style>'
            . '<div class="maa"><span class="bd">' . $h($state) . '</span>'
            . ($prof !== '' ? '<div class="pr">' . $h($this->Translate('Day profile')) . ': ' . $h($prof) . '</div>' : '')
            . '<div class="l1">' . $h($pl !== '' ? $pl : ($open ? $this->Translate('Background music') : $this->Translate('Closed'))) . '</div>'
            . '<div class="l2">' . $h($next) . ($next !== '' ? ' · ' : '') . sprintf($this->Translate('%d playlists in catalog'), count($catalog['items'] ?? [])) . '</div>'
            . ($Message !== '' ? '<div class="m">' . $h($Message) . '</div>' : '')
            . '</div>';
        $this->SetValueIfChanged('Card', $html);
    }

    // ---------- Hilfen ----------

    private function Button(string $Icon, string $Caption): array
    {
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION, 'ICON' => $Icon, 'LAYOUT' => 0, 'DISPLAY' => 2,
            'OPTIONS'      => json_encode([['Value' => 0, 'Caption' => $Caption, 'IconActive' => true, 'IconValue' => $Icon, 'Color' => -1]])
        ];
    }

    private function GetState(): array
    {
        return json_decode($this->ReadAttributeString('State'), true) ?: [];
    }

    private function SaveState(array $State): void
    {
        $this->WriteAttributeString('State', json_encode($State));
    }

    private function SetValueIfChanged(string $Ident, mixed $Value): void
    {
        $id = @IPS_GetObjectIDByIdent($Ident, $this->InstanceID);
        if (is_int($id) && $id > 0 && GetValue($id) !== $Value) {
            $this->SetValue($Ident, $Value);
        }
    }
}
