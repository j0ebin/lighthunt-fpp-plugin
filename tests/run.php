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

/* ---------- lh_build_report: the field mapping ---------- */

$r = lh_build_report(fixture('playing-sequence-and-media'));
same($r, array(
    'source' => 'fpp', 'state' => 'playing', 'name' => 'Carol of the Bells.fseq',
    'mediaFilename' => 'Carol of the Bells.mp3', 'remaining' => 134, 'duration' => 212,
    'showName' => 'Christmas Show', 'fppVersion' => '9.1', 'relayVersion' => LH_VERSION,
), 'sequence and media');

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
$odd['version'] = '';
check(!isset(lh_build_report($odd)['fppVersion']), 'no version when FPP gives none');

$long = fixture('playing-sequence-and-media');
$long['current_song'] = str_repeat('a', 500) . '.mp3';
same(strlen(lh_build_report($long)['mediaFilename']), 200, 'long names are clipped to 200');
$long['current_song'] = str_repeat("\u{1F384}", 300);
same(mb_strlen(lh_build_report($long)['mediaFilename'], 'UTF-8'), 200, 'clipping never cuts an emoji');

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
    $io = array(
        'status' => function () use ($cur) { return $cur->fixture === null ? null : fixture($cur->fixture); },
        'tags' => function ($file) use ($cur) { $cur->tagCalls++; return array('title' => 'Tagged', 'artist' => 'Artist'); },
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

// Same song, 5 seconds later: nothing. After 30 seconds: heartbeat. Tags are not looked up again.
lh_tick($S, 1010, $io);
same(count($posts), 1, 'no resend inside 30 seconds');
lh_tick($S, 1035, $io);
same(count($posts), 2, 'heartbeat at 30 seconds');
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

/* ---------- Log never holds a key ---------- */

$log = @file_get_contents(lh_log_file());
check($log !== false && strpos($log, 'lhr_') === false, 'the log never contains a key');
check($log !== false && strpos($log, 'no longer accepts') !== false, 'the 401 is logged once');
same(substr_count((string) $log, 'no longer accepts'), 1, '401 logged once only');

echo $failures === 0 ? "OK: $checks checks passed\n" : "$failures of $checks checks failed\n";
exit($failures === 0 ? 0 : 1);
