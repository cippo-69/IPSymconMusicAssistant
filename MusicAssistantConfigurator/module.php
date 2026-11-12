<?php

declare(strict_types=1);

/**
 * Music Assistant Konfigurator: listet die Ausgabegeräte aus Music Assistant und legt Player-Instanzen an.
 */
class MusicAssistantConfigurator extends IPSModuleStrict
{
    private const PARENT_TX = '{AB388AAD-6FDC-4C59-BED0-185716F50E2F}';
    private const PLAYER_MODULE = '{D1F3E973-E158-49A7-9368-2329192220DB}';

    public function Create(): void
    {
        parent::Create();
    }

    public function GetCompatibleParents(): string
    {
        return json_encode(['type' => 'connect', 'moduleIDs' => ['{2A922FEF-DCDA-49B9-ABD0-2F074655BADC}']]);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->SetReceiveDataFilter('.*"Event":"none".*');
    }

    public function ReceiveData(string $JSONString): string
    {
        return '';
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $values = [];

        $existing = [];
        foreach (IPS_GetInstanceListByModuleID(self::PLAYER_MODULE) as $id) {
            $existing[(string) IPS_GetProperty($id, 'PlayerID')] = $id;
        }

        $players = [];
        if ($this->HasActiveParent()) {
            $resp = json_decode($this->SendDataToParent(json_encode(['DataID' => self::PARENT_TX, 'Command' => 'players/all', 'Args' => []])), true);
            $players = is_array($resp['result'] ?? null) ? $resp['result'] : [];
        }

        foreach ($players as $p) {
            $pid = (string) ($p['player_id'] ?? '');
            if ($pid === '' || !empty($p['hide_in_ui']) || !empty($p['hide_player_in_ui'])) {
                continue;
            }
            $name = (string) ($p['display_name'] ?? $p['name'] ?? $pid);
            $row = [
                'name'     => $name,
                'model'    => trim(($p['device_info']['manufacturer'] ?? '') . ' ' . ($p['device_info']['model'] ?? '')),
                'address'  => (string) ($p['device_info']['ip_address'] ?? ''),
                'provider' => (string) ($p['provider'] ?? ''),
                'playerid' => $pid,
                'create'   => [
                    'moduleID'      => self::PLAYER_MODULE,
                    'configuration' => ['PlayerID' => $pid],
                    'name'          => $name
                ]
            ];
            if (isset($existing[$pid])) {
                $row['instanceID'] = $existing[$pid];
                unset($existing[$pid]);
            }
            $values[] = $row;
        }
        // Instanzen ohne passenden Player in Music Assistant
        foreach ($existing as $pid => $id) {
            $values[] = [
                'name'       => IPS_GetName($id),
                'model'      => '',
                'address'    => '',
                'provider'   => '',
                'playerid'   => $pid,
                'instanceID' => $id
            ];
        }

        $form['actions'][0]['values'] = $values;
        return json_encode($form);
    }
}
