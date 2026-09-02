<?php

declare(strict_types=1);

/**
 * Dashboard-Kachel fuer Kameras -- baut bewusst auf bereits vorhandenen
 * Geraeteinstanzen auf statt einen eigenen API-Client zu bauen:
 *  - Blink: das bestehende Community-Modul "Blink Home Device"
 *    (https://github.com/Wilkware/BlinkHomeSystem) legt pro Kamera schon
 *    Variablen mit bekannten Idents an (thumbnail/battery/motion_detection/
 *    snapshot/record) -- dieses Modul liest sie per SelectInstance +
 *    IPS_GetObjectIDByIdent() aus, keine einzelne Variable wird manuell
 *    verdrahtet (gleiches Prinzip wie battery_monitor im Alarm Dashboard).
 *  - Ring: das separate IPSymcon-Ring-Modul (RingCamera-Instanz) registriert
 *    bewusst dieselben Idents (thumbnail/battery/motion_detection/snapshot),
 *    damit diese Kachel beide Hersteller ohne Sonderfaelle rendert. Nur die
 *    Batterie-Skala unterscheidet sich (Blink: 0-3 Stufen, Ring: Prozent) --
 *    das wird anhand der Instanz-ModulID unterschieden.
 *
 * Bewusst NICHT eingebaut: Live-Video (beide Hersteller kapseln ihre Streams
 * proprietaer, das erfordert einen dauerhaft laufenden externen Proxy-Dienst,
 * kein PHP/IPS-Modul) -- nur periodische Standbilder.
 */
class CameraDashboard extends IPSModule
{
    /** ModulID von "Blink Home Device" (Wilkware/BlinkHomeSystem) -- unterscheidet die 0-3-Batterieskala von Rings Prozentwert. */
    private const BLINK_DEVICE_MODULE_GUID = '{7D2B8EFA-23D0-D29C-DBEE-E81F1FC2DBDC}';

    private const BLINK_BATTERY_LABELS = [
        0 => ['Unbekannt', 'badge-off'],
        1 => ['Kritisch',  'badge-warn'],
        2 => ['Niedrig',   'badge-amber'],
        3 => ['Gut',       'badge-green'],
    ];

    /**
     * 1x1 dark-navy PNG shown until a camera's first real snapshot arrives.
     * Always an <img> from the start (never a placeholder <div>) so a later
     * live push can just setAttribute('src', ...) -- swapping element types
     * on live-update is the exact bug this project already hit once before
     * (see AlarmDashboard history).
     */
    private const NO_IMAGE_PLACEHOLDER = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('cameras', '[]');
        $this->RegisterPropertyInteger('sync_instance', 0);
        $this->RegisterPropertyInteger('update_interval', 60);

        $this->RegisterTimer('UpdateTimer', 0, 'CAMD_Refresh($_IPS[\'TARGET\']);');
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $cameras     = json_decode($this->ReadPropertyString('cameras'), true) ?: [];
        $hasAnything = count($cameras) > 0 || $this->ReadPropertyInteger('sync_instance') > 0;

        if (!$hasAnything) {
            $this->SetStatus(201);
            $this->SetTimerInterval('UpdateTimer', 0);
            return;
        }

