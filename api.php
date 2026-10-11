<?php
/**
 * Endpoints for the Light Hunt settings page, served by FPP at /api/plugin/lighthunt-fpp-plugin/<endpoint>.
 * The browser never sees the pairing key or the device secret; this file talks to Light Hunt for it.
 */

require_once __DIR__ . '/lib/lighthunt.php';

function getEndpointslighthuntfppplugin()
{
    return array(
        array('method' => 'GET', 'endpoint' => 'status', 'callback' => 'lighthuntfpppluginStatus'),
        array('method' => 'POST', 'endpoint' => 'pairstart', 'callback' => 'lighthuntfpppluginPairStart'),
        array('method' => 'GET', 'endpoint' => 'paircheck', 'callback' => 'lighthuntfpppluginPairCheck'),
        array('method' => 'POST', 'endpoint' => 'unpair', 'callback' => 'lighthuntfpppluginUnpair'),
    );
}

function lh_start_poller()
{
    if (!lh_daemon_running()) {
        exec('/bin/sh ' . escapeshellarg(__DIR__ . '/scripts/start.sh') . ' >/dev/null 2>&1 &');
    }
}

function lh_pair_url($code)
{
    return lh_base_url() . '/relay/pair?code=' . rawurlencode($code);
}

// GET /api/plugin/lighthunt-fpp-plugin/status
function lighthuntfpppluginStatus()
{
    $cfg = lh_config();
    $running = lh_daemon_running();
    if ($cfg && !$running) {
        lh_start_poller(); // heals a poller that was never started, e.g. right after installing
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
    if ($pending && isset($pending['expiresAt']) && strtotime($pending['expiresAt']) > time()) {
        $out['pending'] = array('code' => $pending['code'], 'url' => lh_pair_url($pending['code']), 'expiresAt' => $pending['expiresAt']);
    }
    return json($out);
}

// POST /api/plugin/lighthunt-fpp-plugin/pairstart
function lighthuntfpppluginPairStart()
{
    $r = lh_post_json('/api/relay/pair', array('relayVersion' => LH_VERSION));
    if ($r['code'] !== 201 || !isset($r['json']['code'], $r['json']['deviceSecret'], $r['json']['expiresAt'])) {
        $message = isset($r['json']['error']) ? $r['json']['error'] : 'Could not reach Light Hunt. Check this device has internet access.';
        return json(array('ok' => false, 'error' => $message));
    }
    lh_write_json('pending.json', array(
        'code' => $r['json']['code'],
        'deviceSecret' => $r['json']['deviceSecret'],
        'expiresAt' => $r['json']['expiresAt'],
        'lastCheck' => 0,
    ));
    return json(array('ok' => true, 'code' => $r['json']['code'], 'url' => lh_pair_url($r['json']['code']), 'expiresAt' => $r['json']['expiresAt']));
}

// GET /api/plugin/lighthunt-fpp-plugin/paircheck
function lighthuntfpppluginPairCheck()
{
    $pending = lh_read_json('pending.json');
    if (!$pending) {
        return json(array('status' => lh_config() ? 'paired' : 'none'));
    }
    if (strtotime($pending['expiresAt']) <= time()) {
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
    return json(array('status' => 'pending', 'warning' => 'Could not reach Light Hunt. Still trying.'));
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
