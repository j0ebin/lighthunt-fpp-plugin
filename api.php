<?php
/**
 * Endpoints for the Light Hunt settings page, served by FPP at /api/plugin/lighthunt-fpp-plugin/<endpoint>.
 * The browser never sees the pairing key or the device secret; this file talks to Light Hunt for it.
 */

require_once __DIR__ . '/lib/lighthunt.php';

use function LightHunt\lh_base_url;
use function LightHunt\lh_clock_warning;
use function LightHunt\lh_config;
use function LightHunt\lh_daemon_running;
use function LightHunt\lh_data_dir;
use function LightHunt\lh_file;
use function LightHunt\lh_local_deadline;
use function LightHunt\lh_log;
use function LightHunt\lh_pair_url;
use function LightHunt\lh_pending_deadline;
use function LightHunt\lh_post_json;
use function LightHunt\lh_read_json;
use function LightHunt\lh_reason_key;
use function LightHunt\lh_reason_text;
use function LightHunt\lh_start_poller;
use function LightHunt\lh_write_json;

// FPP looks up the endpoint list and the callbacks below by these exact global names.
function getEndpointslighthuntfppplugin()
{
    return array(
        array('method' => 'GET', 'endpoint' => 'status', 'callback' => 'lighthuntfpppluginStatus'),
        array('method' => 'POST', 'endpoint' => 'pairstart', 'callback' => 'lighthuntfpppluginPairStart'),
        array('method' => 'POST', 'endpoint' => 'paircheck', 'callback' => 'lighthuntfpppluginPairCheck'),
        array('method' => 'POST', 'endpoint' => 'unpair', 'callback' => 'lighthuntfpppluginUnpair'),
    );
}

// GET /api/plugin/lighthunt-fpp-plugin/status
function lighthuntfpppluginStatus()
{
    $cfg = lh_config();
    $running = lh_daemon_running();
    if ($cfg && !$running) {
        // Heals a poller that was never started, e.g. right after installing. Does nothing while fppd is
        // stopped or stopping, so this page can never bring the poller back after preStop.sh.
        lh_start_poller();
    }
    $status = lh_read_json('status.json');
    // A status written before the current pairing describes the old key, so ignore it until the poller reports again.
    $pairedAt = $cfg && isset($cfg['pairedAt']) ? (int) strtotime($cfg['pairedAt']) : 0;
    $fresh = $status && isset($status['updatedAt']) && time() - (int) $status['updatedAt'] <= 150 && (int) $status['updatedAt'] >= $pairedAt;
    $out = array(
        'version' => LH_VERSION,
        'paired' => (bool) $cfg,
        'pollerRunning' => $running,
        'result' => $fresh ? $status['result'] : null,
        'state' => $fresh && isset($status['state']) ? $status['state'] : null,
        'song' => $fresh && isset($status['song']) ? $status['song'] : null,
        'lastReportAt' => $fresh && isset($status['lastReportAt']) ? $status['lastReportAt'] : null,
        'viewers' => $fresh && isset($status['viewers']) ? $status['viewers'] : null,
        'reason' => $fresh && isset($status['reason']) ? lh_reason_text($status['reason'], time()) : null,
        'display' => null,
        'pending' => null,
    );
    if ($cfg) {
        $out['display'] = array(
            'id' => isset($cfg['displayId']) ? $cfg['displayId'] : null,
            'name' => isset($cfg['displayName']) ? $cfg['displayName'] : null,
            'url' => isset($cfg['displayId']) ? lh_base_url() . '/display/' . (int) $cfg['displayId'] : null,
        );
    }
    $pending = lh_read_json('pending.json');
    if ($pending && isset($pending['expiresAt']) && lh_pending_deadline($pending) > time()) {
        $out['pending'] = array(
            'code' => $pending['code'],
            'url' => lh_pair_url($pending['code']),
            'expiresAt' => $pending['expiresAt'],
            'warning' => isset($pending['warning']) ? $pending['warning'] : null,
        );
    }
    return json($out);
}