        $interval = $this->ReadPropertyInteger('update_interval');
        $this->SetTimerInterval('UpdateTimer', $interval > 0 ? $interval * 1000 : 0);
        $this->SetStatus(102);
        $this->Refresh();
    }

    public function GetVisualizationTile(): string
    {
        return $this->buildDashboardHTML();
    }

    public function Refresh(): void
    {
        try {
            $this->pushValue('__all__', $this->collectData());
            $this->SetStatus(102);
        } catch (\Throwable $e) {
            $this->LogMessage('CameraDashboard Refresh: ' . $e->getMessage(), KL_ERROR);
            $this->SetStatus(200);
        }
    }

    // ─── IPS action handler ─────────────────────────────────────────────────────

    public function RequestAction($Ident, $Value): void
    {
        try {
            if (strpos($Ident, 'cam_') === 0) {
                $rest = substr($Ident, strlen('cam_'));
                $sep  = strpos($rest, '_');
                if ($sep === false) {
                    return;
                }
                $index  = (int) substr($rest, 0, $sep);
                $action = substr($rest, $sep + 1);
                $this->forwardCameraAction($index, $action, (bool) $Value);
                return;
            }
            if ($Ident === 'sync_recording') {
                $this->forwardSyncAction('recording', (bool) $Value);
                return;
            }
            $this->LogMessage("CameraDashboard RequestAction: unknown ident {$Ident}", KL_WARNING);
        } catch (\Throwable $e) {
            $this->LogMessage('CameraDashboard RequestAction ' . $Ident . ': ' . $e->getMessage(), KL_ERROR);
        }
    }

    /** $action: 'motion' (echter Schalter) oder 'snapshot' (Ausloeser, Wert wird vom Zielmodul ignoriert). */
    private function forwardCameraAction(int $index, string $action, bool $value): void
    {
        $rows = json_decode($this->ReadPropertyString('cameras'), true) ?: [];
        $instanceId = (int) ($rows[$index]['instance'] ?? 0);
        if ($instanceId <= 0 || !@IPS_InstanceExists($instanceId)) {
            return;
        }

        $ident = $action === 'motion' ? 'motion_detection' : 'snapshot';
        $varId = $this->varIdByIdent($instanceId, $ident);
        if ($varId <= 0) {
            return;
        }
        RequestAction($varId, $value);
    }

    private function forwardSyncAction(string $ident, bool $value): void
    {
        $nodeId = $this->ReadPropertyInteger('sync_instance');
        $varId  = $this->varIdByIdent($nodeId, $ident);
        if ($varId <= 0) {
            return;
        }
        RequestAction($varId, $value);
    }

    // ─── Data collection ──────────────────────────────────────────────────────

    private function collectData(): array
    {
        return [
            'cameras' => $this->collectCameras(),
            'sync'    => $this->collectSync(),
            'updated' => date('d.m. H:i'),
        ];
    }

    private function collectCameras(): array
    {
        $out = [];
        foreach (json_decode($this->ReadPropertyString('cameras'), true) ?: [] as $i => $row) {
            $instanceId = (int) ($row['instance'] ?? 0);
            if ($instanceId <= 0 || !@IPS_InstanceExists($instanceId)) {
                continue;
            }

            $nameOverride = ($row['name'] ?? '') !== '' ? $row['name'] : null;
            $isBlink      = $this->isBlinkInstance($instanceId);

            $batteryId = $this->varIdByIdent($instanceId, 'battery');
            $motionId  = $this->varIdByIdent($instanceId, 'motion_detection');
            $hasMotion = $motionId > 0;
            $hasSnap   = $this->varIdByIdent($instanceId, 'snapshot') > 0;

            $out[] = [
                'ident'      => 'cam_' . $i,
                'name'       => $nameOverride ?? IPS_GetName($instanceId),
                'image'      => $this->readThumbnail($instanceId),
                'battery'    => $batteryId > 0 ? (int) GetValue($batteryId) : null,
                'batteryPct' => !$isBlink,
                'motion'     => $hasMotion ? (bool) GetValue($motionId) : null,
                'hasSnap'    => $hasSnap,
            ];
        }
        return $out;
    }

    private function collectSync(): ?array
    {
        $nodeId = $this->ReadPropertyInteger('sync_instance');
        if ($nodeId <= 0 || !@IPS_InstanceExists($nodeId)) {
            return null;
        }
        $recordingId  = $this->varIdByIdent($nodeId, 'recording');
        $lastMotionId = $this->varIdByIdent($nodeId, 'last_motion');
        if ($recordingId <= 0) {
            return null;
        }
        $lastMotion = $lastMotionId > 0 ? (int) GetValue($lastMotionId) : 0;
        return [
            'name'          => IPS_GetName($nodeId),
            'recording'     => (bool) GetValue($recordingId),
            'lastMotionStr' => $lastMotion > 0 ? date('d.m. H:i', $lastMotion) : '–',
        ];
    }

    private function isBlinkInstance(int $instanceId): bool
    {
        $inst = @IPS_GetInstance($instanceId);
        return is_array($inst) && ($inst['ModuleInfo']['ModuleID'] ?? '') === self::BLINK_DEVICE_MODULE_GUID;
    }

    /**
     * Liest das per CreateMediaImage() angelegte Standbild ("thumbnail"-Ident,
     * bei Blink UND Ring gleich benannt) als Data-URI -- eingebettet statt per
     * relativer media/-URL, damit die Kachel unabhaengig vom WebFront-Pfad
     * funktioniert.
     */
    private function readThumbnail(int $instanceId): string
    {
        $mediaId = $this->varIdByIdent($instanceId, 'thumbnail');
        if ($mediaId <= 0 || !@IPS_MediaExists($mediaId)) {
            return '';
        }
        $media = IPS_GetMedia($mediaId);
        if (($media['MediaType'] ?? null) !== MEDIATYPE_IMAGE) {
            return '';
        }
        $mime = $this->mimeForExtension((string) pathinfo((string) ($media['MediaFile'] ?? ''), PATHINFO_EXTENSION));
        if ($mime === '') {
            return '';
        }
        $content = @IPS_GetMediaContent($mediaId);
        if ($content === false || $content === '') {
            return '';
        }
        return $mime . $content;
    }

    private function mimeForExtension(string $ext): string
    {
        switch (strtolower($ext)) {
            case 'jpg':
            case 'jpeg':
                return 'data:image/jpeg;base64,';
            case 'png':
                return 'data:image/png;base64,';
            case 'gif':
                return 'data:image/gif;base64,';
            case 'bmp':
                return 'data:image/bmp;base64,';
            default:
                return '';
        }
    }

    // ─── Rendering ──────────────────────────────────────────────────────────────

    private function buildDashboardHTML(): string
    {
        $d = $this->collectData();

        $camsHtml = '';
        foreach ($d['cameras'] as $cam) {
            $camsHtml .= $this->renderCamera($cam);
        }
        $camsBlock = $camsHtml !== ''
            ? '<div class="cam-grid">' . $camsHtml . '</div>'
            : '<div class="empty">' . $this->Translate('Keine Kamera konfiguriert') . '</div>';

        $syncBlock = $this->renderSync($d['sync']);

        $updatedEsc = htmlspecialchars($d['updated'], ENT_QUOTES);
        $initJson   = json_encode($d);

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
html{height:100%}
*{box-sizing:border-box;margin:0;padding:0}
body{overflow-y:auto;overflow-x:hidden;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:13px;background:#0d1b2a;color:#d0e8ff;display:flex;flex-direction:column;padding:10px;gap:10px}
.header{display:flex;justify-content:space-between;align-items:center;gap:6px;font-size:14px;font-weight:600;border-bottom:1px solid #1e3a5f;padding-bottom:6px;flex:none}
.updated{font-size:10px;color:#3a5a7a;font-weight:400}
.badge{padding:3px 8px;border-radius:12px;font-size:11px;border:1px solid transparent;white-space:nowrap}
.badge-off{background:#1a2535;border-color:#2a3a50;color:#4a6a8a}
.badge-on{background:#12405a;border-color:#2a7aa0;color:#7ec8f0}
.badge-warn{background:#4a2010;border-color:#8a4020;color:#f08060}
.badge-amber{background:#4a3510;border-color:#8a6a20;color:#f0c060}
.badge-green{background:#124a1e;border-color:#2f8a44;color:#7ee89a}
.empty{font-size:12px;color:#4a6a8a;padding:10px 0}
.cam-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.cam-tile{display:flex;flex-direction:column;gap:6px;background:#131f33;border-radius:10px;padding:8px;overflow:hidden}
.cam-img-wrap{position:relative;width:100%;aspect-ratio:4/3;border-radius:8px;overflow:hidden;background:#0a1526}
.cam-img-wrap img{width:100%;height:100%;object-fit:cover;display:block}
.cam-name{font-size:12px;font-weight:600;color:#d0e8ff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cam-row{display:flex;align-items:center;justify-content:space-between;gap:6px}
.toggle{position:relative;width:34px;height:20px;flex:none;display:inline-block}
.toggle input{opacity:0;position:absolute;width:100%;height:100%;margin:0;cursor:pointer;z-index:1}
.toggle-track{position:absolute;inset:0;background:#1a2535;border:1px solid #2a3a50;border-radius:10px;transition:.15s}
.toggle-thumb{position:absolute;top:1px;left:1px;width:14px;height:14px;background:#8aa8c8;border-radius:50%;transition:.15s}
.toggle input:checked ~ .toggle-track{background:#12405a;border-color:#2a7aa0}
.toggle input:checked ~ .toggle-track .toggle-thumb{transform:translateX(14px);background:#7ec8f0}
.mini-btn{background:#1a2535;border:1px solid #2a3a50;color:#8aa8c8;border-radius:6px;padding:4px 8px;font-size:11px;cursor:pointer;flex:1}
.pv-block{display:flex;flex-direction:column;gap:6px;flex:none;background:#0f1c30;border-radius:10px;padding:8px}
.pv-title{font-size:12px;font-weight:700;color:#d0e8ff;display:flex;justify-content:space-between;align-items:center}
.sub{font-size:10px;color:#4a6a8a}
</style>
</head>
<body>
<div class="header">
  <span>📷 {$this->Translate('Kameras')} <span id="updated" class="updated">{$this->Translate('Stand')} {$updatedEsc}</span></span>
</div>

{$syncBlock}
{$camsBlock}

<script>
var state = {$initJson};
var i18n = {
  aktiv: {$this->jsStr($this->Translate('Aktiv'))},
  inaktiv: {$this->jsStr($this->Translate('Inaktiv'))},
  unbekannt: {$this->jsStr($this->Translate('Unbekannt'))},
  kritisch: {$this->jsStr($this->Translate('Kritisch'))},
  niedrig: {$this->jsStr($this->Translate('Niedrig'))},
  gut: {$this->jsStr($this->Translate('Gut'))}
};

function setText(id, text) {
  var el = document.getElementById(id);
  if (el) el.textContent = text;
}

// Same formatting as renderCamera() in PHP -- initial render and live
// push must produce identical text, otherwise the value visibly "jumps"
// the moment the first push arrives after the tile opens.
var blinkBatteryLabels = [i18n.unbekannt, i18n.kritisch, i18n.niedrig, i18n.gut];

function updateCamera(cam) {
  if (cam.battery !== null) {
    var battEl = document.getElementById(cam.ident + '_batt');
    if (battEl) {
      var battText = cam.batteryPct ? (cam.battery + '%') : (blinkBatteryLabels[cam.battery] || i18n.unbekannt);
      battEl.textContent = '🔋 ' + battText;
    }
  }
  if (cam.motion !== null) {
    var input = document.getElementById(cam.ident + '_motion_input');
    if (input) input.checked = cam.motion;
    var label = document.getElementById(cam.ident + '_motion_label');
    if (label) label.textContent = cam.motion ? i18n.aktiv : i18n.inaktiv;
  }
  if (cam.image) {
    var img = document.getElementById(cam.ident + '_img');
    if (img) img.src = cam.image;
  }
}

window.handleMessage = function(raw) {
  var msg = JSON.parse(raw);
  if (msg.key !== '__all__') return;
  var val = msg.value;
  state = val;
  setText('updated', val.updated);
  for (var i = 0; i < val.cameras.length; i++) {
    updateCamera(val.cameras[i]);
  }
  if (val.sync) {
    var syncInput = document.getElementById('sync_recording_input');
    if (syncInput) syncInput.checked = val.sync.recording;
    setText('sync_recording_label', val.sync.recording ? i18n.aktiv : i18n.inaktiv);
    setText('sync_last_motion', val.sync.lastMotionStr);
  }
};
</script>
</body>
</html>
HTML;
    }

    private function renderSync(?array $sync): string
    {
        if ($sync === null) {
            return '';
        }

        $checked       = $sync['recording'] ? ' checked' : '';
        $label         = $sync['recording'] ? $this->Translate('Aktiv') : $this->Translate('Inaktiv');
        $nameEsc       = htmlspecialchars($sync['name'], ENT_QUOTES);
        $lastMotionStr = htmlspecialchars($sync['lastMotionStr'], ENT_QUOTES);

        return <<<HTML
<div class="pv-block">
  <div class="pv-title">
    <span>🛡️ {$nameEsc}</span>
    <label class="toggle"><input id="sync_recording_input" type="checkbox"{$checked} onchange="requestAction('sync_recording', this.checked)"><span class="toggle-track"><span class="toggle-thumb"></span></span></label>
  </div>
  <div class="cam-row"><span id="sync_recording_label" class="sub">{$label}</span><span class="sub">{$this->Translate('Letzte Bewegung')}: <span id="sync_last_motion">{$lastMotionStr}</span></span></div>
</div>
HTML;
    }

    private function renderCamera(array $cam): string
    {
        $identEsc = htmlspecialchars($cam['ident'], ENT_QUOTES);
        $nameEsc  = htmlspecialchars($cam['name'], ENT_QUOTES);

        $imgSrc  = $cam['image'] !== '' ? $cam['image'] : self::NO_IMAGE_PLACEHOLDER;
        $imgHtml = "<img id='{$identEsc}_img' src='{$imgSrc}' alt='{$nameEsc}'>";

        $battHtml = '';
        if ($cam['battery'] !== null) {
            $battText = $cam['batteryPct']
                ? $cam['battery'] . '%'
                : (self::BLINK_BATTERY_LABELS[$cam['battery']][0] ?? $this->Translate('Unbekannt'));
            $battHtml = "<span id='{$identEsc}_batt' class='sub'>🔋 " . htmlspecialchars($battText, ENT_QUOTES) . '</span>';
        }

        $motionHtml = '';
        if ($cam['motion'] !== null) {
            $checked = $cam['motion'] ? ' checked' : '';
            $label   = $cam['motion'] ? $this->Translate('Aktiv') : $this->Translate('Inaktiv');
            $motionHtml = <<<HTML
<div class="cam-row">
  <span id='{$identEsc}_motion_label' class="sub">{$label}</span>
  <label class="toggle"><input id='{$identEsc}_motion_input' type="checkbox"{$checked} onchange="requestAction('{$cam['ident']}_motion', this.checked)"><span class="toggle-track"><span class="toggle-thumb"></span></span></label>
</div>
HTML;
        }

        $snapBtn = '';
        if ($cam['hasSnap']) {
            $snapIdent = $cam['ident'] . '_snapshot';
            $snapLabel = $this->Translate('Snapshot');
            $snapBtn   = <<<HTML
<button type="button" class="mini-btn" onclick="requestAction('{$snapIdent}', 1)">📸 {$snapLabel}</button>
HTML;
        }

        return <<<HTML
<div class="cam-tile">
  <div class="cam-img-wrap">{$imgHtml}</div>
  <div class="cam-row"><span class="cam-name">{$nameEsc}</span>{$battHtml}</div>
  {$motionHtml}
  {$snapBtn}
</div>
HTML;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function pushValue(string $key, $value): void
    {
        $this->UpdateVisualizationValue(json_encode(['key' => $key, 'value' => $value]));
    }

    private function varIdByIdent(int $instanceId, string $ident): int
    {
        if ($instanceId <= 0) {
            return 0;
        }
        $id = @IPS_GetObjectIDByIdent($ident, $instanceId);
        return $id ?: 0;
    }

    /** Encodes a translated string for safe embedding as a JS literal. */
    private function jsStr(string $s): string
    {
        return json_encode($s, JSON_UNESCAPED_UNICODE);
    }
}
