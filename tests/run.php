<?php
/**
 * Plain PHP tests, no framework. Run with: php tests/run.php
 * Works on PHP 7.4 through 8.x. Uses recorded /api/fppd/status shapes from tests/fixtures.
 */

$tmp = sys_get_temp_dir() . '/lh-test-' . getmypid();
@mkdir($tmp . '/logs', 0775, true);
@mkdir($tmp . '/plugindata/lighthunt-fpp-plugin', 0775, true);
putenv('FPP_MEDIA_DIR=' . $tmp);
require __DIR__ . '/../lib/lighthunt.php';

use function LightHunt\{lh_backoff, lh_base_url, lh_build_feedback, lh_build_report, lh_cant_save_message, lh_feedback_result, lh_fpp_is_remote, lh_fpp_uuid, lh_http, lh_log_tail, lh_redact_log, lh_config, lh_clock_warning, lh_daemon_running, lh_encode_report, lh_file, lh_local_deadline,
    lh_log_file, lh_new_state, lh_orphaned, lh_pending_deadline, lh_read_json, lh_reason_key, lh_reason_text, lh_retry_after, lh_safe_tick, lh_signature,
    lh_start_poller, lh_status_path, lh_tick, lh_data_dir, lh_write_json, lh_next_items};
use const LightHunt\LH_VERSION;

// Nothing here may reach lightshunt.com, even if a check goes wrong.
putenv('LIGHTHUNT_BASE_URL=http://127.0.0.1:1');
putenv('LIGHTHUNT_FPP_URL=http://127.0.0.1:1');
// status.json goes to /dev/shm when it exists; point that at nothing so these tests use the data folder (one test turns it on).
putenv('LIGHTHUNT_SHM_DIR=' . $tmp . '/no-shm');

$failures = 0;
$checks = 0;
function check($ok, $label)
{
    global $failures, $checks;
    $checks++;
    if (!$ok) {
        $failures++;
        echo "FAIL: $label\n";
    }
}
function same($actual, $expected, $label)
{
    check($actual === $expected, $label . ' (got ' . json_encode($actual) . ', want ' . json_encode($expected) . ')');
}
function fixture($name)
{
    return json_decode(file_get_contents(__DIR__ . '/fixtures/' . $name . '.json'), true);
}

/* ---------- Constants live in the namespace, never global (fpp-data#293 review, item 2) ---------- */

foreach (array('LH_REPO', 'LH_VERSION', 'LH_KEY_RE', 'LH_HEARTBEAT_SECONDS', 'LH_POLL_SECONDS', 'LH_BACKOFF', 'LH_RETRY_AFTER_MAX', 'LH_TAG_RETRY_SECONDS', 'LH_RESTART_DROP') as $name) {
    check(!defined($name), "$name is not a global constant");
    check(defined('LightHunt\\' . $name), "LightHunt\\$name is defined");
}

/* ---------- lh_build_report: the field mapping ---------- */

$r = lh_build_report(fixture('playing-sequence-and-media'));
same($r, array(
    'source' => 'fpp', 'state' => 'playing', 'name' => 'Carol of the Bells.fseq',
    'mediaFilename' => 'Carol of the Bells.mp3', 'remaining' => 134, 'duration' => 212,
    'showName' => 'Christmas Show', 'relayVersion' => LH_VERSION,
), 'sequence and media (the FPP version is not sent)');

$r = lh_build_report(fixture('playing-sequence-and-media'), array('title' => 'Carol of the Bells', 'artist' => 'Mannheim Steamroller'));
same($r['mediaTitle'], 'Carol of the Bells', 'tag title');
same($r['mediaArtist'], 'Mannheim Steamroller', 'tag artist');
$r = lh_build_report(fixture('playing-sequence-and-media'), array('title' => '', 'artist' => 'Someone'));
check(!isset($r['mediaTitle']) && !isset($r['mediaArtist']), 'an artist without a title is not sent');

$r = lh_build_report(fixture('playing-sequence-only'));
same($r['state'], 'playing', 'sequence only is playing');
check(!isset($r['mediaFilename']), 'no media file when there is none');
same($r['name'], 'Halloween/Monster Mash - Bobby Pickett.fseq', 'sequence name kept as FPP gives it');

$r = lh_build_report(fixture('playing-media-only'));
same($r['state'], 'playing', 'media only is playing');
check(!isset($r['name']), 'no sequence name for media only');

same(lh_build_report(fixture('playing-script'))['state'], 'between', 'a script is between songs');
check(!isset(lh_build_report(fixture('playing-script'))['name']), 'a script name is never sent as a song');
same(lh_build_report(fixture('global-pause'))['state'], 'between', 'global pause is between songs');
same(lh_build_report(fixture('paused'))['state'], 'between', 'paused is between');
same(lh_build_report(fixture('stopping-gracefully'))['state'], 'playing', 'stopping gracefully still plays out');
$idle = lh_build_report(fixture('idle'));
same($idle['state'], 'off', 'idle is off');
check(!isset($idle['name']) && !isset($idle['remaining']) && !isset($idle['showName']), 'off carries no song or show');
same(lh_build_report(fixture('background-music'))['state'], 'off', 'background audio is not the show');
same(lh_build_report(fixture('testing'))['state'], 'off', 'channel test is off');

$odd = fixture('playing-sequence-and-media');
$odd['seconds_remaining'] = '-3';
$odd['seconds_played'] = 'x';
$r = lh_build_report($odd);
check(!isset($r['remaining']) && !isset($r['duration']), 'bad seconds are dropped');
check(strpos(lh_encode_report(lh_build_report(fixture('playing-sequence-and-media'))), 'fppVersion') === false, 'the FPP version never reaches the now-playing payload (feedback sends it, only on Send)');

$long = fixture('playing-sequence-and-media');
$long['current_song'] = str_repeat('a', 500) . '.mp3';
same(strlen(lh_build_report($long)['mediaFilename']), 200, 'long names are clipped to 200');
$long['current_song'] = str_repeat("\u{1F384}", 300);
same(lh_text_length_via(lh_build_report($long)['mediaFilename']), 200, 'clipping never cuts an emoji');

$enc = lh_encode_report(lh_build_report(fixture('playing-sequence-and-media'), array('title' => 'Carol of the Bells', 'artist' => 'Mannheim Steamroller')));
same(json_decode($enc, true)['mediaTitle'], 'Carol of the Bells', 'report encodes to JSON');

same(lh_signature(lh_build_report(fixture('playing-sequence-and-media'))), 'playing|Carol of the Bells.fseq|Carol of the Bells.mp3', 'signature');
check(lh_signature(lh_build_report(fixture('playing-sequence-and-media'))) !== lh_signature(lh_build_report(fixture('playing-sequence-only'))), 'signature differs per song');

/* ---------- lh_tick: when it sends, retries and stops ---------- */

$KEY = 'lhr_' . str_repeat('k', 43);
$KEY2 = 'lhr_' . str_repeat('m', 43);
function pair($key)
{
    lh_write_json('config.json', array('key' => $key, 'displayId' => 12326, 'displayName' => 'Elm Street', 'pairedAt' => 'x'));
}
function unpair()
{
    @unlink(lh_file('config.json'));
}
function harness($fixtureName, &$posts, $reply)
{
    $cur = new stdClass();
    $cur->fixture = $fixtureName;
    $cur->reply = $reply;
    $cur->tagCalls = 0;
    $cur->tags = array('title' => 'Tagged', 'artist' => 'Artist'); // null means FPP gave no real answer
    $cur->status = null; // set to an array to use that instead of a fixture
    $io = array(
        'status' => function () use ($cur) { return $cur->status !== null ? $cur->status : ($cur->fixture === null ? null : fixture($cur->fixture)); },
        'tags' => function ($file) use ($cur) { $cur->tagCalls++; return $cur->tags; },
        'rand' => function () { return 0.5; }, // no spread in the backoff, so the waits are exact
        'post' => function ($key, $json) use ($cur, &$posts) {
            $posts[] = array('key' => $key, 'body' => json_decode($json, true));
            return $cur->reply;
        },
    );
    return array($cur, $io);
}
$ok = array('code' => 200, 'json' => array('ok' => true, 'viewers' => 3), 'retryAfter' => null);

