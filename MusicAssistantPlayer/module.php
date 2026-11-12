<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/MAHelper.php';

/**
 * Music Assistant Player
 *
 * Ein Ausgabegerät aus Music Assistant: Wiedergabe, Lautstärke, Stumm, Ein/Aus, aktueller Titel
 * und Klangregelung über den DSP von Music Assistant (Bass, Mitten, Höhen, Verstärkung).
 */
class MusicAssistantPlayer extends IPSModuleStrict
{
    use MAHelper;

    private const PARENT_TX = '{AB388AAD-6FDC-4C59-BED0-185716F50E2F}';

    private const CONTROL_PREVIOUS = 0;
    private const CONTROL_STOP = 1;
    private const CONTROL_PLAY = 2;
    private const CONTROL_PAUSE = 3;
    private const CONTROL_NEXT = 4;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('PlayerID', '');
        $this->RegisterPropertyBoolean('ShowTone', true);

        $this->RegisterAttributeString('Player', '{}');
        $this->RegisterAttributeString('DSP', '{}');

        $this->ConnectParent('{2A922FEF-DCDA-49B9-ABD0-2F074655BADC}');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->RegisterVariableInteger('Control', $this->Translate('Playback'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS'      => json_encode([
                ['Value' => self::CONTROL_PREVIOUS, 'Caption' => $this->Translate('Previous'), 'IconActive' => true, 'IconValue' => 'backward-step', 'Color' => -1],
                ['Value' => self::CONTROL_STOP, 'Caption' => $this->Translate('Stop'), 'IconActive' => true, 'IconValue' => 'stop', 'Color' => -1],
                ['Value' => self::CONTROL_PLAY, 'Caption' => $this->Translate('Play'), 'IconActive' => true, 'IconValue' => 'play', 'Color' => -1],
                ['Value' => self::CONTROL_PAUSE, 'Caption' => $this->Translate('Pause'), 'IconActive' => true, 'IconValue' => 'pause', 'Color' => -1],
                ['Value' => self::CONTROL_NEXT, 'Caption' => $this->Translate('Next'), 'IconActive' => true, 'IconValue' => 'forward-step', 'Color' => -1]
            ])
        ], 10);
        $this->EnableAction('Control');

        $this->RegisterVariableInteger('Volume', $this->Translate('Volume'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER, 'MIN' => 0, 'MAX' => 100, 'STEP_SIZE' => 1, 'SUFFIX' => ' %'
        ], 20);
        $this->EnableAction('Volume');

        $this->RegisterVariableBoolean('Mute', $this->Translate('Mute'), ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH], 30);
        $this->EnableAction('Mute');

        $this->RegisterVariableString('Title', $this->Translate('Title'), ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION], 40);
        $this->RegisterVariableString('Artist', $this->Translate('Artist'), ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION], 41);
        $this->RegisterVariableString('Album', $this->Translate('Album'), ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION], 42);
        $this->RegisterVariableString('Cover', $this->Translate('Cover URL'), ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION], 43);
        $this->RegisterVariableBoolean('Available', $this->Translate('Available'), ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION], 90);

        if ($this->ReadPropertyBoolean('ShowTone')) {
            $this->RegisterVariableBoolean('ToneEnabled', $this->Translate('Tone control'), ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH], 50);
            $this->EnableAction('ToneEnabled');
            foreach (['Bass' => 51, 'Mid' => 52, 'Treble' => 53] as $ident => $pos) {
                $this->RegisterVariableFloat($ident, $this->Translate($ident), [
                    'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER, 'MIN' => -10, 'MAX' => 10, 'STEP_SIZE' => 0.5, 'DIGITS' => 1, 'SUFFIX' => ' dB'
                ], $pos);
                $this->EnableAction($ident);
            }
            $this->RegisterVariableFloat('Gain', $this->Translate('Gain'), [
                'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER, 'MIN' => -15, 'MAX' => 15, 'STEP_SIZE' => 0.5, 'DIGITS' => 1, 'SUFFIX' => ' dB'
            ], 54);
            $this->EnableAction('Gain');
        } else {
            foreach (['ToneEnabled', 'Bass', 'Mid', 'Treble', 'Gain'] as $ident) {
                $this->UnregisterVariable($ident);
            }
        }

        $this->SetReceiveDataFilter('.*' . preg_quote(json_encode($this->ReadPropertyString('PlayerID')), '/') . '.*|.*"Event":"connection".*');
        $this->SetSummary($this->ReadPropertyString('PlayerID'));

        if ($this->ReadPropertyString('PlayerID') === '') {
            $this->SetStatus(104);
            return;
        }
        $this->SetStatus(IS_ACTIVE);
        if ($this->HasActiveParent()) {
            $this->Refresh();
        }
    }

    // ---------- Ereignisse vom Server ----------

    public function ReceiveData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        $event = (string) ($data['Event'] ?? '');
        if ($event === 'connection') {
            if (!empty($data['Data']['connected'])) {
                $this->Refresh();
            } else {
                $this->SetValueIfChanged('Available', false);
            }
            return '';
        }
        if ((string) ($data['ObjectID'] ?? '') !== $this->ReadPropertyString('PlayerID')) {
            return '';
        }
        switch ($event) {
            case 'player_updated':
            case 'player_added':
                if (is_array($data['Data'])) {
                    $this->UpdatePlayer($data['Data']);
                }
                break;
            case 'player_removed':
                $this->SetValueIfChanged('Available', false);
                break;
            case 'player_config_updated':
                $this->RefreshDSP();
                break;
        }
        return '';
    }

    // ---------- Bedienung ----------

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'Control':
                if ((int) $Value !== self::CONTROL_PLAY) {
                    $this->MANotifyManual($this->ReadPropertyString('PlayerID'));
                }
                $map = [self::CONTROL_PREVIOUS => 'previous', self::CONTROL_STOP => 'stop', self::CONTROL_PLAY => 'play', self::CONTROL_PAUSE => 'pause', self::CONTROL_NEXT => 'next'];
                if (isset($map[(int) $Value])) {
                    $this->PlayerCommand($map[(int) $Value]);
                }
                break;
            case 'Volume':
                $this->SetVolume((int) $Value);
                break;
            case 'Mute':
                $this->SetMute((bool) $Value);
                break;
            case 'Power':
                $this->SetPower((bool) $Value);
                break;
            case 'ToneEnabled':
                $this->SetTone(['enabled' => (bool) $Value]);
                break;
            case 'Bass':
                $this->SetTone(['bass_level' => (float) $Value]);
                break;
            case 'Mid':
                $this->SetTone(['mid_level' => (float) $Value]);
                break;
            case 'Treble':
                $this->SetTone(['treble_level' => (float) $Value]);
                break;
            case 'Gain':
                $this->SetTone(['output_gain' => (float) $Value]);
                break;
            default:
                throw new Exception('Ungültiger Ident: ' . $Ident);
        }
    }

    public function Play(): bool
    {
        return $this->PlayerCommand('play');
    }

    public function Pause(): bool
    {
        return $this->PlayerCommand('pause');
    }

    public function Stop(): bool
    {
        return $this->PlayerCommand('stop');
    }

    public function Next(): bool
    {
        return $this->PlayerCommand('next');
    }

    public function Previous(): bool
    {
        return $this->PlayerCommand('previous');
    }

    public function SetVolume(int $Volume): bool
    {
        $Volume = max(0, min(100, $Volume));
        $ok = $this->PlayerCommand('volume_set', ['volume_level' => $Volume]);
        if ($ok) {
            $this->SetValueIfChanged('Volume', $Volume);
        }
        return $ok;
    }

    public function SetMute(bool $Mute): bool
    {
        $ok = $this->PlayerCommand('volume_mute', ['muted' => $Mute]);
        if ($ok) {
            $this->SetValueIfChanged('Mute', $Mute);
        }
        return $ok;
    }

    public function SetPower(bool $Power): bool
    {
        return $this->PlayerCommand('power', ['powered' => $Power]);
    }

    /**
     * Medien abspielen (URI aus Music Assistant, z. B. qobuz…://playlist/123 oder radiobrowser://radio/…).
     * Option: play, replace, next, add (Standard: replace)
     */
    public function PlayMedia(string $URI, string $Option): bool
    {
        $this->MANotifyManual($this->ReadPropertyString('PlayerID'));
        $args = ['queue_id' => $this->ReadPropertyString('PlayerID'), 'media' => $URI];
        if ($Option !== '') {
            $args['option'] = $Option;
        }
        return $this->Call('player_queues/play_media', $args) !== false;
    }

    public function SetShuffle(bool $Shuffle): bool
    {
        return $this->PlayerCommand('shuffle', ['shuffle_enabled' => $Shuffle]);
    }

    public function SetRepeat(string $Mode): bool
    {
        // off, one, all
        return $this->PlayerCommand('repeat', ['repeat_mode' => $Mode]);
    }

    public function Refresh(): bool
    {
        $id = $this->ReadPropertyString('PlayerID');
        if ($id === '') {
            return false;
        }
        $player = $this->Call('players/get', ['player_id' => $id]);
        if (!is_array($player)) {
            $this->SetValueIfChanged('Available', false);
            return false;
        }
        $this->UpdatePlayer($player);
        $this->RefreshDSP();
        return true;
    }

    public function GetPlayer(): string
    {
        return $this->ReadAttributeString('Player');
    }

    // ---------- intern ----------

    private function UpdatePlayer(array $p): void
    {
        $this->WriteAttributeString('Player', json_encode($p));
        $this->SetValueIfChanged('Available', (bool) ($p['available'] ?? false));

        $state = (string) ($p['playback_state'] ?? $p['state'] ?? 'idle');
        $control = ['playing' => self::CONTROL_PLAY, 'paused' => self::CONTROL_PAUSE][$state] ?? self::CONTROL_STOP;
        $this->SetValueIfChanged('Control', $control);

        if (isset($p['volume_level']) && $p['volume_level'] !== null) {
            $this->SetValueIfChanged('Volume', (int) round((float) $p['volume_level']));
        }
        $this->SetValueIfChanged('Mute', (bool) ($p['volume_muted'] ?? false));

        $hasPower = ($p['power_control'] ?? 'none') !== 'none';
        if ($hasPower) {
            $this->RegisterVariableBoolean('Power', $this->Translate('Power'), ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH], 5);
            $this->EnableAction('Power');
            $this->SetValueIfChanged('Power', (bool) ($p['powered'] ?? false));
        }

        $m = is_array($p['current_media'] ?? null) ? $p['current_media'] : [];
        $playing = $control !== self::CONTROL_STOP;
        $this->SetValueIfChanged('Title', $playing ? (string) ($m['title'] ?? '') : '');
        $this->SetValueIfChanged('Artist', $playing ? (string) ($m['artist'] ?? '') : '');
        $this->SetValueIfChanged('Album', $playing ? (string) ($m['album'] ?? '') : '');
        $this->SetValueIfChanged('Cover', $playing ? (string) ($m['image_url'] ?? '') : '');
    }

    private function RefreshDSP(): void
    {
        if (!$this->ReadPropertyBoolean('ShowTone')) {
            return;
        }
        $dsp = $this->Call('config/players/dsp/get', ['player_id' => $this->ReadPropertyString('PlayerID')]);
        if (!is_array($dsp)) {
            return;
        }
        $this->WriteAttributeString('DSP', json_encode($dsp));
        $t = $this->MAToneValues($dsp);
        $this->SetValueIfChanged('ToneEnabled', $t['enabled']);
        $this->SetValueIfChanged('Bass', $t['bass']);
        $this->SetValueIfChanged('Mid', $t['mid']);
        $this->SetValueIfChanged('Treble', $t['treble']);
        $this->SetValueIfChanged('Gain', $t['gain']);
    }

    private function SetTone(array $Change): bool
    {
        $ok = $this->MASetTone($this->ReadPropertyString('PlayerID'), $Change) !== false;
        $this->RefreshDSP();
        return $ok;
    }

    private function PlayerCommand(string $Command, array $Args = []): bool
    {
        $Args['player_id'] = $this->ReadPropertyString('PlayerID');
        return $this->Call('players/cmd/' . $Command, $Args) !== false;
    }

    private function Call(string $Command, array $Args): mixed
    {
        return $this->MACall($Command, $Args);
    }

    private function SetValueIfChanged(string $Ident, mixed $Value): void
    {
        $id = @IPS_GetObjectIDByIdent($Ident, $this->InstanceID);
        if (!is_int($id) || $id === 0) {
            return;
        }
        if (GetValue($id) !== $Value) {
            $this->SetValue($Ident, $Value);
        }
    }
}
