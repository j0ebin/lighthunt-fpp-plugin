<?php
/**
 * Shared by the background poller (lighthunt-daemon.php) and the settings page API (api.php).
 * Runs on the PHP that ships with Falcon Player 9 and 10 (PHP 7.4 through 8.x), so it avoids
 * newer syntax: no str_starts_with, match, named arguments or nullsafe operators.
 *
 * Everything is in the LightHunt namespace, because FPP loads api.php into the same PHP process as
 * its own code and every other plugin. The constants below stay global (define() with a plain name).
 */

namespace LightHunt;

define('LH_REPO', 'lighthunt-fpp-plugin');
define('LH_VERSION', '0.2.1');
define('LH_KEY_RE', '/^lhr_[A-Za-z0-9_-]{43}$/');
define('LH_HEARTBEAT_SECONDS', 30);
define('LH_POLL_SECONDS', 5);
define('LH_BACKOFF', [5, 10, 20, 40, 60]);

/* ---------- Paths, files, log ---------- */

function lh_media_dir()
{
    $env = getenv('FPP_MEDIA_DIR');
    // <media>/plugins/<repoName>/lib -> <media>
    return $env ? rtrim($env, '/') : dirname(__DIR__, 3);
}

/** FPP's log folder, found the way FPP finds it: the LOGDIR it exports, then its settings, then <media>/logs. */
function lh_log_dir()
{
    $env = getenv('LOGDIR');
    if ($env) {
        return rtrim($env, '/');
    }
    if (!empty($GLOBALS['settings']['logDirectory'])) {
        return rtrim($GLOBALS['settings']['logDirectory'], '/');
    }
    return lh_media_dir() . '/logs';
}

function lh_log_file()
{
    return lh_log_dir() . '/plugin-' . LH_REPO . '.log';
}

/**
 * Never creates the folder. Only fpp_install.sh and start.sh (as root) do, so a poller or a page
 * that outlives an uninstall cannot bring the folder back.
 */
function lh_data_dir()
{
    return lh_media_dir() . '/plugindata/' . LH_REPO;
}

function lh_file($name)
{
    return lh_data_dir() . '/' . $name;
}

/** The one runtime log FPP rotates. Never write the pairing key here. */
function lh_log($message)
{
    @file_put_contents(lh_log_file(), gmdate('Y-m-d H:i:s') . ' UTC ' . $message . "\n", FILE_APPEND);
}

function lh_read_json($name)
{
    $raw = @file_get_contents(lh_file($name));
    $data = $raw === false ? null : json_decode($raw, true);
    return is_array($data) ? $data : null;
}

/** Write through a temp file so a reader never sees half a file. */
function lh_write_json($name, $data, $mode = 0600)
{
    $path = lh_file($name);
    $tmp = $path . '.' . getmypid() . '.tmp';
    $old = umask(0077);
    $ok = @file_put_contents($tmp, json_encode($data)) !== false;
    umask($old);
    if (!$ok) {
        return false;
    }
    @chmod($tmp, $mode);
    return @rename($tmp, $path);
}

/** Developers can point the plugin at a local Light Hunt server with the LIGHTHUNT_BASE_URL environment variable. */
function lh_base_url()
{
    $env = getenv('LIGHTHUNT_BASE_URL');
    return $env ? rtrim($env, '/') : 'https://lightshunt.com';
}

function lh_fpp_url()
{
    $env = getenv('LIGHTHUNT_FPP_URL');
    return $env ? rtrim($env, '/') : 'http://127.0.0.1';
}

/* ---------- HTTP ---------- */

/**
 * Returns ['code' => int (0 = network failure), 'json' => array|null, 'retryAfter' => int|null,
 * 'errno' => curl error number (0 when curl succeeded), 'error' => curl error text,
 * 'serverTime' => the server's Date header as a Unix time, or null].
 */
function lh_http($method, $url, $headers = array(), $body = null, $timeout = 5)
{
    $retry = null;
    $serverTime = null;
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'LightHuntFPP/' . LH_VERSION,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$retry, &$serverTime) {
            if (stripos($line, 'retry-after:') === 0) {
                $retry = (int) trim(substr($line, 12));
            } elseif (stripos($line, 'date:') === 0) {
                $t = strtotime(trim(substr($line, 5)));
                $serverTime = $t === false ? null : $t;
            }
            return strlen($line);
        },
    ));
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        return array('code' => 0, 'json' => null, 'retryAfter' => null, 'errno' => $errno, 'error' => $error, 'serverTime' => $serverTime);
    }
    $json = json_decode($raw, true);
    return array('code' => $code, 'json' => is_array($json) ? $json : null, 'retryAfter' => $retry, 'errno' => 0, 'error' => '', 'serverTime' => $serverTime);
}