// Not paired: reads nothing, sends nothing.
unpair();
$posts = array();
list($cur, $io) = harness('playing-sequence-and-media', $posts, $ok);
$S = lh_new_state();
lh_tick($S, 1000, $io);
same(count($posts), 0, 'unpaired sends nothing');
same(lh_read_json('status.json')['result'], 'unpaired', 'unpaired status written');

// First tick after pairing sends at once, with the tag data and the Bearer key.
pair($KEY);
lh_tick($S, 1005, $io);
same(count($posts), 1, 'first tick sends');
same($posts[0]['key'], $KEY, 'sends the paired key');
same($posts[0]['body']['mediaTitle'], 'Tagged', 'tags read once and sent');
same($cur->tagCalls, 1, 'one tag lookup');
same(lh_read_json('status.json')['viewers'], 3, 'viewer count kept for the page');
check(strpos(file_get_contents(lh_file('status.json')), $KEY) === false, 'status file holds no key');
$mode = substr(sprintf('%o', fileperms(lh_file('config.json'))), -4);
same($mode, '0600', 'config file is 0600');

// Same song, 5 seconds later: nothing. After 20 seconds: heartbeat. Tags are not looked up again.
lh_tick($S, 1010, $io);
same(count($posts), 1, 'no resend inside 20 seconds');
lh_tick($S, 1024, $io);
same(count($posts), 1, 'still none at 19 seconds');
lh_tick($S, 1025, $io);
same(count($posts), 2, 'heartbeat at 20 seconds');
same($cur->tagCalls, 1, 'tags cached per file');

// A new song sends immediately.
$cur->fixture = 'playing-sequence-only';
lh_tick($S, 1040, $io);
same(count($posts), 3, 'song change sends at once');
same($posts[2]['body']['name'], 'Halloween/Monster Mash - Bobby Pickett.fseq', 'new song body');

// Show off sends an off report.
$cur->fixture = 'idle';
lh_tick($S, 1045, $io);
same($posts[3]['body']['state'], 'off', 'off is reported');

// fppd down: say nothing, keep waiting.
$cur->fixture = null;
lh_tick($S, 1100, $io);
same(count($posts), 4, 'nothing sent while fppd is down');
same(lh_read_json('status.json')['result'], 'fpp_unreachable', 'status says FPP is unreachable');

// Server errors back off 5, 10, 20, 40, 60, 60 seconds and recover quietly.
$cur->fixture = 'playing-sequence-and-media';
$cur->reply = array('code' => 503, 'json' => null, 'retryAfter' => null);
$S = lh_new_state();
$posts = array();
$t = 2000;
lh_tick($S, $t, $io);              // fail 1 -> wait 5
same(count($posts), 1, 'attempt 1');
lh_tick($S, $t + 4, $io);
same(count($posts), 1, 'still waiting at 4s');
lh_tick($S, $t + 5, $io);          // fail 2 -> wait 10
same(count($posts), 2, 'attempt 2 after 5s');
lh_tick($S, $t + 14, $io);
same(count($posts), 2, 'still waiting at 9s');
lh_tick($S, $t + 15, $io);         // fail 3 -> wait 20
same(count($posts), 3, 'attempt 3 after 10s');
lh_tick($S, $t + 35, $io);         // fail 4 -> wait 40
same(count($posts), 4, 'attempt 4 after 20s');
lh_tick($S, $t + 75, $io);         // fail 5 -> wait 60
same(count($posts), 5, 'attempt 5 after 40s');
lh_tick($S, $t + 135, $io);        // fail 6 -> still 60
same(count($posts), 6, 'attempt 6 after 60s');
lh_tick($S, $t + 194, $io);
same(count($posts), 6, 'capped at 60s');
same(lh_read_json('status.json')['result'], 'offline', 'status says offline while failing');
$cur->reply = $ok;
lh_tick($S, $t + 195, $io);
same(count($posts), 7, 'recovers');
same(lh_read_json('status.json')['result'], 'ok', 'status ok again');

// Network failure (code 0) is retried the same way.
$cur->reply = array('code' => 0, 'json' => null, 'retryAfter' => null);
$S = lh_new_state();
$posts = array();
lh_tick($S, 3000, $io);
lh_tick($S, 3001, $io);
same(count($posts), 1, 'network failure backs off');

// 429 waits for Retry-After and then sends the same report.
$cur->reply = array('code' => 429, 'json' => null, 'retryAfter' => 7);
$S = lh_new_state();
$posts = array();
lh_tick($S, 4000, $io);
lh_tick($S, 4006, $io);
same(count($posts), 1, '429 waits for Retry-After');
$cur->reply = $ok;
lh_tick($S, 4007, $io);
same(count($posts), 2, 'then sends');

// 400 is not retried for the same song.
$cur->reply = array('code' => 400, 'json' => null, 'retryAfter' => null);
$S = lh_new_state();
$posts = array();
lh_tick($S, 5000, $io);
lh_tick($S, 5010, $io);
same(count($posts), 1, 'a refused report is not hammered');

// 401: stops sending until a new pairing.
$cur->reply = array('code' => 401, 'json' => array('error' => 'Invalid or revoked key'), 'retryAfter' => null);
$S = lh_new_state();
$posts = array();
lh_tick($S, 6000, $io);
lh_tick($S, 6100, $io);
lh_tick($S, 6200, $io);
same(count($posts), 1, '401 stops all sending');
same(lh_read_json('status.json')['result'], 'revoked', 'status says revoked');
$cur->reply = $ok;
pair($KEY2);
lh_tick($S, 6205, $io);
same(count($posts), 2, 'a new pairing sends at once');
same($posts[1]['key'], $KEY2, 'with the new key');
same(lh_read_json('status.json')['result'], 'ok', 'revoked clears after re-pairing');

// Unpairing stops sending.
unpair();
lh_tick($S, 6300, $io);
same(count($posts), 2, 'unpaired again sends nothing');

/* ---------- The settings page API: status freshness and unpairing ---------- */

function json($data)
{
    return $data; // FPP's json() helper writes a response; the tests just want the array
}
/* ---------- lh_next_items: what plays after the current song ---------- */

