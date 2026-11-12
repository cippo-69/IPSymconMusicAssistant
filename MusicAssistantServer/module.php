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
        $this->SetTimerInterval('Watchdog', 60 * 1000);
        $this->SetStatus(self::STATUS_NOT_CONNECTED);
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
        if ($this->GetBuffer('Authenticated') === '1') {
            // Lebenszeichen: leichte Abfrage; scheitert sie, Verbindung neu aufbauen
            $result = $this->Request('info', [], 5);
            if ($result !== null) {
                return;
            }
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

    private function Request(string $Command, array $Args, int $Timeout): ?array
    {
        if ($this->GetBuffer('Authenticated') !== '1' && $Command !== 'info') {
            $this->SendDebug('Befehl', $Command . ' – nicht angemeldet', 0);
            return null;
        }
        $id = bin2hex(random_bytes(6));
        $payload = ['command' => $Command, 'message_id' => $id];
        if (count($Args) > 0) {
            $payload['args'] = $Args;
        }
        if (!$this->SendRaw($payload)) {
            return null;
        }
        $end = microtime(true) + max(1, $Timeout);
        while (microtime(true) < $end) {
            $resp = $this->GetBuffer('R_' . $id);
            if ($resp !== '') {
                $this->SetBuffer('R_' . $id, '');
                return json_decode($resp, true);
            }
            IPS_Sleep(25);
        }
        $this->SetBuffer('P_' . $id, '');
        $this->SendDebug('Befehl', $Command . ' – Zeitüberschreitung', 0);
        return null;
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