/**
 * Why a Light Hunt request failed, as a short key the status file can hold: 'tls' (certificate or
 * secure handshake, almost always a wrong clock on the player), 'network', or 'http_<code>'.
 */
function lh_reason_key($r)
{
    if ($r['code'] === 0) {
        $errno = isset($r['errno']) ? (int) $r['errno'] : 0;
        return in_array($errno, array(35, 51, 60), true) ? 'tls' : 'network';
    }
    return 'http_' . (int) $r['code'];
}

/** The sentence for a reason key. $now is the player's clock, shown so the owner can spot a wrong date. */
function lh_reason_text($key, $now)
{
    if ($key === 'tls') {
        return 'Secure connection to Light Hunt failed. Check this player\'s date and time (it says ' . date('D j M Y, H:i T', $now) . ').';
    }
    if ($key === 'network') {
        return 'Could not reach Light Hunt. Check this device has internet access.';
    }
    if (strpos((string) $key, 'http_') === 0) {
        return 'Light Hunt answered with an error (HTTP ' . (int) substr($key, 5) . ').';
    }
    return null;
}

/**
 * A code's expiry on this player's clock. Light Hunt's expiresAt is on Light Hunt's clock, so a
 * player whose clock is off would see every new code as already expired (or never expiring). Measure
 * the code's lifetime on Light Hunt's clock (expiresAt minus its Date header) and add it to our now.
 */
function lh_local_deadline($expiresAt, $serverTime, $now)
{
    $expires = strtotime((string) $expiresAt);
    if ($expires === false) {
        return $now + 600; // codes live 10 minutes
    }
    return $now + ($expires - ($serverTime === null ? $now : $serverTime));
}

/** When a pending pairing runs out, on this player's clock. */
function lh_pending_deadline($pending)
{
    if (isset($pending['deadline'])) {
        return (int) $pending['deadline'];
    }
    $t = isset($pending['expiresAt']) ? strtotime($pending['expiresAt']) : false;
    return $t === false ? 0 : $t;
}

/** A warning when this player's clock differs from Light Hunt's by more than 5 minutes, else null. */
function lh_clock_warning($serverTime, $now)
{
    if ($serverTime === null || abs($serverTime - $now) <= 300) {
        return null;
    }
    $minutes = (int) round(abs($serverTime - $now) / 60);
    return 'This player\'s clock is about ' . $minutes . ' minutes ' . ($now > $serverTime ? 'ahead of' : 'behind')
        . ' Light Hunt\'s (it says ' . date('D j M Y, H:i T', $now) . '). Check this player\'s date and time.';
}

/** Light Hunt POST with a JSON body. */
function lh_post_json($path, $payload, $key = null)
{
    $headers = array('Content-Type: application/json');
    if ($key) {
        $headers[] = 'Authorization: Bearer ' . $key;
    }
    return lh_http('POST', lh_base_url() . $path, $headers, is_string($payload) ? $payload : json_encode($payload), 8);
}

/* ---------- Falcon Player ---------- */

/** FPP's local status, or null when fppd is not answering. */
function lh_fpp_status()
{
    $r = lh_http('GET', lh_fpp_url() . '/api/fppd/status', array(), null, 3);
    return $r['code'] === 200 ? $r['json'] : null;
}

/** Title and artist tags of an audio file, through FPP's own media endpoint. Empty strings when absent. */
function lh_fpp_tags($file)
{
    $r = lh_http('GET', lh_fpp_url() . '/api/media/' . rawurlencode($file) . '/meta', array(), null, 5);
    $tags = ($r['code'] === 200 && isset($r['json']['format']['tags']) && is_array($r['json']['format']['tags']))
        ? array_change_key_case($r['json']['format']['tags'], CASE_LOWER) : array();
    return array(
        'title' => isset($tags['title']) ? trim((string) $tags['title']) : '',
        'artist' => isset($tags['artist']) ? trim((string) $tags['artist']) : '',
    );
}

function lh_clip($value)
{
    $value = (string) $value;
    if (preg_match('/^.{0,200}/su', $value, $m)) {
        return $m[0];
    }
    return substr($value, 0, 200); // not valid UTF-8; the server strips what it cannot read
}

function lh_seconds($value)
{
    return (is_string($value) || is_int($value)) && ctype_digit((string) $value) ? (int) $value : null;
}

/**
 * Maps FPP's /api/fppd/status to the report Light Hunt expects. Pure, so it is tested with recorded JSON.
 * $tags is ['title' => ..., 'artist' => ...] from the media file, or null.
 *
 * playing  = a playlist is running and the current item is a sequence (.fseq) or a media file
 * between  = paused, stopping, or running an item that is not a song (a script, a pause, a command)
 * off      = idle, stopped, testing, or only background audio
 */