// POST /api/plugin/lighthunt-fpp-plugin/pairstart
function lighthuntfpppluginPairStart()
{
    // Check before asking Light Hunt for a code: a code this player can't save is one the owner never sees.
    $cantSave = 'This player can\'t save Light Hunt\'s files. In FPP, open Status/Control, press Restart FPPD, then try again.';
    if (!is_writable(lh_data_dir())) {
        return json(array('ok' => false, 'error' => $cantSave));
    }
    $r = lh_post_json('/api/relay/pair', array('relayVersion' => LH_VERSION));
    if ($r['code'] !== 201 || !isset($r['json']['code'], $r['json']['deviceSecret'], $r['json']['expiresAt'])) {
        $message = isset($r['json']['error']) ? $r['json']['error'] : lh_reason_text(lh_reason_key($r), time());
        return json(array('ok' => false, 'error' => $message));
    }
    $now = time();
    $warning = lh_clock_warning($r['serverTime'], $now);
    $saved = lh_write_json('pending.json', array(
        'code' => $r['json']['code'],
        'deviceSecret' => $r['json']['deviceSecret'],
        'expiresAt' => $r['json']['expiresAt'],
        // expiresAt is on Light Hunt's clock; this is the same moment on this player's clock.
        'deadline' => lh_local_deadline($r['json']['expiresAt'], $r['serverTime'], $now),
        'warning' => $warning,
        'lastCheck' => 0,
    ));
    if (!$saved) {
        return json(array('ok' => false, 'error' => $cantSave));
    }
    return json(array('ok' => true, 'code' => $r['json']['code'], 'url' => lh_pair_url($r['json']['code']), 'expiresAt' => $r['json']['expiresAt'], 'warning' => $warning));
}

// POST /api/plugin/lighthunt-fpp-plugin/paircheck (it writes files, so not GET)
function lighthuntfpppluginPairCheck()
{
    $pending = lh_read_json('pending.json');
    if (!$pending) {
        return json(array('status' => lh_config() ? 'paired' : 'none'));
    }
    if (lh_pending_deadline($pending) <= time()) {
        @unlink(lh_file('pending.json'));
        return json(array('status' => 'expired'));
    }
    // Light Hunt allows 120 checks per code per 10 minutes; stay well under it, even with several tabs open.
    if (time() - (int) $pending['lastCheck'] < 5) {
        return json(array('status' => 'pending'));
    }
    $pending['lastCheck'] = time();
    lh_write_json('pending.json', $pending);

    $r = lh_post_json('/api/relay/pair/status', array('code' => $pending['code'], 'deviceSecret' => $pending['deviceSecret']));
    if ($r['code'] === 200 && isset($r['json']['status']) && $r['json']['status'] === 'pending') {
        return json(array('status' => 'pending'));
    }
    if ($r['code'] === 200 && isset($r['json']['status']) && $r['json']['status'] === 'paired' && isset($r['json']['key']) && preg_match(LH_KEY_RE, $r['json']['key'])) {
        $display = isset($r['json']['display']) && is_array($r['json']['display']) ? $r['json']['display'] : array();
        lh_write_json('config.json', array(
            'key' => $r['json']['key'],
            'displayId' => isset($display['id']) ? (int) $display['id'] : null,
            'displayName' => isset($display['name']) ? (string) $display['name'] : null,
            'pairedAt' => gmdate('c'),
        ));
        @unlink(lh_file('pending.json'));
        lh_log('Paired with display ' . (isset($display['id']) ? (int) $display['id'] : '?'));
        lh_start_poller();
        return json(array('status' => 'paired'));
    }
    if ($r['code'] === 404 || $r['code'] === 410 || $r['code'] === 409) {
        @unlink(lh_file('pending.json'));
        return json(array('status' => 'expired'));
    }
    $why = $r['code'] === 0 ? lh_reason_text(lh_reason_key($r), time()) : 'Light Hunt answered with an error (HTTP ' . (int) $r['code'] . ').';
    return json(array('status' => 'pending', 'warning' => $why . ' Still trying.'));
}

// POST /api/plugin/lighthunt-fpp-plugin/unpair
function lighthuntfpppluginUnpair()
{
    @unlink(lh_file('config.json'));
    @unlink(lh_file('pending.json'));
    @unlink(lh_file('status.json'));
    lh_log('Pairing key removed from this device');
    return json(array('ok' => true));
}