function pl_entry($type, $seq = null, $media = null)
{
    $e = array('type' => $type, 'enabled' => 1);
    if ($seq !== null) $e['sequenceName'] = $seq;
    if ($media !== null) $e['mediaName'] = $media;
    return $e;
}
function pl_status($index, $count, $repeat = 0, $random = 0)
{
    return array('current_playlist' => array('playlist' => 'Show', 'index' => (string) $index, 'count' => (string) $count),
        'repeat_mode' => $repeat, 'random' => $random);
}
$showPl = array(
    'leadIn' => array(pl_entry('both', 'Intro.fseq', 'Intro.mp3')),
    'mainPlaylist' => array(
        pl_entry('both', 'Carol of the Bells.fseq', 'Carol of the Bells.mp3'),
        pl_entry('pause'),
        pl_entry('sequence', 'Tree Twinkle.fseq'),
        pl_entry('media', null, 'Sleigh Ride.mp3'),
        pl_entry('command'),
        pl_entry('both', 'Feliz Navidad.fseq', 'Feliz Navidad.mp3'),
    ),
    'leadOut' => array(pl_entry('both', 'Goodnight.fseq', 'Goodnight.mp3')),
);
// Index 2 = first main song (1-based across lead-in, main, lead-out). Skips the pause and the command.
same(lh_next_items(pl_status(2, 8), $showPl), array(
    array('name' => 'Tree Twinkle.fseq'),
    array('mediaFilename' => 'Sleigh Ride.mp3'),
    array('name' => 'Feliz Navidad.fseq', 'mediaFilename' => 'Feliz Navidad.mp3'),
), 'next three songs, non-songs skipped');
// Last main song, no repeat: the lead-out comes next.
same(lh_next_items(pl_status(7, 8), $showPl), array(array('name' => 'Goodnight.fseq', 'mediaFilename' => 'Goodnight.mp3')), 'no repeat runs into the lead-out');
// Last main song, repeating: back to the top of the main section, never the lead-in or lead-out.
same(lh_next_items(pl_status(7, 8, 1), $showPl), array(
    array('name' => 'Carol of the Bells.fseq', 'mediaFilename' => 'Carol of the Bells.mp3'),
    array('name' => 'Tree Twinkle.fseq'),
    array('mediaFilename' => 'Sleigh Ride.mp3'),
), 'repeat loops the main section');
// Lead-in: the main section follows.
same(lh_next_items(pl_status(1, 8), $showPl)[0], array('name' => 'Carol of the Bells.fseq', 'mediaFilename' => 'Carol of the Bells.mp3'), 'lead-in leads to main');
// In the lead-out: only what is left of it; nothing after the last item.
same(lh_next_items(pl_status(8, 8, 1), $showPl), array(), 'last lead-out item has nothing next');
// Shuffled: FPP picks the next song live, so say nothing.
same(lh_next_items(pl_status(2, 8, 0, 1), $showPl), array(), 'shuffled playlist has no up next');
// The playlist on disk no longer matches what FPP is running: say nothing rather than guess.
same(lh_next_items(pl_status(2, 9), $showPl), array(), 'count mismatch has no up next');
same(lh_next_items(pl_status(0, 8), $showPl), array(), 'idle position has no up next');
same(lh_next_items(pl_status(2, 8), null), array(), 'unreadable playlist has no up next');
// A repeating playlist whose main section has no songs must not loop forever.
same(lh_next_items(pl_status(1, 2, 1), array('mainPlaylist' => array(pl_entry('pause'), pl_entry('command')))), array(), 'no songs, no loop');

// The poller adds up next with tags, and reads the playlist at most once a minute.
pair($KEY);
@unlink(lh_file('status.json'));
$posts = array();
list($cur, $io) = harness('playing-sequence-and-media', $posts, $ok);
$plCalls = 0;
$io['playlist'] = function ($name) use (&$plCalls) {
    $plCalls++;
    return array('mainPlaylist' => array_merge(
        array(pl_entry('both', 'Carol of the Bells.fseq', 'Carol of the Bells.mp3')),
        array_fill(0, 11, pl_entry('both', 'Next Song.fseq', 'Next Song.mp3'))
    ));
};
$S = lh_new_state();
lh_tick($S, 7000, $io);
$sent = end($posts)['body'];
same(isset($sent['upNext']) ? count($sent['upNext']) : 0, 3, 'report carries three up-next songs');
same($sent['upNext'][0]['mediaTitle'], 'Tagged', 'up-next song carries its title tag');
same($sent['upNext'][0]['mediaArtist'], 'Artist', 'up-next song carries its artist tag');
lh_tick($S, 7005, $io);
lh_tick($S, 7030, $io);
same($plCalls, 1, 'playlist read once within a minute');
lh_tick($S, 7061, $io);
same($plCalls, 2, 'playlist read again after a minute');
unpair();

require __DIR__ . '/../api.php';

unpair();
@unlink(lh_file('status.json'));
$s = lighthuntfpppluginStatus();
same($s['paired'], false, 'api: not paired');
same($s['pollerRunning'], false, 'api: no poller');

pair($KEY);
lh_write_json('config.json', array('key' => $KEY, 'displayId' => 12326, 'displayName' => 'Elm Street', 'pairedAt' => gmdate('c', time() + 100)));
lh_write_json('status.json', array('result' => 'revoked', 'updatedAt' => time()), 0644);
same(lighthuntfpppluginStatus()['result'], null, 'api: a status older than the pairing is ignored');
lh_write_json('config.json', array('key' => $KEY, 'displayId' => 12326, 'displayName' => 'Elm Street', 'pairedAt' => gmdate('c', time() - 100)));
$s = lighthuntfpppluginStatus();
same($s['result'], 'revoked', 'api: a status newer than the pairing is shown');
same($s['display']['name'], 'Elm Street', 'api: display name shown');
check(strpos(json_encode($s), 'lhr_') === false, 'api: the key never reaches the browser');
lh_write_json('status.json', array('result' => 'ok', 'updatedAt' => time() - 1000), 0644);
same(lighthuntfpppluginStatus()['result'], null, 'api: an old status is ignored');

lh_write_json('pending.json', array('code' => '123456', 'deviceSecret' => 's', 'expiresAt' => gmdate('c', time() + 300), 'lastCheck' => 0));
$s = lighthuntfpppluginStatus();
same($s['pending']['code'], '123456', 'api: pending code shown');
check(strpos(json_encode($s), '"s"') === false && !isset($s['pending']['deviceSecret']), 'api: the device secret never reaches the browser');
lh_write_json('pending.json', array('code' => '123456', 'deviceSecret' => 's', 'expiresAt' => gmdate('c', time() - 5), 'lastCheck' => 0));
same(lighthuntfpppluginPairCheck(), array('status' => 'expired'), 'api: an expired code is dropped');
check(!is_file(lh_file('pending.json')), 'api: expired pending file removed');

lighthuntfpppluginUnpair();
check(!is_file(lh_file('config.json')) && !is_file(lh_file('status.json')), 'api: unpair removes the key and status');
same(lighthuntfpppluginStatus()['paired'], false, 'api: unpaired after unpair');

/* ---------- pairstart refuses while paired; ensure is a POST that never pairs ---------- */

unpair();
$r = lighthuntfpppluginPairStart();
check($r['ok'] === false && strpos($r['error'], 'already paired') === false, 'api: unpaired pairstart goes on to ask Light Hunt (and fails here, nothing listens)');
check(!is_file(lh_file('pending.json')), 'api: a failed pairstart leaves no pending file');
pair($KEY);
same(lighthuntfpppluginPairStart(), array('ok' => false, 'error' => 'This player is already paired. Press Unpair first.'), 'api: a paired player refuses to start another pairing');
check(!is_file(lh_file('pending.json')), 'api: the refusal writes nothing');
same(lh_config()['key'], $KEY, 'api: the refusal keeps the existing pairing');
touch(lh_file('stopped'));
same(lighthuntfpppluginEnsure(), array('ok' => true), 'api: ensure answers ok');
check(!lh_daemon_running(), 'api: ensure does not start the poller while stopped');
unlink(lh_file('stopped'));
unpair();
same(lh_base_url(), 'http://127.0.0.1:1', 'the base URL comes from the environment variable');
@unlink(lh_file('status.json'));
file_put_contents(lh_file('base_url'), 'http://example.invalid');
same(lh_base_url(), 'http://127.0.0.1:1', 'a base_url file is ignored');
putenv('LIGHTHUNT_BASE_URL');
same(lh_base_url(), 'https://lightshunt.com', 'production by default');
putenv('LIGHTHUNT_BASE_URL=http://127.0.0.1:1');
unlink(lh_file('base_url'));

/* ---------- The log folder is found the way FPP finds it ---------- */

$plain = lh_log_file();
same($plain, $tmp . '/logs/plugin-lighthunt-fpp-plugin.log', 'log: <media>/logs without LOGDIR or settings');
$GLOBALS['settings'] = array('logDirectory' => '/fpp/logs/');
same(lh_log_file(), '/fpp/logs/plugin-lighthunt-fpp-plugin.log', 'log: FPP settings logDirectory');
putenv('LOGDIR=/exported/logs');
same(lh_log_file(), '/exported/logs/plugin-lighthunt-fpp-plugin.log', 'log: the exported LOGDIR wins');
putenv('LOGDIR');
unset($GLOBALS['settings']);
same(lh_log_file(), $plain, 'log: back to <media>/logs');