function lh_build_report($st, $tags = null)
{
    $statusName = strtolower(trim((string) (isset($st['status_name']) ? $st['status_name'] : '')));
    $seq = trim((string) (isset($st['current_sequence']) ? $st['current_sequence'] : ''));
    $song = trim((string) (isset($st['current_song']) ? $st['current_song'] : ''));
    $isSequence = $seq !== '' && preg_match('/\.fseq$/i', $seq) === 1;

    $running = array('playing', 'playing media', 'stopping gracefully', 'stopping gracefully after loop');
    if (in_array($statusName, $running, true)) {
        $state = ($isSequence || $song !== '') ? 'playing' : 'between';
    } elseif (in_array($statusName, array('paused', 'stopping now'), true)) {
        $state = 'between';
    } else {
        $state = 'off';
    }

    $report = array('source' => 'fpp', 'state' => $state);
    if ($state === 'playing') {
        if ($isSequence) {
            $report['name'] = lh_clip($seq);
        }
        if ($song !== '') {
            $report['mediaFilename'] = lh_clip($song);
        }
        if ($tags && $tags['title'] !== '') {
            $report['mediaTitle'] = lh_clip($tags['title']);
            if ($tags['artist'] !== '') {
                $report['mediaArtist'] = lh_clip($tags['artist']);
            }
        }
        $played = lh_seconds(isset($st['seconds_played']) ? $st['seconds_played'] : null);
        $left = lh_seconds(isset($st['seconds_remaining']) ? $st['seconds_remaining'] : null);
        if ($left !== null) {
            $report['remaining'] = $left;
            if ($played !== null && $played + $left > 0) {
                $report['duration'] = $played + $left;
            }
        }
    }
    $playlist = isset($st['current_playlist']['playlist']) ? trim((string) $st['current_playlist']['playlist']) : '';
    if ($state !== 'off' && $playlist !== '') {
        $report['showName'] = lh_clip($playlist);
    }
    $report['relayVersion'] = LH_VERSION;
    return $report;
}

/** What makes two reports "the same song": a heartbeat resends, a change sends at once. */
function lh_signature($report)
{
    $parts = array();
    foreach (array('state', 'name', 'mediaFilename') as $k) {
        $parts[] = isset($report[$k]) ? $report[$k] : '';
    }
    return implode('|', $parts);
}

function lh_encode_report($report)
{
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    return json_encode($report, $flags);
}

/* ---------- Config and status files ---------- */

/** The pairing: ['key' => 'lhr_...', 'displayId' => int, 'displayName' => string, 'pairedAt' => iso]. Mode 0600. */
function lh_config()
{
    $c = lh_read_json('config.json');
    return ($c && isset($c['key']) && is_string($c['key']) && preg_match(LH_KEY_RE, $c['key'])) ? $c : null;
}

/** What the settings page shows. Written by the daemon; holds no secrets. */
function lh_write_status_if_needed(&$S, $now, $doc)
{
    $doc['version'] = LH_VERSION;
    $doc['pid'] = getmypid();
    $body = json_encode($doc);
    if ($body === $S['lastStatusBody'] && $now - $S['lastStatusAt'] < 60) {
        return;
    }
    $doc['updatedAt'] = $now;
    lh_write_json('status.json', $doc, 0644);
    $S['lastStatusBody'] = $body;
    $S['lastStatusAt'] = $now;
}

/* ---------- The poll loop, one tick at a time ---------- */

function lh_new_state()
{
    return array(
        'key' => null, 'sig' => null, 'sentAt' => 0, 'nextTry' => 0, 'fails' => 0, 'rejected' => null,
        'tagCache' => array(), 'lastOk' => null, 'lastReportAt' => null, 'viewers' => null,
        'lastStatusBody' => null, 'lastStatusAt' => 0, 'badSig' => null, 'reason' => null,
    );
}

/**
 * One pass. $io supplies 'status' => fn(): array|null, 'tags' => fn(string): array, 'post' => fn(key, json): http result.
 * Never throws and never blocks longer than the HTTP timeouts, so a bad network cannot stall anything else.
 */
