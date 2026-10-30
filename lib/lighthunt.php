<?php
/**
 * Shared by the background poller (lighthunt-daemon.php) and the settings page API (api.php).
 * Runs on the PHP that ships with Falcon Player 9 and 10 (PHP 7.4 through 8.x), so it avoids
 * newer syntax: no str_starts_with, match, named arguments or nullsafe operators.
 *
 * Everything is in the LightHunt namespace, constants included, because FPP loads api.php into the
 * same PHP process as its own code and every other plugin. define() would make a global constant
 * even here, so these use const. Callers import them with `use const LightHunt\LH_...;`.
 */

namespace LightHunt;

const LH_REPO = 'lighthunt-fpp-plugin';
const LH_VERSION = '0.4.1';
const LH_KEY_RE = '/^lhr_[A-Za-z0-9_-]{43}$/';
// Light Hunt calls a sender stale after 60 seconds (STALE_MS in api/_relay.ts), so this leaves room for two lost posts.
const LH_HEARTBEAT_SECONDS = 20;
const LH_POLL_SECONDS = 5;
const LH_MAX_NEXT = 3;
/** How long a read of the running playlist is reused before FPP is asked again. */
const LH_PLAYLIST_SECONDS = 60;
const LH_BACKOFF = [5, 10, 20, 40, 60];
const LH_RETRY_AFTER_MAX = 600;
const LH_TAG_RETRY_SECONDS = 60;
const LH_RESTART_DROP = 5;
const LH_FEEDBACK_MIN = 5;
const LH_FEEDBACK_MAX = 2000;
const LH_LOG_LINES = 20;
const LH_LOG_MAX = 8000;

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

/**
 * status.json is rewritten every few seconds around the clock, which wears out an SD card, and it
 * means nothing after a reboot. So it lives in RAM (/dev/shm) when that folder is there, and in the
 * data folder when it is not. LIGHTHUNT_SHM_DIR is for tests.
 */
function lh_status_path()
{
    $shm = getenv('LIGHTHUNT_SHM_DIR') ?: '/dev/shm';
    return is_dir($shm) && is_writable($shm) ? rtrim($shm, '/') . '/' . LH_REPO . '-status.json' : lh_data_dir() . '/status.json';
}

function lh_file($name)
{
    return $name === 'status.json' ? lh_status_path() : lh_data_dir() . '/' . $name;
}

/** The one runtime log FPP rotates. Never write the pairing key here. */
function lh_log($message)
{
    @file_put_contents(lh_log_file(), gmdate('Y-m-d H:i:s') . ' UTC ' . $message . "\n", FILE_APPEND);
}