/* ---------- Network errors: curl errno to message ---------- */

same(lh_reason_key(array('code' => 0, 'errno' => 60)), 'tls', 'errno 60 is a certificate problem');
same(lh_reason_key(array('code' => 0, 'errno' => 35)), 'tls', 'errno 35 is a TLS handshake problem');
same(lh_reason_key(array('code' => 0, 'errno' => 6)), 'network', 'errno 6 (DNS) is a network problem');
same(lh_reason_key(array('code' => 0)), 'network', 'no errno is a network problem');
same(lh_reason_key(array('code' => 503, 'errno' => 0)), 'http_503', 'an HTTP error keeps its code');
$tlsText = lh_reason_text('tls', 1760000000);
check(strpos($tlsText, 'Secure connection to Light Hunt failed. Check this player\'s date and time (it says ') === 0, 'tls message wording');
check(strpos($tlsText, date('Y', 1760000000)) !== false, 'tls message shows the local date');
same(lh_reason_text('network', 1), 'Could not reach Light Hunt. Check this device has internet access.', 'network message');
same(lh_reason_text('http_503', 1), 'Light Hunt answered with an error (HTTP 503).', 'http message');

// A failing send records the reason in status.json and logs the curl error, not "HTTP 0".
$KEY3 = 'lhr_' . str_repeat('t', 43);
pair($KEY3);
$posts = array();
list($cur, $io) = harness('playing-sequence-and-media', $posts, array('code' => 0, 'json' => null, 'retryAfter' => null, 'errno' => 60, 'error' => 'SSL certificate problem: certificate is not yet valid', 'serverTime' => null));
$S = lh_new_state();
lh_tick($S, 8000, $io);
same(lh_read_json('status.json')['reason'], 'tls', 'status.json carries the reason');
same(lh_read_json('status.json')['result'], 'offline', 'and says offline');
lh_write_json('config.json', array('key' => $KEY3, 'displayId' => 1, 'displayName' => 'x', 'pairedAt' => gmdate('c', time() - 100)));
lh_write_json('status.json', array_merge(lh_read_json('status.json'), array('updatedAt' => time())), 0644);
check(strpos((string) lighthuntfpppluginStatus()['reason'], 'Secure connection to Light Hunt failed') === 0, 'api: the status shows the reason as a sentence');
$log = (string) @file_get_contents(lh_log_file());
check(strpos($log, 'curl error 60: SSL certificate problem') !== false && strpos($log, 'HTTP 0') === false, 'the log holds the curl error, not HTTP 0');
$cur->reply = $ok;
lh_tick($S, 8030, $io);
same(lh_read_json('status.json')['reason'], null, 'the reason clears when sending works again');
unpair();

/* ---------- Clock skew: codes expire on the player's clock ---------- */

$now = 1760000000;
$exp = gmdate('c', $now + 600 + 3600); // Light Hunt's clock is an hour ahead of the player's
same(lh_local_deadline($exp, $now + 3600, $now), $now + 600, 'a code lives 10 minutes on the player\'s clock whatever its clock says');
same(lh_local_deadline(gmdate('c', $now + 600), null, $now), $now + 600, 'no Date header: fall back to the local clock');
same(lh_local_deadline('garbage', 5, $now), $now + 600, 'unreadable expiry falls back to 10 minutes');
same(lh_pending_deadline(array('expiresAt' => gmdate('c', $now), 'deadline' => $now + 42)), $now + 42, 'the stored local deadline wins');
same(lh_pending_deadline(array('expiresAt' => gmdate('c', $now))), $now, 'older pending files fall back to expiresAt');
same(lh_clock_warning($now, $now + 300), null, '5 minutes of skew is fine');
check(strpos((string) lh_clock_warning($now, $now + 301), 'ahead of') !== false, 'a clock over 5 minutes fast warns');
check(strpos((string) lh_clock_warning($now, $now - 3600), 'behind') !== false, 'a clock over 5 minutes slow warns');
same(lh_clock_warning(null, $now), null, 'no server time, no warning');

// End to end: a code past expiresAt but inside its local deadline is still live, and the reverse.
lh_write_json('pending.json', array('code' => '654321', 'deviceSecret' => 's', 'expiresAt' => gmdate('c', time() - 3000), 'deadline' => time() + 500, 'lastCheck' => 0));
same(lighthuntfpppluginStatus()['pending']['code'], '654321', 'api: a code past expiresAt but inside its local deadline is still live');
lh_write_json('pending.json', array('code' => '654321', 'deviceSecret' => 's', 'expiresAt' => gmdate('c', time() + 3000), 'deadline' => time() - 5, 'lastCheck' => 0));
same(lighthuntfpppluginPairCheck(), array('status' => 'expired'), 'api: a code past its local deadline is expired');
@unlink(lh_file('pending.json'));

// paircheck writes files, so it is a POST.
$methods = array();
foreach (getEndpointslighthuntfppplugin() as $e) {
    $methods[$e['endpoint']] = $e['method'];
}
same($methods['paircheck'], 'POST', 'paircheck is a POST');
same($methods['status'], 'GET', 'status stays a GET');
same($methods['ensure'], 'POST', 'ensure is a POST');
same($methods['pairstart'], 'POST', 'pairstart is a POST');

/* ---------- Feedback: sent only on Send, redacted, never carries the key ---------- */

same($methods['feedbackpreview'], 'GET', 'feedbackpreview is a GET');
same($methods['feedback'], 'POST', 'feedback is a POST');

same(lh_redact_log('key lhr_' . str_repeat('k', 43) . ' used'), 'key <key> used', 'redact: pairing key');
same(lh_redact_log('lhr_abc-DEF_123'), '<key>', 'redact: short lhr_ token too');
same(lh_redact_log('open /home/fpp/media/x.fseq and /home/joey/y'), 'open /home/<user>/media/x.fseq and /home/<user>/y', 'redact: Linux home folders');
same(lh_redact_log('C:\Users\Joey Smith\Docs D:/Users/ann/x'), 'C:\Users\<user> Smith\Docs D:/Users/<user>/x', 'redact: Windows home folders, any drive, either slash');
same(lh_redact_log('from 192.168.1.24 to 8.8.8.8:53'), 'from <ip> to <ip>:53', 'redact: IPv4 addresses');
same(lh_redact_log('Pair 1.2.3 version 10.2'), 'Pair 1.2.3 version 10.2', 'redact: version numbers stay');
same(lh_redact_log(lh_redact_log('/home/a 1.2.3.4 lhr_x')), '/home/<user> <ip> <key>', 'redact: running it twice changes nothing');
same(lh_redact_log("bad \xB1 byte /home/x"), "bad \xB1 byte /home/<user>", 'redact: invalid UTF-8 survives');

// lh_log_tail: last 20 lines, null for missing, empty or unreadable.
$logDir = $tmp . '/tail';
@mkdir($logDir, 0775, true);
$logPath = $logDir . '/t.log';
file_put_contents($logPath, implode("\n", array_map(function ($i) { return "line $i"; }, range(1, 30))) . "\n");
$tail = lh_log_tail($logPath);
same(substr_count($tail, "\n") + 1, 20, 'log tail: 20 lines');
check(strpos($tail, 'line 30') !== false && strpos($tail, 'line 10') === false && strpos($tail, 'line 11') !== false, 'log tail: the last 20, newest included');
same(lh_log_tail($logDir . '/missing.log'), null, 'log tail: missing file is none');
file_put_contents($logPath, "\n\n");
same(lh_log_tail($logPath), null, 'log tail: empty file is none');
file_put_contents($logPath, str_repeat("x\n", 100000) . "last\n");
$tail = lh_log_tail($logPath);
check(substr_count($tail, "\n") + 1 === 20 && substr($tail, -4) === 'last', 'log tail: a large file is read from its end');
if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    file_put_contents($logPath, "secret\n");
    chmod($logPath, 0000);
    same(lh_log_tail($logPath), null, 'log tail: an unreadable log is left out silently');
    chmod($logPath, 0600);
}