function lh_tick(&$S, $now, $io)
{
    $cfg = lh_config();
    if (!$cfg) {
        $S['sig'] = null;
        return lh_write_status_if_needed($S, $now, array('result' => 'unpaired'));
    }
    if ($cfg['key'] === $S['rejected']) {
        return lh_write_status_if_needed($S, $now, array('result' => 'revoked'));
    }

    $fpp = call_user_func($io['status']);
    if ($fpp === null) {
        // Nothing to say while fppd is down. Light Hunt shows the setlist after 60 seconds.
        return lh_write_status_if_needed($S, $now, array('result' => 'fpp_unreachable'));
    }

    if ($cfg['key'] !== $S['key']) {
        // A new pairing: say hello right away.
        $S['key'] = $cfg['key'];
        $S['sig'] = null;
        $S['fails'] = 0;
        $S['nextTry'] = 0;
    }

    $report = lh_build_report($fpp);
    $sig = lh_signature($report);
    $due = ($sig !== $S['sig'] || $now - $S['sentAt'] >= LH_HEARTBEAT_SECONDS) && $now >= $S['nextTry'];
    if ($report['state'] === 'playing' && isset($report['mediaFilename'])) {
        // Tags are read once per file, when it is first about to be reported.
        $file = $report['mediaFilename'];
        if (!isset($S['tagCache'][$file]) && $due) {
            if (count($S['tagCache']) >= 100) {
                $S['tagCache'] = array();
            }
            $S['tagCache'][$file] = call_user_func($io['tags'], $file);
        }
        if (isset($S['tagCache'][$file])) {
            $report = lh_build_report($fpp, $S['tagCache'][$file]);
        }
    }

    if ($due) {
        $r = call_user_func($io['post'], $cfg['key'], lh_encode_report($report));
        $code = $r['code'];
        if ($code === 200) {
            if ($S['lastOk'] === false) {
                lh_log('Reports are reaching Light Hunt again');
            }
            $S['lastOk'] = true;
            $S['reason'] = null;
            $S['fails'] = 0;
            $S['sig'] = $sig;
            $S['sentAt'] = $now;
            $S['lastReportAt'] = $now;
            $S['badSig'] = null;
            $S['viewers'] = isset($r['json']['viewers']) ? (int) $r['json']['viewers'] : null;
        } elseif ($code === 401) {
            $S['rejected'] = $cfg['key'];
            lh_log('Light Hunt no longer accepts this pairing (the owner unpaired it). Pair again from the plugin page.');
        } elseif ($code === 429) {
            $S['nextTry'] = $now + max(2, (int) $r['retryAfter']);
        } elseif ($code === 400 || $code === 413) {
            // The report itself was refused; retrying the same report cannot help. Wait for the next song.
            if ($S['badSig'] !== $sig) {
                lh_log('Light Hunt refused a report (HTTP ' . $code . ')');
            }
            $S['badSig'] = $sig;
            $S['sig'] = $sig;
            $S['sentAt'] = $now;
        } else {
            $S['fails']++;
            $S['nextTry'] = $now + LH_BACKOFF[min($S['fails'], count(LH_BACKOFF)) - 1];
            $S['reason'] = lh_reason_key($r);
            if ($S['lastOk'] !== false) {
                $what = $code === 0 ? 'curl error ' . (isset($r['errno']) ? (int) $r['errno'] : 0) . ': ' . (isset($r['error']) ? $r['error'] : '') : 'HTTP ' . $code;
                lh_log('Cannot reach Light Hunt (' . $what . '). Retrying with a growing delay.');
            }
            $S['lastOk'] = false;
        }
    }

    $doc = array(
        'result' => $S['rejected'] === $cfg['key'] ? 'revoked' : ($S['lastOk'] === false ? 'offline' : 'ok'),
        'state' => $report['state'],
        'song' => isset($report['mediaTitle']) ? $report['mediaTitle']
            : (isset($report['mediaFilename']) ? $report['mediaFilename'] : (isset($report['name']) ? $report['name'] : null)),
        'lastReportAt' => $S['lastReportAt'],
        'viewers' => $S['viewers'],
        'reason' => $S['lastOk'] === false ? $S['reason'] : null,
    );
    lh_write_status_if_needed($S, $now, $doc);
}

/** True when this plugin has been uninstalled out from under a running poller: its file or data folder is gone. */
function lh_orphaned($daemonFile)
{
    return !is_file($daemonFile) || !is_dir(lh_data_dir());
}

/** True when a poller holds the lock. Opens read-only so the web user can check a lock the poller created as another user. */
function lh_daemon_running()
{
    $path = lh_file('daemon.lock');
    if (!is_file($path)) {
        return false;
    }
    $fh = @fopen($path, 'r');
    if (!$fh) {
        return false;
    }
    $free = flock($fh, LOCK_SH | LOCK_NB);
    if ($free) {
        flock($fh, LOCK_UN);
    }
    fclose($fh);
    return !$free;
}

/**
 * Starts the poller from a page request, only when fppd answers and wasn't stopped on purpose.
 * preStop.sh leaves a `stopped` marker that start.sh clears, so a page open in a browser cannot
 * bring the poller back while fppd is shutting down or stopped.
 */
function lh_start_poller()
{
    if (lh_daemon_running() || is_file(lh_file('stopped')) || !is_dir(lh_data_dir()) || lh_fpp_status() === null) {
        return;
    }
    exec('/bin/bash ' . escapeshellarg(dirname(__DIR__) . '/scripts/start.sh') . ' >/dev/null 2>&1 &');
}

function lh_pair_url($code)
{
    return lh_base_url() . '/relay/pair?code=' . rawurlencode($code);
}