function lh_read_json($name)
{
    $raw = @file_get_contents(lh_file($name));
    $data = $raw === false ? null : json_decode($raw, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
    return is_array($data) ? $data : null;
}

/** Write through a temp file so a reader never sees half a file. */
function lh_write_json($name, $data, $mode = 0600)
{
    $path = lh_file($name);
    $tmp = $path . '.' . getmypid() . '.tmp';
    $old = umask(0077);
    // A name FPP gave us in Latin-1 must not turn the whole file into nothing.
    $body = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
    $ok = $body !== false && @file_put_contents($tmp, $body) === strlen($body);
    umask($old);
    if (!$ok) {
        @unlink($tmp);
        return false;
    }
    @chmod($tmp, $mode);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/** Shown when the player cannot save Light Hunt's files, at the start of pairing and when it finishes. */
function lh_cant_save_message()
{
    return 'This player can\'t save Light Hunt\'s files. In FPP, open Status/Control, press Restart FPPD, then try again.';
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
    // No curl_close: it does nothing since PHP 8.0 and is deprecated in 8.5. The handle closes itself.
    if ($raw === false) {
        return array('code' => 0, 'json' => null, 'retryAfter' => null, 'errno' => $errno, 'error' => $error, 'serverTime' => $serverTime);
    }
    // FPP can hand back a Latin-1 file name; substitute it instead of dropping the whole answer.
    $json = json_decode($raw, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
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
    // Light Hunt never answers a 200 that way, so something else did (a proxy, a captive portal, a filter).
    return (int) $r['code'] === 200 ? 'bad_reply' : 'http_' . (int) $r['code'];
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
    if ($key === 'bad_reply') {
        return 'Something other than Light Hunt answered. Check this network for a proxy, a login page or a filter.';
    }
    if ($key === 'cloned') {
        return 'This looks like a copy of another player\'s SD card. Unpair and pair this player again.';
    }
    if ($key === 'remote') {
        return 'This player is a multisync remote. Pair the master player instead.';
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

/* ---------- Feedback: sent only when the owner presses Send ---------- */

/** Characters, not bytes. */
function lh_text_length($text)
{
    return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : (int) preg_match_all('/./su', $text);
}

/** Removes what a log line could use to identify the owner: pairing keys, home folder names, IPv4 addresses. Idempotent. */
function lh_redact_log($text)
{
    $text = preg_replace('/lhr_[A-Za-z0-9_-]+/', '<key>', (string) $text);
    $text = preg_replace('#/home/[^/\s]+#', '/home/<user>', $text);
    $text = preg_replace('#([A-Za-z]:[\\\\/]Users[\\\\/])[^\\\\/\s]+#i', '$1<user>', $text);
    return preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '<ip>', $text);
}

/** The last lines of a log file as one string, or null when it is missing, unreadable (the web user may not own it) or empty. */
function lh_log_tail($path, $lines = LH_LOG_LINES)
{
    $size = is_file($path) && is_readable($path) ? @filesize($path) : false;
    $fh = $size === false ? false : @fopen($path, 'r');
    if (!$fh) {
        return null;
    }
    $cut = $size > 65536;
    if ($cut) {
        fseek($fh, -65536, SEEK_END);
    }
    $raw = stream_get_contents($fh);
    fclose($fh);
    $all = $raw === false ? array() : preg_split('/\r?\n/', rtrim($raw));
    if ($cut) {
        array_shift($all); // the first line may be cut in half
    }
    $all = array_slice($all, -$lines);
    $text = implode("\n", $all);
    return trim($text) === '' ? null : $text;
}

/** What this player knows about itself. Only the owner's Send button ever puts it on the wire. */
function lh_feedback_context()
{
    $status = lh_read_json('status.json');
    $fpp = lh_fpp_status();
    $ctx = array('pluginVersion' => LH_VERSION, 'phpVersion' => PHP_VERSION, 'paired' => (bool) lh_config());
    if ($fpp) {
        $ctx['fppVersion'] = isset($fpp['version']) ? $fpp['version'] : null;
        $ctx['fppBranch'] = isset($fpp['branch']) ? $fpp['branch'] : null;
    }
    if ($status) {
        $ctx['lastResult'] = isset($status['result']) ? $status['result'] : null;
        $ctx['lastReason'] = isset($status['reason']) ? $status['reason'] : null;
        $ctx['lastReportAt'] = !empty($status['lastReportAt']) ? gmdate('c', (int) $status['lastReportAt']) : null;
    }
    $tail = lh_log_tail(lh_log_file());
    if ($tail !== null) {
        $ctx['log'] = $tail;
    }
    return $ctx;
}

/**
 * The body for POST /api/feedback/report. Pure. Only the keys Light Hunt accepts for source "fpp" get through,
 * values are clipped, and the log (only when $includeLog) is redacted again and cut to its last 8000 characters.
 * The pairing key is never part of it: it travels as the Authorization header.
 */
function lh_build_feedback($message, $email, $context, $includeLog)
{
    $ctx = array();
    foreach (array('pluginVersion', 'fppVersion', 'fppBranch', 'phpVersion', 'lastResult', 'lastReason', 'lastReportAt', 'paired') as $k) {
        if (!isset($context[$k]) || $context[$k] === '') {
            continue;
        }
        $ctx[$k] = (is_bool($context[$k]) || is_int($context[$k])) ? $context[$k] : lh_clip($context[$k]);
    }
    if ($includeLog && isset($context['log']) && is_string($context['log'])) {
        $log = lh_redact_log($context['log']);
        if (strlen($log) > LH_LOG_MAX) {
            $log = preg_replace('/^[^\n]*\n/', '', substr($log, -LH_LOG_MAX), 1); // drop the cut-off first line
        }
        if (trim($log) !== '') {
            $ctx['log'] = $log;
        }
    }
    $out = array('source' => 'fpp', 'message' => lh_clip(trim((string) $message), LH_FEEDBACK_MAX));
    $email = trim((string) $email);
    if ($email !== '') {
        $out['email'] = lh_clip($email, 254);
    }
    $out['context'] = $ctx;
    return $out;
}

/** The owner-facing result of a /api/feedback/report call. */
function lh_feedback_result($r)
{
    if ($r['code'] === 200) {
        return array('ok' => true);
    }
    if ($r['code'] === 429) {
        return array('ok' => false, 'reason' => 'Too many reports, try again later.');
    }
    if (isset($r['json']['code']) && $r['json']['code'] === 'invalid_email') {
        return array('ok' => false, 'reason' => 'Light Hunt did not accept that email address.');
    }
    return array('ok' => false, 'reason' => lh_reason_text(lh_reason_key($r), time()));
}

/* ---------- Falcon Player ---------- */

/** FPP's local status, or null when fppd is not answering. */
function lh_fpp_status()
{
    $r = lh_http('GET', lh_fpp_url() . '/api/fppd/status', array(), null, 3);
    return $r['code'] === 200 ? $r['json'] : null;
}

/**
 * FPP's hardware id for this player (the Pi's serial, say), from /api/fppd/status "uuid". FPP says
 * "Unknown" when it cannot find one; that is no id at all.
 */
function lh_fpp_uuid($st)
{
    $u = isset($st['uuid']) && is_string($st['uuid']) ? trim($st['uuid']) : '';
    return ($u === '' || strtolower($u) === 'unknown') ? null : $u;
}

/** True when FPP is a multisync remote: it plays what the master tells it to, so the master is the one to pair. */
function lh_fpp_is_remote($st)
{
    return isset($st['mode_name']) && is_string($st['mode_name']) && strtolower(trim($st['mode_name'])) === 'remote';
}

/**
 * Title and artist tags of an audio file, through FPP's own media endpoint. Empty strings when the file
 * has none. Null when FPP gave no real answer (a timeout or a 5xx while ffprobe is busy), so the caller
 * tries again later instead of remembering "no tags". A 200 and a 404 are real answers.
 */
function lh_fpp_tags($file)
{
    $r = lh_http('GET', lh_fpp_url() . '/api/media/' . rawurlencode($file) . '/meta', array(), null, 5);
    if ($r['code'] !== 200 && $r['code'] !== 404) {
        return null;
    }
    $tags = ($r['code'] === 200 && isset($r['json']['format']['tags']) && is_array($r['json']['format']['tags']))
        ? array_change_key_case($r['json']['format']['tags'], CASE_LOWER) : array();
    return array(
        'title' => isset($tags['title']) ? trim((string) $tags['title']) : '',
        'artist' => isset($tags['artist']) ? trim((string) $tags['artist']) : '',
    );
}

/** A playlist's entries with sub-playlists merged in, through FPP's own API. Null when FPP can't say. */
function lh_fpp_playlist($name)
{
    $r = lh_http('GET', lh_fpp_url() . '/api/playlist/' . rawurlencode($name) . '?mergeSubs=1', array(), null, 5);
    return $r['code'] === 200 && is_array($r['json']) ? $r['json'] : null;
}

/**
 * The next songs FPP will play, from its status and the running playlist. Pure, so it is tested with recorded JSON.
 * Returns up to LH_MAX_NEXT items of ['name' => sequence file, 'mediaFilename' => audio file] (either may be missing).
 * Empty when the playlist is shuffled (FPP picks the next song live), or the position doesn't fit the playlist.
 * index in the status is 1-based across lead-in, main and lead-out; a repeating playlist loops its main section.
 */
function lh_next_items($st, $playlist)
{
    if (!is_array($st) || !is_array($playlist)) {
        return array();
    }
    if (!empty($st['random']) && (int) $st['random'] > 0) {
        return array();
    }
    $sections = array();
    foreach (array('leadIn', 'mainPlaylist', 'leadOut') as $k) {
        $sections[$k] = isset($playlist[$k]) && is_array($playlist[$k]) ? array_values($playlist[$k]) : array();
    }
    $lead = count($sections['leadIn']);
    $main = count($sections['mainPlaylist']);
    $all = array_merge($sections['leadIn'], $sections['mainPlaylist'], $sections['leadOut']);
    $total = count($all);
    $pos = isset($st['current_playlist']['index']) ? (int) $st['current_playlist']['index'] : 0;
    $count = isset($st['current_playlist']['count']) ? (int) $st['current_playlist']['count'] : 0;
    if ($pos < 1 || $pos > $total || ($count > 0 && $count !== $total)) {
        return array();
    }
    $repeat = !empty($st['repeat_mode']) && (string) $st['repeat_mode'] !== '0';

    $items = array();
    $i = $pos; // 0-based index of the entry after the current one
    for ($steps = 0; $steps < 2 * $total && count($items) < LH_MAX_NEXT; $steps++) {
        if ($repeat && $main > 0 && $pos <= $lead + $main && $i === $lead + $main) {
            $i = $lead; // a repeating playlist goes back to the top of its main section, not into the lead-out
        }
        if ($i >= $total) {
            break;
        }
        $e = $all[$i++];
        $type = isset($e['type']) ? (string) $e['type'] : '';
        if (!in_array($type, array('both', 'sequence', 'media'), true)) {
            continue; // pauses, commands, scripts and the like are not songs
        }
        $item = array();
        if ($type !== 'media' && !empty($e['sequenceName'])) {
            $item['name'] = lh_clip($e['sequenceName']);
        }
        if ($type !== 'sequence' && !empty($e['mediaName'])) {
            $item['mediaFilename'] = lh_clip($e['mediaName']);
        }
        if ($item) {
            $items[] = $item;
        }
    }
    return $items;
}

function lh_clip($value, $max = 200)
{
    $value = (string) $value;
    if (preg_match('/^.{0,' . (int) $max . '}/su', $value, $m)) {
        return $m[0];
    }
    return substr($value, 0, (int) $max); // not valid UTF-8; the server strips what it cannot read
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
    return json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

/* ---------- Config and status files ---------- */

/** The pairing: ['key' => 'lhr_...', 'displayId' => int, 'displayName' => string, 'pairedAt' => iso]. Mode 0600. */
function lh_config()
{
    $c = lh_read_json('config.json');
    return ($c && isset($c['key']) && is_string($c['key']) && preg_match(LH_KEY_RE, $c['key'])) ? $c : null;
}

/**
 * What the settings page shows. Written by the daemon; holds no secrets. $now is the wall clock (shown
 * to the owner); $mono is the monotonic clock the rewrite interval is counted on.
 */
function lh_write_status_if_needed(&$S, $now, $doc, $mono = null)
{
    $mono = $mono === null ? $now : $mono;
    $doc['version'] = LH_VERSION;
    $doc['pid'] = getmypid();
    $body = json_encode($doc, JSON_INVALID_UTF8_SUBSTITUTE);
    // systemd-logind deletes a user's /dev/shm files when that user's last login session ends (RemoveIPC,
    // on by default), so an owner logging out of SSH as fpp takes the file with it. Write it again then.
    if ($body === $S['lastStatusBody'] && $mono - $S['lastStatusAt'] < 60 && is_file(lh_file('status.json'))) {
        return;
    }
    $doc['updatedAt'] = $now;
    lh_write_json('status.json', $doc, 0644);
    $S['lastStatusBody'] = $body;
    $S['lastStatusAt'] = $mono;
}

/* ---------- The poll loop, one tick at a time ---------- */

function lh_new_state()
{
    return array(
        'key' => null, 'sig' => null, 'sentAt' => 0, 'nextTry' => 0, 'fails' => 0, 'rejected' => null,
        'tagCache' => array(), 'tagRetry' => array(), 'lastOk' => null, 'lastReportAt' => null, 'viewers' => null,
        'lastStatusBody' => null, 'lastStatusAt' => 0, 'badSig' => null, 'reason' => null,
        'lastPlayed' => null, 'restartPending' => false, 'notice' => null, 'lastError' => null,
        'playlistName' => null, 'playlistAt' => 0, 'playlist' => null,
    );
}

/** Seconds to wait after the $fails-th failure in a row: LH_BACKOFF, spread by +/-20% so many players do not retry in step. $rand is 0..1. */
function lh_backoff($fails, $rand = null)
{
    $base = LH_BACKOFF[min(max((int) $fails, 1), count(LH_BACKOFF)) - 1];
    $rand = $rand === null ? mt_rand() / mt_getrandmax() : $rand;
    return max(1, (int) round($base * (0.8 + 0.4 * $rand)));
}

/** Retry-After as seconds: at least 2, at most 10 minutes. A server (or a proxy) cannot park the poller for hours. */
function lh_retry_after($value)
{
    return min(LH_RETRY_AFTER_MAX, max(2, (int) $value));
}

/**
 * One pass. $io supplies 'status' => fn(): array|null, 'tags' => fn(string): array|null, 'post' => fn(key, json): http result,
 * and optionally 'rand' => fn(): float 0..1 for the backoff spread.
 * $now is the wall clock. $mono is a monotonic clock in seconds (the poller passes hrtime): every interval
 * is counted on it, because a Pi with no clock chip steps its wall clock when NTP first answers. Tests may omit it.
 * Never throws and never blocks longer than the HTTP timeouts, so a bad network cannot stall anything else.
 */
function lh_tick(&$S, $now, $io, $mono = null)
{
    $mono = $mono === null ? $now : $mono;
    $cfg = lh_config();
    if (!$cfg) {
        $S['sig'] = null;
        return lh_write_status_if_needed($S, $now, array('result' => 'unpaired'), $mono);
    }
    if ($cfg['key'] === $S['rejected']) {
        return lh_write_status_if_needed($S, $now, array('result' => 'revoked'), $mono);
    }

    $fpp = call_user_func($io['status']);
    if ($fpp === null) {
        // Nothing to say while fppd is down. Light Hunt shows the setlist after 60 seconds.
        return lh_write_status_if_needed($S, $now, array('result' => 'fpp_unreachable'), $mono);
    }

    // Two reasons to stay quiet. Each says so once in the log, and says hello again the moment it clears.
    $uuid = lh_fpp_uuid($fpp);
    $notice = null;
    if (lh_fpp_is_remote($fpp)) {
        $notice = 'remote';
    } elseif ($uuid !== null && !empty($cfg['uuid']) && $cfg['uuid'] !== $uuid) {
        $notice = 'cloned';
    }
    if ($notice !== null) {
        if ($S['notice'] !== $notice) {
            lh_log($notice === 'remote'
                ? 'This player is a multisync remote, so it sends nothing. Pair the master player instead.'
                : 'This player\'s hardware id differs from the one it was paired with: the SD card looks like a copy of another player\'s. Sending stopped. Unpair and pair again.');
        }
        $S['notice'] = $notice;
        $S['sig'] = null;
        return lh_write_status_if_needed($S, $now, array('result' => $notice, 'reason' => $notice), $mono);
    }
    $S['notice'] = null;
    if ($uuid !== null && empty($cfg['uuid'])) {
        // Paired before 0.4.1, or the pairing could not read the id: pin the first one this player shows.
        $fresh = lh_config();
        if ($fresh && $fresh['key'] === $cfg['key']) {
            $fresh['uuid'] = $uuid;
            lh_write_json('config.json', $fresh);
        }
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
    // The same song twice in a row, or one song on a loop, keeps its signature. Its clock jumping back is the restart.
    $played = lh_seconds(isset($fpp['seconds_played']) ? $fpp['seconds_played'] : null);
    if ($played !== null && $S['lastPlayed'] !== null && $played < $S['lastPlayed'] - LH_RESTART_DROP) {
        $S['restartPending'] = true;
    }
    $S['lastPlayed'] = $played;
    $due = ($sig !== $S['sig'] || $S['restartPending'] || $mono - $S['sentAt'] >= LH_HEARTBEAT_SECONDS) && $mono >= $S['nextTry'];
    if ($report['state'] === 'playing' && isset($report['mediaFilename'])) {
        // Tags are read once per file, when it is first about to be reported. Only a real answer is
        // remembered; a timeout or 5xx from FPP is tried again after a minute.
        $file = $report['mediaFilename'];
        if (!isset($S['tagCache'][$file]) && $due && (!isset($S['tagRetry'][$file]) || $mono >= $S['tagRetry'][$file])) {
            if (count($S['tagCache']) + count($S['tagRetry']) >= 100) {
                $S['tagCache'] = array();
                $S['tagRetry'] = array();
            }
            $tags = call_user_func($io['tags'], $file);
            if ($tags === null) {
                $S['tagRetry'][$file] = $mono + LH_TAG_RETRY_SECONDS;
            } else {
                $S['tagCache'][$file] = $tags;
                unset($S['tagRetry'][$file]);
            }
        }
        if (isset($S['tagCache'][$file])) {
            $report = lh_build_report($fpp, $S['tagCache'][$file]);
        }
    }

    // Up next: the following songs in the running playlist, read from FPP at most once a minute.
    if (isset($io['playlist']) && $report['state'] !== 'off' && isset($report['showName'])) {
        $name = $report['showName'];
        if ($S['playlistName'] !== $name || $now - $S['playlistAt'] >= LH_PLAYLIST_SECONDS) {
            $S['playlist'] = call_user_func($io['playlist'], $name);
            $S['playlistName'] = $name;
            $S['playlistAt'] = $now;
        }
        $next = lh_next_items($fpp, $S['playlist']);
        foreach ($next as $k => $item) {
            if (!isset($item['mediaFilename'])) {
                continue;
            }
            $file = $item['mediaFilename'];
            if (!isset($S['tagCache'][$file]) && $due) {
                if (count($S['tagCache']) >= 100) {
                    $S['tagCache'] = array();
                }
                $S['tagCache'][$file] = call_user_func($io['tags'], $file);
            }
            $tags = isset($S['tagCache'][$file]) ? $S['tagCache'][$file] : null;
            if ($tags && $tags['title'] !== '') {
                $next[$k]['mediaTitle'] = lh_clip($tags['title']);
                if ($tags['artist'] !== '') {
                    $next[$k]['mediaArtist'] = lh_clip($tags['artist']);
                }
            }
        }
        if ($next) {
            $report['upNext'] = $next;
        }
    }

    if ($due) {
        $r = call_user_func($io['post'], $cfg['key'], lh_encode_report($report));
        $code = $r['code'];
        // Light Hunt answers {"ok": true, ...} on success. A bare 200 is not enough: a proxy or login page also says 200.
        if ($code === 200 && isset($r['json']['ok']) && $r['json']['ok'] === true) {
            if ($S['lastOk'] === false) {
                lh_log('Reports are reaching Light Hunt again');
            }
            $S['lastOk'] = true;
            $S['reason'] = null;
            $S['fails'] = 0;
            $S['sig'] = $sig;
            $S['sentAt'] = $mono;
            $S['lastReportAt'] = $now;
            $S['badSig'] = null;
            $S['restartPending'] = false;
            $S['viewers'] = isset($r['json']['viewers']) ? (int) $r['json']['viewers'] : null;
        } elseif ($code === 401) {
            $S['rejected'] = $cfg['key'];
            lh_log('Light Hunt no longer accepts this pairing (the owner unpaired it). Pair again from the plugin page.');
        } elseif ($code === 429) {
            $S['nextTry'] = $mono + lh_retry_after(isset($r['retryAfter']) ? $r['retryAfter'] : 0);
        } elseif ($code === 400 || $code === 413) {
            // The report itself was refused; retrying the same report cannot help. Wait for the next song.
            if ($S['badSig'] !== $sig) {
                lh_log('Light Hunt refused a report (HTTP ' . $code . ')');
            }
            $S['badSig'] = $sig;
            $S['sig'] = $sig;
            $S['sentAt'] = $mono;
            $S['restartPending'] = false;
        } else {
            $S['fails']++;
            $S['nextTry'] = $mono + lh_backoff($S['fails'], isset($io['rand']) ? call_user_func($io['rand']) : null);
            $S['reason'] = lh_reason_key($r);
            if ($S['lastOk'] !== false) {
                $what = $code === 0 ? 'curl error ' . (isset($r['errno']) ? (int) $r['errno'] : 0) . ': ' . (isset($r['error']) ? $r['error'] : '')
                    : ($code === 200 ? 'an answer that was not from Light Hunt' : 'HTTP ' . $code);
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
    lh_write_status_if_needed($S, $now, $doc, $mono);
}

/**
 * lh_tick for the poller loop. A tick that throws is logged once, not every 5 seconds all night, and
 * once more when ticks work again.
 */
function lh_safe_tick(&$S, $now, $io, $mono = null)
{
    try {
        lh_tick($S, $now, $io, $mono);
        if ($S['lastError'] !== null) {
            lh_log('Poller error cleared');
            $S['lastError'] = null;
        }
    } catch (\Throwable $e) {
        if ($S['lastError'] !== $e->getMessage()) {
            lh_log('Poller error: ' . $e->getMessage());
        }
        $S['lastError'] = $e->getMessage();
    }
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