// lh_build_feedback: allowed keys only, clipped, log only when asked.
$ctx = array(
    'pluginVersion' => '0.3.0', 'fppVersion' => '10.2', 'fppBranch' => 'master', 'phpVersion' => '8.2.0',
    'lastResult' => 'offline', 'lastReason' => 'tls', 'lastReportAt' => '2026-10-26T20:15:00+00:00', 'paired' => true,
    'log' => "ok /home/fpp/x\nfrom 10.0.0.5 key lhr_" . str_repeat('z', 43),
    'key' => $KEY, 'deviceSecret' => 'shh', 'displayName' => 'Elm Street',
);
$fb = lh_build_feedback('  The song stops.  ', '  me@example.com ', $ctx, true);
same($fb['source'], 'fpp', 'feedback: source is fpp');
same($fb['message'], 'The song stops.', 'feedback: message is trimmed');
same($fb['email'], 'me@example.com', 'feedback: email is trimmed');
same($fb['context']['fppVersion'], '10.2', 'feedback: carries the FPP version');
same($fb['context']['paired'], true, 'feedback: paired stays a boolean');
same($fb['context']['log'], "ok /home/<user>/x\nfrom <ip> key <key>", 'feedback: the log is redacted again on the way out');
check(!isset($fb['context']['key']) && !isset($fb['context']['deviceSecret']) && !isset($fb['context']['displayName']), 'feedback: unknown context keys are dropped');
check(strpos(json_encode($fb), 'lhr_') === false && strpos(json_encode($fb), 'shh') === false, 'feedback: no key or secret anywhere in the body');
check(!isset(lh_build_feedback('hello there', '', $ctx, false)['context']['log']), 'feedback: no log unless asked');
check(!isset(lh_build_feedback('hello there', '', $ctx, false)['email']), 'feedback: no email key when none was typed');
same(array_keys($fb), array('source', 'message', 'email', 'context'), 'feedback: the body has exactly these keys');
$bigLog = implode("\n", array_fill(0, 400, 'a line that is 30 chars long...'));
$fb = lh_build_feedback('hello there', '', array('log' => $bigLog), true);
check(strlen($fb['context']['log']) <= 8000 && substr($fb['context']['log'], -3) === '...', 'feedback: the log is cut to 8000 characters from the end');
check(strpos($fb['context']['log'], 'a line that is 30 chars long...') === 0, 'feedback: a cut log starts on a whole line');
same(lh_text_length_via(lh_build_feedback(str_repeat("\u{1F384}", 3000), '', array(), false)['message']), 2000, 'feedback: a long message is clipped to 2000 characters');
// FPP's PHP image ships without mbstring, so count characters the way the plugin does.
function lh_text_length_via($s)
{
    return (int) preg_match_all('/./su', $s);
}

// Results shown to the owner.
same(lh_feedback_result(array('code' => 200, 'json' => array('ok' => true))), array('ok' => true), 'result: 200 is ok');
same(lh_feedback_result(array('code' => 429, 'json' => null)), array('ok' => false, 'reason' => 'Too many reports, try again later.'), 'result: 429 is friendly');
same(lh_feedback_result(array('code' => 0, 'json' => null, 'errno' => 6))['reason'], 'Could not reach Light Hunt. Check this device has internet access.', 'result: offline');
same(lh_feedback_result(array('code' => 503, 'json' => array('code' => 'unavailable')))['reason'], 'Light Hunt answered with an error (HTTP 503).', 'result: 503');
check(strpos(lh_feedback_result(array('code' => 400, 'json' => array('code' => 'invalid_email')))['reason'], 'email') !== false, 'result: a refused email says so');

// The endpoint: the browser sends words only; the player builds the rest.
unpair();
$sent = array();
$fake = function ($path, $body, $key) use (&$sent) {
    $sent[] = array('path' => $path, 'body' => json_decode($body, true), 'raw' => $body, 'key' => $key);
    return array('code' => 200, 'json' => array('ok' => true, 'id' => 'x'), 'retryAfter' => null);
};
same(lighthuntfpppluginFeedbackSend(array('message' => 'abcd'), $fake), array('ok' => false, 'reason' => 'Write at least 5 characters.'), 'api: under 5 characters is refused');
same(lighthuntfpppluginFeedbackSend(array('message' => '   abcd   '), $fake)['ok'], false, 'api: spaces do not count');
same(lighthuntfpppluginFeedbackSend(array('message' => str_repeat('a', 2001)), $fake)['reason'], 'Keep it under 2000 characters.', 'api: over 2000 characters is refused');
same(lighthuntfpppluginFeedbackSend(array('message' => 'hello there', 'email' => 'not an email'), $fake)['reason'], 'That email address does not look right.', 'api: a bad email is refused');
same(lighthuntfpppluginFeedbackSend(array('message' => array('x')), $fake)['ok'], false, 'api: a non-string message is refused');
same(count($sent), 0, 'api: refused feedback sends nothing');

// Unpaired: anonymous, email allowed, a browser-supplied context is ignored.
$r = lighthuntfpppluginFeedbackSend(array('message' => 'The song stops', 'email' => 'me@example.com', 'includeLog' => false,
    'context' => array('fppVersion' => 'forged'), 'source' => 'relay'), $fake);
same($r, array('ok' => true), 'api: feedback sent');
same($sent[0]['path'], '/api/feedback/report', 'api: posts to /api/feedback/report');
same($sent[0]['key'], null, 'api: unpaired sends no Authorization key');
same($sent[0]['body']['source'], 'fpp', 'api: the source is always fpp');
same($sent[0]['body']['email'], 'me@example.com', 'api: the typed email is sent');
same($sent[0]['body']['context']['pluginVersion'], LH_VERSION, 'api: plugin version added by the player');
same($sent[0]['body']['context']['paired'], false, 'api: paired flag read on the player');
check(!isset($sent[0]['body']['context']['log']), 'api: no log when includeLog is false');
check(strpos($sent[0]['raw'], 'forged') === false, 'api: a browser-supplied context is ignored');

// Paired: the key travels as the header only, and the log is redacted and optional.
$logHome = $tmp . '/fblogs';
@mkdir($logHome, 0775, true);
putenv('LOGDIR=' . $logHome);
file_put_contents(lh_log_file(), "2026 Paired with 192.168.1.50 as /home/pi/x\nkey lhr_" . str_repeat('q', 43) . "\n");
pair($KEY);
lh_write_json('status.json', array('result' => 'offline', 'reason' => 'tls', 'lastReportAt' => 1760000000, 'updatedAt' => time()), 0644);
$sent = array();
lighthuntfpppluginFeedbackSend(array('message' => 'hello there', 'includeLog' => true), $fake);
same($sent[0]['key'], $KEY, 'api: paired sends the key as the Authorization key');
check(strpos($sent[0]['raw'], $KEY) === false && strpos($sent[0]['raw'], 'lhr_') === false, 'api: the key is never in the body');
same($sent[0]['body']['context']['paired'], true, 'api: paired flag true');
same($sent[0]['body']['context']['lastResult'], 'offline', 'api: last result from status.json');
same($sent[0]['body']['context']['lastReason'], 'tls', 'api: last reason from status.json');
same($sent[0]['body']['context']['lastReportAt'], gmdate('c', 1760000000), 'api: last report time is ISO 8601');
check(strpos($sent[0]['body']['context']['log'], '192.168') === false && strpos($sent[0]['body']['context']['log'], '/home/pi') === false, 'api: the log is redacted');
check(strpos($sent[0]['body']['context']['log'], '<ip>') !== false && strpos($sent[0]['body']['context']['log'], '<key>') !== false, 'api: the log shows the replacements');
$sent = array();
lighthuntfpppluginFeedbackSend(array('message' => 'hello there', 'includeLog' => 'yes'), $fake);
check(!isset($sent[0]['body']['context']['log']), 'api: includeLog must be exactly true');

