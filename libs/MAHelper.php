<?php

declare(strict_types=1);

/**
 * Gemeinsame Hilfsfunktionen für Module, die über den Music-Assistant-Server sprechen.
 */
trait MAHelper
{
    private function MACall(string $Command, array $Args = [], int $Timeout = 0): mixed
    {
        if (!$this->HasActiveParent()) {
            $this->SendDebug('Befehl', $Command . ' – Server nicht verbunden', 0);
            return false;
        }
        $payload = ['DataID' => '{AB388AAD-6FDC-4C59-BED0-185716F50E2F}', 'Command' => $Command, 'Args' => $Args];
        if ($Timeout > 0) {
            $payload['Timeout'] = $Timeout;
        }
        $data = json_decode($this->SendDataToParent(json_encode($payload)), true);
        if (!is_array($data) || isset($data['error'])) {
            $this->SendDebug('Befehl', $Command . ' – ' . ($data['error'] ?? 'ungültige Antwort'), 0);
            return false;
        }
        return $data['result'] ?? true;
    }

    /**
     * Handbedienung an alle Automatik-Instanzen melden (pausiert die Automatik bis zum nächsten Tagesprofil).
     */
    private function MANotifyManual(string $PlayerID): void
    {
        foreach (IPS_GetInstanceListByModuleID('{3C6CF2E5-4FE0-4883-8104-C2B427C1CDFE}') as $id) {
            @MAA_NotifyManual($id, $PlayerID);
        }
    }

    /**
     * Klangregelung (DSP tone_control + output_gain) eines Players ändern.
     * $Change: enabled, bass_level, mid_level, treble_level, output_gain
     */
    private function MASetTone(string $PlayerID, array $Change): array|false
    {
        $dsp = $this->MACall('config/players/dsp/get', ['player_id' => $PlayerID]);
        if (!is_array($dsp)) {
            return false;
        }
        $filters = (array) ($dsp['filters'] ?? []);
        $index = null;
        foreach ($filters as $i => $f) {
            if (($f['type'] ?? '') === 'tone_control') {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            $filters[] = ['type' => 'tone_control', 'enabled' => true, 'bass_level' => 0.0, 'mid_level' => 0.0, 'treble_level' => 0.0];
            $index = count($filters) - 1;
        }
        if (array_key_exists('enabled', $Change)) {
            $filters[$index]['enabled'] = (bool) $Change['enabled'];
            if ($Change['enabled']) {
                $dsp['enabled'] = true;
            }
        }
        foreach (['bass_level', 'mid_level', 'treble_level'] as $key) {
            if (array_key_exists($key, $Change)) {
                $filters[$index][$key] = max(-10.0, min(10.0, (float) $Change[$key]));
                $filters[$index]['enabled'] = true;
                $dsp['enabled'] = true;
            }
        }
        if (array_key_exists('output_gain', $Change)) {
            $dsp['output_gain'] = max(-15.0, min(15.0, (float) $Change['output_gain']));
            $dsp['enabled'] = true;
        }
        $dsp['filters'] = array_values($filters);
        if ($this->MACall('config/players/dsp/save', ['player_id' => $PlayerID, 'config' => $dsp]) === false) {
            return false;
        }
        return $dsp;
    }

    private function MAToneValues(array $Dsp): array
    {
        $tone = [];
        foreach ((array) ($Dsp['filters'] ?? []) as $f) {
            if (($f['type'] ?? '') === 'tone_control') {
                $tone = $f;
                break;
            }
        }
        return [
            'enabled' => (bool) ($Dsp['enabled'] ?? false) && (bool) ($tone['enabled'] ?? false),
            'bass'    => (float) ($tone['bass_level'] ?? 0),
            'mid'     => (float) ($tone['mid_level'] ?? 0),
            'treble'  => (float) ($tone['treble_level'] ?? 0),
            'gain'    => (float) ($Dsp['output_gain'] ?? 0)
        ];
    }

    /**
     * Bild-URL eines Medienobjekts (Suchergebnis). Direkt erreichbare Bilder unverändert, sonst über den Bildproxy.
     */
    private function MAImage(array $Item, string $BaseURL, int $Size = 200): string
    {
        $images = (array) ($Item['metadata']['images'] ?? []);
        if (isset($Item['image']) && is_array($Item['image'])) {
            array_unshift($images, $Item['image']);
        }
        foreach ($images as $img) {
            if (!is_array($img) || ($img['type'] ?? 'thumb') !== 'thumb' || empty($img['path'])) {
                continue;
            }
            $path = (string) $img['path'];
            if (!empty($img['remotely_accessible']) && preg_match('#^https?://#', $path)) {
                return $path;
            }
            if ($BaseURL === '') {
                return '';
            }
            return $BaseURL . '/imageproxy?path=' . rawurlencode($path) . '&provider=' . rawurlencode((string) ($img['provider'] ?? 'builtin')) . '&size=' . $Size;
        }
        return '';
    }
}
