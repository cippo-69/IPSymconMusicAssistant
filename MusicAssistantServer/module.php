<?php

declare(strict_types=1);

/**
 * Music Assistant Server (Splitter)
 *
 * Verbindet IP-Symcon über den WebSocket Client mit der Music-Assistant-API (ws://<host>:8095/ws),
 * meldet sich mit einem Token an, leitet Befehle der Kinder weiter und verteilt Ereignisse an die Kinder.
 */
class MusicAssistantServer extends IPSModuleStrict
{
    private const WS_CLIENT = '{D68FD31F-0E90-7019-F16C-1949BD3079EF}';
    private const IO_TX = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';
    private const CHILD_RX = '{062AF688-8507-4F5C-9B9C-37E9BC0B6CEA}';
    private const CHILD_TX = '{AB388AAD-6FDC-4C59-BED0-185716F50E2F}';

    private const STATUS_NO_CONFIG = 104;
    private const STATUS_AUTH_FAILED = 201;
    private const STATUS_NOT_CONNECTED = 202;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 8095);
        $this->RegisterPropertyString('Token', '');
        $this->RegisterPropertyInteger('Timeout', 10);

        $this->RegisterAttributeString('ServerVersion', '');
        $this->RegisterAttributeString('BaseURL', '');

        $this->RegisterTimer('Watchdog', 0, 'MAS_Watchdog($_IPS[\'TARGET\']);');
    }

    public function GetCompatibleParents(): string
    {
        return json_encode(['type' => 'require', 'moduleIDs' => [self::WS_CLIENT]]);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetBuffer('Authenticated', '0');

        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        $parentID = $this->GetParentID();
        if ($parentID > 0) {
            $this->RegisterMessage($parentID, IM_CHANGESTATUS);
        }

        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '' || $this->ReadPropertyString('Token') === '') {
            $this->SetStatus(self::STATUS_NO_CONFIG);
            $this->SetTimerInterval('Watchdog', 0);
            return;
        }
        $this->WriteAttributeString('BaseURL', 'http://' . $host . ':' . $this->ReadPropertyInteger('Port'));
        $this->SyncParent();
        $this->SetTimerInterval('Watchdog', 60 * 1000);
        $this->SetStatus(self::STATUS_NOT_CONNECTED);
        if ($this->HasActiveParent()) {
            // Verbindung besteht schon (z. B. nach Modul-Update): direkt anmelden
            $this->SendRaw(['command' => 'auth', 'args' => ['token' => $this->ReadPropertyString('Token'), 'device_name' => 'IP-Symcon'], 'message_id' => 'auth']);
        }
        $this->SetSummary($host . ':' . $this->ReadPropertyInteger('Port'));
    }

    public function GetConfigurationForParent(): string
    {
        $host = trim($this->ReadPropertyString('Host'));
        return json_encode([
            'URL'               => $host === '' ? '' : 'ws://' . $host . ':' . $this->ReadPropertyInteger('Port') . '/ws',
            'VerifyCertificate' => false,
            'Type'              => 0,
            'Headers'           => '[]',
            'Active'            => $host !== '' && $this->ReadPropertyString('Token') !== ''
        ]);
    }

    /**
     * Konfiguration des WebSocket Clients angleichen (URL, aktiv). Symcon übernimmt sie nicht in jedem Fall selbst.
     */
    private function SyncParent(): void
    {
        $parentID = $this->GetParentID();
        if ($parentID === 0 || !IPS_InstanceExists($parentID)) {
            return;
        }
        $wanted = json_decode($this->GetConfigurationForParent(), true);
        $current = json_decode(IPS_GetConfiguration($parentID), true);
        $changed = false;
        foreach ($wanted as $key => $value) {
            if (array_key_exists($key, $current) && $current[$key] !== $value) {
                IPS_SetProperty($parentID, $key, $value);
                $changed = true;
            }
        }
        if ($changed) {
            IPS_ApplyChanges($parentID);
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->ApplyChanges();
                break;
            case IM_CHANGESTATUS:
                if ($SenderID === $this->GetParentID() && $Data[0] !== IS_ACTIVE) {
                    $this->SetBuffer('Authenticated', '0');
                    if ($this->GetStatus() === IS_ACTIVE) {
                        $this->SetStatus(self::STATUS_NOT_CONNECTED);
                        $this->SendToChildren('connection', '', ['connected' => false]);
                    }
                }
                break;
        }
    }

    /**
     * Prüft regelmäßig, ob die Verbindung steht; ohne Anmeldung wird die Verbindung neu aufgebaut.
     */
    public function Watchdog(): void
    {
        $parentID = $this->GetParentID();
        if ($parentID === 0) {
            return;
        }
        if ($this->GetBuffer('Authenticated') === '1' && IPS_GetInstance($parentID)['InstanceStatus'] === IS_ACTIVE) {
            return;
        }
        $this->SendDebug('Watchdog', 'nicht angemeldet → Verbindung neu aufbauen', 0);
        $this->SetBuffer('Authenticated', '0');
        if (IPS_GetInstance($parentID)['InstanceStatus'] === IS_ACTIVE) {
            IPS_SetProperty($parentID, 'Active', false);
            IPS_ApplyChanges($parentID);
        }
        IPS_SetProperty($parentID, 'Active', true);
        IPS_ApplyChanges($parentID);
    }

    // ---------- Empfang vom WebSocket ----------

    public function ReceiveData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        $text = hex2bin($data['Buffer'] ?? '');
        if ($text === false || $text === '') {
            return '';
        }
        $msg = json_decode($text, true);
        if (!is_array($msg)) {
            $this->SendDebug('Empfang (kein JSON)', $text, 0);
            return '';
        }

        // 1. Server-Info nach Verbindungsaufbau → anmelden
        if (isset($msg['server_id']) && !isset($msg['message_id']) && !isset($msg['event'])) {
            $this->WriteAttributeString('ServerVersion', (string) ($msg['server_version'] ?? ''));
            if (!empty($msg['base_url'])) {
                $this->WriteAttributeString('BaseURL', (string) $msg['base_url']);
            }
            $this->SendDebug('Server', 'Version ' . ($msg['server_version'] ?? '?') . ' – Anmeldung', 0);
            $this->SendRaw([
                'command'    => 'auth',
                'args'       => ['token' => $this->ReadPropertyString('Token'), 'device_name' => 'IP-Symcon'],
                'message_id' => 'auth'
            ]);
            return '';
        }

        // 2. Antwort auf einen Befehl
        if (isset($msg['message_id'])) {
            $id = (string) $msg['message_id'];
            if ($id === 'auth') {
                if (isset($msg['error_code'])) {
                    $this->SendDebug('Anmeldung', 'fehlgeschlagen: ' . ($msg['details'] ?? ''), 0);
                    $this->SetBuffer('Authenticated', '0');
                    $this->SetStatus(self::STATUS_AUTH_FAILED);
                } else {
                    $this->SendDebug('Anmeldung', 'ok', 0);
                    $this->SetBuffer('Authenticated', '1');
                    $this->SetStatus(IS_ACTIVE);
                    $this->SendToChildren('connection', '', ['connected' => true]);
                }
                return '';
            }
            if (!empty($msg['partial'])) {
                $prev = json_decode($this->GetBuffer('P_' . $id) ?: '[]', true);
                $this->SetBuffer('P_' . $id, json_encode(array_merge($prev, (array) ($msg['result'] ?? []))));
                return '';
            }
            $partial = $this->GetBuffer('P_' . $id);
            if ($partial !== '') {
                $msg['result'] = array_merge(json_decode($partial, true), (array) ($msg['result'] ?? []));
                $this->SetBuffer('P_' . $id, '');
            }
            $this->SetBuffer('R_' . $id, json_encode($msg));
            return '';
        }

        // 3. Ereignis → an alle Kinder
        if (isset($msg['event'])) {
            $event = (string) $msg['event'];
            if (in_array($event, ['player_updated', 'player_added', 'player_removed', 'queue_updated', 'queue_time_updated', 'player_config_updated'], true)) {
                $this->SendToChildren($event, (string) ($msg['object_id'] ?? ''), $msg['data'] ?? null);
            }
        }
        return '';
    }

    // ---------- Befehle der Kinder ----------

    public function ForwardData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        $command = (string) ($data['Command'] ?? '');
        if ($command === '') {
            return json_encode(['error' => 'Befehl fehlt']);
        }
        $special = $this->HandleLocal($command, (array) ($data['Args'] ?? []));
        if ($special !== null) {
            return json_encode(['result' => $special]);
        }
        $result = $this->Request($command, (array) ($data['Args'] ?? []), (int) ($data['Timeout'] ?? $this->ReadPropertyInteger('Timeout')));
        if ($result === null) {
            return json_encode(['error' => 'keine Antwort von Music Assistant']);
        }
        if (isset($result['error_code'])) {
            return json_encode(['error' => (string) ($result['details'] ?? $result['error_code'])]);
        }
        return json_encode(['result' => $result['result'] ?? null]);
    }

    /**
     * Öffentlicher Befehl für Scripte: MAS_SendCommand($id, 'players/all', '{}')
     */
    public function SendCommand(string $Command, string $ArgsJSON): string
    {
        $args = json_decode($ArgsJSON === '' ? '{}' : $ArgsJSON, true);
        $result = $this->Request($Command, is_array($args) ? $args : [], $this->ReadPropertyInteger('Timeout'));
        return json_encode($result);
    }

    public function GetServerInfo(): string
    {
        return json_encode([
            'connected' => $this->GetBuffer('Authenticated') === '1',
            'version'   => $this->ReadAttributeString('ServerVersion'),
            'base_url'  => $this->ReadAttributeString('BaseURL')
        ]);
    }

    // ---------- intern ----------

    private function HandleLocal(string $Command, array $Args): mixed
    {
        switch ($Command) {
            case 'local/server_info':
                return json_decode($this->GetServerInfo(), true);
            case 'local/image_url':
                // Bild-URL über den Bildproxy von Music Assistant (für Kacheln im LAN)
                $path = (string) ($Args['path'] ?? '');
                if ($path === '') {
                    return '';
                }
                return $this->ReadAttributeString('BaseURL') . '/imageproxy?path=' . rawurlencode($path)
                    . '&provider=' . rawurlencode((string) ($Args['provider'] ?? 'builtin')) . '&size=' . (int) ($Args['size'] ?? 256);
        }
        return null;
    }

    /**
     * Befehl an Music Assistant über eine eigene, kurze WebSocket-Verbindung (Anmeldung, Befehl, Antwort, Ende).
     * Grund: Symcon führt Aufrufe derselben Instanz nacheinander aus – auf eine Antwort über den
     * dauerhaften WebSocket Client zu warten, würde ReceiveData blockieren. Ereignisse kommen weiter
     * über den dauerhaften WebSocket Client.
     */
    private function Request(string $Command, array $Args, int $Timeout): ?array
    {
        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '' || $this->ReadPropertyString('Token') === '') {
            return null;
        }
        $Timeout = max(2, $Timeout);
        $end = microtime(true) + $Timeout;
        $errno = 0;
        $errstr = '';
        $s = @stream_socket_client('tcp://' . $host . ':' . $this->ReadPropertyInteger('Port'), $errno, $errstr, 3);
        if (!$s) {
            $this->SendDebug('Befehl', $Command . ' – keine Verbindung: ' . $errstr, 0);
            return null;
        }
        try {
            stream_set_timeout($s, 1);
            $key = base64_encode(random_bytes(16));
            fwrite($s, "GET /ws HTTP/1.1\r\nHost: " . $host . ':' . $this->ReadPropertyInteger('Port') . "\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
                . "Sec-WebSocket-Key: " . $key . "\r\nSec-WebSocket-Version: 13\r\n\r\n");
            $head = '';
            while (!str_contains($head, "\r\n\r\n") && microtime(true) < $end) {
                $c = fread($s, 1);
                if ($c === false || $c === '') {
                    if (feof($s)) {
                        break;
                    }
                    continue;
                }
                $head .= $c;
            }
            if (!str_contains($head, ' 101 ')) {
                $this->SendDebug('Befehl', $Command . ' – Handshake fehlgeschlagen: ' . strtok($head, "\r\n"), 0);
                return null;
            }
            // 1. Server-Info abwarten, 2. anmelden, 3. Befehl
            $info = $this->WSRead($s, $end);
            if ($info === null) {
                return null;
            }
            $this->WSWrite($s, json_encode(['command' => 'auth', 'args' => ['token' => $this->ReadPropertyString('Token'), 'device_name' => 'IP-Symcon'], 'message_id' => 'a']));
            $auth = $this->WSWaitFor($s, 'a', $end);
            if ($auth === null || isset($auth['error_code'])) {
                $this->SendDebug('Befehl', $Command . ' – Anmeldung fehlgeschlagen', 0);
                return null;
            }
            $payload = ['command' => $Command, 'message_id' => 'c'];
            if (count($Args) > 0) {
                $payload['args'] = $Args;
            }
            $this->SendDebug('Befehl', json_encode($payload), 0);
            $this->WSWrite($s, json_encode($payload));
            $resp = $this->WSWaitFor($s, 'c', $end);
            if ($resp === null) {
                $this->SendDebug('Befehl', $Command . ' – Zeitüberschreitung', 0);
            }
            return $resp;
        } finally {
            @fwrite($s, chr(0x88) . chr(0x80) . random_bytes(4));
            fclose($s);
        }
    }

    /** Wartet auf die Antwort mit der message_id; Teilantworten (partial) werden zusammengeführt. */
    private function WSWaitFor($s, string $Id, float $End): ?array
    {
        $collected = null;
        while (microtime(true) < $End) {
            $msg = $this->WSRead($s, $End);
            if ($msg === null) {
                return null;
            }
            if ((string) ($msg['message_id'] ?? '') !== $Id) {
                continue; // Ereignisse u. a. ignorieren
            }
            if (!empty($msg['partial'])) {
                $collected = array_merge($collected ?? [], (array) ($msg['result'] ?? []));
                continue;
            }
            if ($collected !== null) {
                $msg['result'] = array_merge($collected, (array) ($msg['result'] ?? []));
            }
            return $msg;
        }
        return null;
    }

    /** Liest eine vollständige Text-Nachricht (inkl. Fragmenten) und dekodiert JSON. */
    private function WSRead($s, float $End): ?array
    {
        $data = '';
        while (microtime(true) < $End) {
            $h = $this->WSReadN($s, 2, $End);
            if ($h === null) {
                return null;
            }
            $fin = (ord($h[0]) & 0x80) !== 0;
            $op = ord($h[0]) & 0x0F;
            $len = ord($h[1]) & 0x7F;
            if ($len === 126) {
                $len = unpack('n', (string) $this->WSReadN($s, 2, $End))[1];
            } elseif ($len === 127) {
                $len = unpack('J', (string) $this->WSReadN($s, 8, $End))[1];
            }
            $mask = (ord($h[1]) & 0x80) ? $this->WSReadN($s, 4, $End) : null;
            $p = $len > 0 ? $this->WSReadN($s, $len, $End) : '';
            if ($p === null) {
                return null;
            }
            if ($mask !== null) {
                for ($i = 0; $i < strlen($p); $i++) {
                    $p[$i] = $p[$i] ^ $mask[$i % 4];
                }
            }
            if ($op === 0x9) {
                $this->WSWrite($s, $p, 0xA);
                continue;
            }
            if ($op === 0x8) {
                return null;
            }
            if ($op === 0xA) {
                continue;
            }
            $data .= $p;
            if ($fin) {
                $msg = json_decode($data, true);
                return is_array($msg) ? $msg : ['raw' => $data];
            }
        }
        return null;
    }

    private function WSReadN($s, int $n, float $End): ?string
    {
        $b = '';
        while (strlen($b) < $n) {
            if (microtime(true) > $End) {
                return null;
            }
            $c = fread($s, $n - strlen($b));
            if ($c === false || $c === '') {
                if (feof($s)) {
                    return null;
                }
                continue;
            }
            $b .= $c;
        }
        return $b;
    }

    private function WSWrite($s, string $Text, int $Op = 0x1): void
    {
        $len = strlen($Text);
        $h = chr(0x80 | $Op);
        if ($len < 126) {
            $h .= chr(0x80 | $len);
        } elseif ($len < 65536) {
            $h .= chr(0x80 | 126) . pack('n', $len);
        } else {
            $h .= chr(0x80 | 127) . pack('J', $len);
        }
        $mask = random_bytes(4);
        $m = '';
        for ($i = 0; $i < $len; $i++) {
            $m .= $Text[$i] ^ $mask[$i % 4];
        }
        fwrite($s, $h . $mask . $m);
    }

    private function SendRaw(array $Payload): bool
    {
        if (!$this->HasActiveParent()) {
            return false;
        }
        $json = json_encode($Payload);
        $debug = $Payload['command'] === 'auth' ? '{"command":"auth", …}' : $json;
        $this->SendDebug('Senden', $debug, 0);
        try {
            $this->SendDataToParent(json_encode(['DataID' => self::IO_TX, 'Buffer' => bin2hex($json)]));
        } catch (Throwable $e) {
            $this->SendDebug('Senden', 'Fehler: ' . $e->getMessage(), 0);
            return false;
        }
        return true;
    }

    private function SendToChildren(string $Event, string $ObjectID, mixed $Data): void
    {
        $this->SendDataToChildren(json_encode([
            'DataID'   => self::CHILD_RX,
            'Event'    => $Event,
            'ObjectID' => $ObjectID,
            'Data'     => $Data
        ]));
    }

    private function GetParentID(): int
    {
        return (int) IPS_GetInstance($this->InstanceID)['ConnectionID'];
    }
}