// The preview is the same context, without the words, and holds no key.
$pv = lighthuntfpppluginFeedbackPreview();
same($pv['ok'], true, 'preview: ok');
same($pv['context']['pluginVersion'], LH_VERSION, 'preview: shows the plugin version');
check(isset($pv['context']['log']) && strpos($pv['context']['log'], '<ip>') !== false, 'preview: shows the redacted log');
check(strpos(json_encode($pv), 'lhr_') === false && strpos(json_encode($pv), $KEY) === false && !isset($pv['message']), 'preview: no key and no message');
unlink(lh_log_file());
check(!isset(lighthuntfpppluginFeedbackPreview()['context']['log']), 'preview: no log key when there is no log');

// A failed send leaves the words with the owner and says why.
$down = function ($path, $body, $key) { return array('code' => 0, 'json' => null, 'retryAfter' => null, 'errno' => 6, 'error' => 'x', 'serverTime' => null); };
same(lighthuntfpppluginFeedbackSend(array('message' => 'hello there'), $down), array('ok' => false, 'reason' => 'Could not reach Light Hunt. Check this device has internet access.'), 'api: a network failure is reported');
$limited = function ($path, $body, $key) { return array('code' => 429, 'json' => array('code' => 'rate_limited'), 'retryAfter' => 60); };
same(lighthuntfpppluginFeedbackSend(array('message' => 'hello there'), $limited)['reason'], 'Too many reports, try again later.', 'api: rate limited');
$fblog = (string) @file_get_contents(lh_log_file());
check(strpos($fblog, 'hello there') === false && strpos($fblog, 'lhr_') === false, 'api: the log never holds the message or a key');
putenv('LOGDIR');
@unlink(lh_file('status.json'));
unpair();

/* ---------- 0.4.1 hardening ---------- */

// The heartbeat leaves room for two lost posts before Light Hunt calls the sender stale (60 s).
same(\LightHunt\LH_HEARTBEAT_SECONDS, 20, 'heartbeat is 20 seconds');
same(LH_VERSION, '0.4.1', 'plugin version');

// Intervals run on the monotonic clock: a wall clock that steps back (NTP on a Pi with no RTC) changes nothing.
pair($KEY);
$posts = array();
list($cur, $io) = harness('playing-sequence-and-media', $posts, $ok);
$S = lh_new_state();
lh_tick($S, 5000, $io, 100);
same(count($posts), 1, 'mono: first tick sends');
lh_tick($S, 100, $io, 125); // the wall clock stepped back an hour and more; 25 real seconds passed
same(count($posts), 2, 'mono: a wall clock stepping back does not stall the heartbeat');
lh_tick($S, 9999999, $io, 130); // the wall clock jumped forward; 5 real seconds passed
same(count($posts), 2, 'mono: a wall clock jumping forward does not fire early');
same(lh_read_json('status.json')['lastReportAt'], 100, 'mono: the owner sees wall-clock time');
$cur->reply = array('code' => 503, 'json' => null, 'retryAfter' => null);
$S = lh_new_state();
$posts = array();
lh_tick($S, 5000, $io, 100);   // fails, waits 5
lh_tick($S, 1, $io, 104);
same(count($posts), 1, 'mono: backoff is not shortened by a wall clock step back');
lh_tick($S, 99999, $io, 105);
same(count($posts), 2, 'mono: backoff ends on monotonic time');

// Only real answers are remembered for tags. A timeout or 5xx from FPP is asked again after a minute.
same(\LightHunt\lh_fpp_tags('a.mp3'), null, 'tags: no answer from FPP is null, not "no tags"');
$posts = array();
list($cur, $io) = harness('playing-sequence-and-media', $posts, $ok);
$cur->tags = null;
$S = lh_new_state();
lh_tick($S, 9000, $io, 9000);
same($cur->tagCalls, 1, 'tags: first look-up');
check(!isset($posts[0]['body']['mediaTitle']), 'tags: reported without tags while FPP has no answer');
lh_tick($S, 9020, $io, 9020);
lh_tick($S, 9040, $io, 9040);
same($cur->tagCalls, 1, 'tags: a failed look-up is not repeated inside a minute');
$cur->tags = array('title' => 'Tagged', 'artist' => 'Artist');
lh_tick($S, 9060, $io, 9060);
same($cur->tagCalls, 2, 'tags: tried again after a minute');
same($posts[count($posts) - 1]['body']['mediaTitle'], 'Tagged', 'tags: the retry reaches the report');
lh_tick($S, 9080, $io, 9080);
same($cur->tagCalls, 2, 'tags: a real answer is remembered');
$cur->tags = array('title' => '', 'artist' => ''); // a file with no tags is a real answer too
$cur->fixture = 'playing-media-only';
lh_tick($S, 9100, $io, 9100);
lh_tick($S, 9120, $io, 9120);
lh_tick($S, 9140, $io, 9140);
same($cur->tagCalls, 3, 'tags: "no tags" is remembered for a file');

// 200 must say ok:true. A proxy or login page answering 200 is not Light Hunt.
foreach (array(array('code' => 200, 'json' => null), array('code' => 200, 'json' => array('ok' => false)), array('code' => 200, 'json' => array('message' => 'ok'))) as $i => $bad) {
    $posts = array();
    list($cur, $io) = harness('playing-sequence-and-media', $posts, $bad + array('retryAfter' => null));
    $S = lh_new_state();
    lh_tick($S, 100, $io);
    same(lh_read_json('status.json')['result'], 'offline', "200 without ok:true #$i is not Live");
    same(lh_read_json('status.json')['reason'], 'bad_reply', "200 without ok:true #$i has its own reason");
    same(lh_read_json('status.json')['lastReportAt'], null, "200 without ok:true #$i is not counted as a report");
    lh_tick($S, 102, $io);
    same(count($posts), 1, "200 without ok:true #$i backs off");
}
check(strpos(lh_reason_text('bad_reply', 1), 'Something other than Light Hunt answered') === 0, 'bad_reply has a sentence');
$cur->reply = $ok;
lh_tick($S, 200, $io);
same(lh_read_json('status.json')['result'], 'ok', 'a real answer clears bad_reply');

// Retry-After is clamped to 10 minutes and the failure backoff is spread by 20%.
same(lh_retry_after(null), 2, 'retry-after: missing is 2 seconds');
same(lh_retry_after(0), 2, 'retry-after: at least 2');
same(lh_retry_after(30), 30, 'retry-after: honoured');
same(lh_retry_after(99999), 600, 'retry-after: at most 10 minutes');
same(lh_retry_after(-5), 2, 'retry-after: negative is 2');
$cur->reply = array('code' => 429, 'json' => null, 'retryAfter' => 99999);
$S = lh_new_state();
$posts = array();
lh_tick($S, 3000, $io);
lh_tick($S, 3599, $io);
same(count($posts), 1, 'retry-after: a huge value still waits');
lh_tick($S, 3600, $io);
same(count($posts), 2, 'retry-after: but no longer than 10 minutes');
same(lh_backoff(1, 0.0), 4, 'backoff: first wait spread low');
same(lh_backoff(1, 1.0), 6, 'backoff: first wait spread high');
same(lh_backoff(1, 0.5), 5, 'backoff: first wait at the middle');
same(lh_backoff(5, 0.0), 48, 'backoff: capped wait spread low');
same(lh_backoff(5, 1.0), 72, 'backoff: capped wait spread high');
same(lh_backoff(9, 0.5), 60, 'backoff: stays at the cap');
same(lh_backoff(0, 0.5), 5, 'backoff: zero failures counts as the first');
$spread = array();
for ($i = 0; $i < 200; $i++) {
    $spread[lh_backoff(3)] = true;
}
check(min(array_keys($spread)) >= 16 && max(array_keys($spread)) <= 24 && count($spread) > 2, 'backoff: random spread stays within 20% and varies');

// A song restarting (a loop of one song, or the same song twice) is sent at once, though its signature did not change.
$posts = array();
list($cur, $io) = harness('playing-sequence-and-media', $posts, $ok);
$st = fixture('playing-sequence-and-media');
$cur->status = $st;
$S = lh_new_state();
lh_tick($S, 7000, $io);
same(count($posts), 1, 'restart: first send');
$st['seconds_played'] = '83';
$cur->status = $st;
lh_tick($S, 7005, $io);
same(count($posts), 1, 'restart: playing on sends nothing');
$st['seconds_played'] = '2';
$cur->status = $st;
lh_tick($S, 7010, $io);
same(count($posts), 2, 'restart: seconds played dropping by more than 5 sends at once');
$st['seconds_played'] = '7';
$cur->status = $st;
lh_tick($S, 7015, $io);
$st['seconds_played'] = '4';
$cur->status = $st;
lh_tick($S, 7020, $io);
same(count($posts), 2, 'restart: a dip of 3 seconds is not a restart');
// A restart that arrives while sending is backing off is not forgotten.
$st['seconds_played'] = '90';
$cur->status = $st;
lh_tick($S, 7021, $io);
$cur->reply = array('code' => 503, 'json' => null, 'retryAfter' => null);
$st['seconds_played'] = '1';
$cur->status = $st;
lh_tick($S, 7022, $io);          // restart, the post fails, waits 5
$cur->reply = $ok;
$before = count($posts);
$st['seconds_played'] = '6';
$cur->status = $st;
lh_tick($S, 7027, $io);
same(count($posts), $before + 1, 'restart: retried after the backoff');

// Multisync remote: sends nothing, says why, and sends again the moment it is a player.
$posts = array();
list($cur, $io) = harness('multisync-remote', $posts, $ok);
$S = lh_new_state();
lh_tick($S, 100, $io);
lh_tick($S, 130, $io);
same(count($posts), 0, 'remote: a multisync remote sends nothing');
same(lh_read_json('status.json')['result'], 'remote', 'remote: status says remote');
same(lh_read_json('status.json')['reason'], 'remote', 'remote: with a reason');
same(lh_reason_text('remote', 1), 'This player is a multisync remote. Pair the master player instead.', 'remote: message');
check(lh_fpp_is_remote(fixture('multisync-remote')) && !lh_fpp_is_remote(fixture('playing-sequence-and-media')) && !lh_fpp_is_remote(array()), 'remote: only mode_name remote counts');
$cur->fixture = 'playing-sequence-and-media';
lh_tick($S, 135, $io);
same(count($posts), 1, 'remote: becoming a player sends at once');
same(substr_count((string) file_get_contents(lh_log_file()), 'multisync remote, so it sends nothing'), 1, 'remote: logged once');

// A copied SD card: the hardware id differs from the one the pairing pinned.
same(lh_fpp_uuid(array('uuid' => ' M1-00000000 ')), 'M1-00000000', 'uuid: trimmed');
same(lh_fpp_uuid(array('uuid' => 'Unknown')), null, 'uuid: "Unknown" is no id');
same(lh_fpp_uuid(array('uuid' => '')), null, 'uuid: empty is no id');
same(lh_fpp_uuid(array()), null, 'uuid: missing is no id');
$posts = array();
list($cur, $io) = harness('playing-sequence-and-media', $posts, $ok);
lh_write_json('config.json', array('key' => $KEY, 'displayId' => 1, 'displayName' => 'x', 'pairedAt' => 'x', 'uuid' => 'M1-OTHER'));
$S = lh_new_state();
lh_tick($S, 100, $io);
lh_tick($S, 130, $io);
same(count($posts), 0, 'clone: a different hardware id sends nothing');
same(lh_read_json('status.json')['result'], 'cloned', 'clone: status says cloned');
same(lh_reason_text('cloned', 1), 'This looks like a copy of another player\'s SD card. Unpair and pair this player again.', 'clone: message');
same(lh_config()['uuid'], 'M1-OTHER', 'clone: the pinned id is not overwritten');
same(substr_count((string) file_get_contents(lh_log_file()), 'looks like a copy of another player'), 1, 'clone: logged once');
// Paired before 0.4.1 (no id stored): the first id seen is adopted, and sending goes on.
lh_write_json('config.json', array('key' => $KEY, 'displayId' => 1, 'displayName' => 'x', 'pairedAt' => 'x'));
$S = lh_new_state();
lh_tick($S, 200, $io);
same(count($posts), 1, 'clone: an older pairing with no id sends');
same(lh_config()['uuid'], 'M1-00000000', 'clone: an older pairing adopts the first id it sees');
same(lh_config()['key'], $KEY, 'clone: adopting keeps the key');
// FPP with no usable id: nothing to compare, sends normally.
$noId = fixture('playing-sequence-and-media');
$noId['uuid'] = 'Unknown';
$cur->status = $noId;
lh_write_json('config.json', array('key' => $KEY, 'displayId' => 1, 'displayName' => 'x', 'pairedAt' => 'x', 'uuid' => 'M1-OTHER'));
$S = lh_new_state();
$posts = array();
lh_tick($S, 300, $io);
same(count($posts), 1, 'clone: no id from FPP means no check');
$cur->status = null;
unpair();

// A bad-UTF-8 name (Latin-1) must not blank the status file, the report or an FPP answer.
$latin = "Caf\xE9.mp3";
lh_write_json('status.json', array('song' => $latin, 'result' => 'ok'), 0644);
check(lh_read_json('status.json') !== null && strpos(lh_read_json('status.json')['song'], 'Caf') === 0, 'utf8: a Latin-1 name still writes status.json');
$enc = lh_encode_report(array('source' => 'fpp', 'state' => 'playing', 'mediaFilename' => $latin));
check($enc !== false && json_decode($enc, true)['mediaFilename'] === "Caf\u{FFFD}.mp3", 'utf8: a Latin-1 name still encodes the report');
$probe = $tmp . '/latin1.json';
file_put_contents($probe, "{\"status_name\":\"playing\",\"current_song\":\"$latin\"}");
$r = lh_http('GET', 'file://' . $probe); // curl reports code 0 for file://, but the body is still decoded
check(isset($r['json']['current_song']) && $r['json']['current_song'] === "Caf\u{FFFD}.mp3", 'utf8: an FPP answer with a Latin-1 name still decodes');
unlink($probe);

// A tick that throws is logged once, then once more when it clears.
pair($KEY);
$boom = array('status' => function () { throw new \RuntimeException('disk gone'); }, 'tags' => null, 'post' => null);
$S = lh_new_state();
for ($i = 0; $i < 5; $i++) {
    lh_safe_tick($S, 100 + $i * 5, $boom);
}
same(substr_count((string) file_get_contents(lh_log_file()), 'Poller error: disk gone'), 1, 'errors: a repeated error is logged once');
$posts = array();
list($cur, $io) = harness('playing-sequence-and-media', $posts, $ok);
lh_safe_tick($S, 200, $io);
lh_safe_tick($S, 205, $io);
same(substr_count((string) file_get_contents(lh_log_file()), 'Poller error cleared'), 1, 'errors: logged once more when it clears');
$boom2 = array('status' => function () { throw new \RuntimeException('other'); }, 'tags' => null, 'post' => null);
lh_safe_tick($S, 210, $boom2);
lh_safe_tick($S, 215, $boom);
same(substr_count((string) file_get_contents(lh_log_file()), 'Poller error: disk gone'), 2, 'errors: a different error is logged');
unpair();

// status.json lives in RAM when /dev/shm is there, and the settings page reads it from the same place.
$shm = $tmp . '/shm';
@unlink(lh_file('status.json'));
same(lh_status_path(), lh_data_dir() . '/status.json', 'status path: the data folder when there is no /dev/shm');
@mkdir($shm, 0775, true);
putenv('LIGHTHUNT_SHM_DIR=' . $shm);
same(lh_status_path(), $shm . '/lighthunt-fpp-plugin-status.json', 'status path: RAM when /dev/shm is there');
pair($KEY);
$posts = array();
list($cur, $io) = harness('playing-sequence-and-media', $posts, $ok);
$S = lh_new_state();
lh_tick($S, time(), $io);
check(is_file($shm . '/lighthunt-fpp-plugin-status.json') && !is_file(lh_data_dir() . '/status.json'), 'status path: written in RAM, not on the SD card');
// logind empties /dev/shm for fpp when an SSH session as fpp ends. The next tick writes it back, not 60 seconds later.
@unlink($shm . '/lighthunt-fpp-plugin-status.json');
lh_tick($S, time(), $io, 2);
check(is_file($shm . '/lighthunt-fpp-plugin-status.json'), 'status path: a deleted RAM file is written again on the next tick');
lh_write_json('config.json', array('key' => $KEY, 'displayId' => 12326, 'displayName' => 'Elm Street', 'pairedAt' => gmdate('c', time() - 100)));
same(lighthuntfpppluginStatus()['result'], 'ok', 'status path: the page reads it from RAM');
lighthuntfpppluginUnpair();
check(!is_file($shm . '/lighthunt-fpp-plugin-status.json'), 'status path: unpair removes it from RAM');
putenv('LIGHTHUNT_SHM_DIR=' . $tmp . '/no-shm');

// The page API shows the clone and remote messages.
lh_write_json('config.json', array('key' => $KEY, 'displayId' => 12326, 'displayName' => 'Elm Street', 'pairedAt' => gmdate('c', time() - 100)));
lh_write_json('status.json', array('result' => 'cloned', 'reason' => 'cloned', 'updatedAt' => time()), 0644);
$s = lighthuntfpppluginStatus();
same($s['result'], 'cloned', 'api: cloned result passes through');
check(strpos((string) $s['reason'], 'copy of another player\'s SD card') !== false, 'api: cloned reason is a sentence');
lh_write_json('status.json', array('result' => 'remote', 'reason' => 'remote', 'updatedAt' => time()), 0644);
check(strpos((string) lighthuntfpppluginStatus()['reason'], 'multisync remote') !== false, 'api: remote reason is a sentence');
unpair();
@unlink(lh_file('status.json'));

// Pairing: a pairing that could not be saved is not "paired". Light Hunt hands the key out once, so it waits in pending.json.
$pairedReply = function ($path, $body, $key = null) use ($KEY) {
    return array('code' => 200, 'json' => array('status' => 'paired', 'key' => $KEY, 'display' => array('id' => 12326, 'name' => 'Elm Street')), 'retryAfter' => null, 'serverTime' => null);
};
$noFpp = function () { return null; };
$fppWithId = function () { return fixture('playing-sequence-and-media'); };
unpair();
lh_write_json('pending.json', array('code' => '123456', 'deviceSecret' => 's', 'expiresAt' => gmdate('c', time() + 300), 'deadline' => time() + 300, 'lastCheck' => 0));
@mkdir(lh_file('config.json')); // a folder where the file goes: the rename cannot succeed, like a full or read-only card
$r = lighthuntfpppluginPairCheckWith($pairedReply, $fppWithId);
same($r['status'], 'pending', 'pair: an unsaved pairing is still pending');
same($r['warning'], lh_cant_save_message(), 'pair: and says the player can\'t save');
check(is_file(lh_file('pending.json')), 'pair: pending.json is kept');
check(!is_file(lh_file('config.json')), 'pair: no config was written');
check(count(glob(lh_data_dir() . '/*.tmp')) === 0, 'pair: a failed write leaves no temp file behind');
$never = function () { throw new \RuntimeException('must not ask Light Hunt again'); };
rmdir(lh_file('config.json')); // the card recovers
$r = lighthuntfpppluginPairCheckWith($never, $fppWithId);
same($r, array('status' => 'paired'), 'pair: the next try saves the key it already holds');
same(lh_config()['key'], $KEY, 'pair: the saved key is right');
same(lh_config()['uuid'], 'M1-00000000', 'pair: the hardware id is pinned at pairing');
check(!is_file(lh_file('pending.json')), 'pair: pending.json is removed once saved');
unpair();
lh_write_json('pending.json', array('code' => '123456', 'deviceSecret' => 's', 'expiresAt' => gmdate('c', time() + 300), 'deadline' => time() + 300, 'lastCheck' => 0));
same(lighthuntfpppluginPairCheckWith($pairedReply, $noFpp), array('status' => 'paired'), 'pair: a normal pairing still works');
check(!isset(lh_config()['uuid']), 'pair: no hardware id from FPP means none pinned (the poller adopts one later)');
unpair();

/* ---------- Lifecycle: nothing creates the data folder, nothing restarts the poller ---------- */

$bare = sys_get_temp_dir() . '/lh-bare-' . getmypid();
@mkdir($bare . '/logs', 0775, true);
putenv('FPP_MEDIA_DIR=' . $bare);
lh_data_dir();
lh_write_json('status.json', array('a' => 1));
lh_start_poller();
check(!is_dir($bare . '/plugindata'), 'lib helpers never create the data folder');
check(lh_orphaned(__FILE__), 'a missing data folder means orphaned');
@mkdir($bare . '/plugindata/lighthunt-fpp-plugin', 0775, true);
check(!lh_orphaned(__FILE__) && lh_orphaned(__FILE__ . '.gone'), 'present file and folder is not orphaned; a missing daemon file is');
rmdir($bare . '/plugindata/lighthunt-fpp-plugin');
rmdir($bare . '/plugindata');

// The real daemon, pointed at a media folder with no data folder, exits at once and creates nothing.
$out = array();
exec('FPP_MEDIA_DIR=' . escapeshellarg($bare) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../lighthunt-daemon.php') . ' 2>&1', $out, $rc);
same($rc, 0, 'daemon exits 0 when the data folder is gone');
check(!is_dir($bare . '/plugindata'), 'daemon does not mkdir the data folder');
putenv('FPP_MEDIA_DIR=' . $tmp);

// The page must not restart a poller while fppd is stopped or stopping (marker from preStop.sh).
pair($KEY);
touch(lh_file('stopped'));
lh_start_poller();
check(!lh_daemon_running(), 'the page does not start the poller while stopped');
unlink(lh_file('stopped'));
// fppd not answering (nothing on 127.0.0.1:1) also means no restart.
putenv('LIGHTHUNT_FPP_URL=http://127.0.0.1:1');
lh_start_poller();
check(!lh_daemon_running(), 'the page does not start the poller when fppd is not answering');
unpair();

/* ---------- Log never holds a key ---------- */

$log = @file_get_contents(lh_log_file());
check($log !== false && strpos($log, 'lhr_') === false, 'the log never contains a key');
check($log !== false && strpos($log, 'no longer accepts') !== false, 'the 401 is logged once');
same(substr_count((string) $log, 'no longer accepts'), 1, '401 logged once only');

echo $failures === 0 ? "OK: $checks checks passed\n" : "$failures of $checks checks failed\n";
exit($failures === 0 ? 0 : 1);
