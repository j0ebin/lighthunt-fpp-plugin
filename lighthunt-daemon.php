<?php
/**
 * Light Hunt background poller. Started by scripts/postStart.sh, stopped by scripts/preStop.sh.
 *
 * Every 5 seconds it reads Falcon Player's local status. When the song changes, and at least every
 * 20 seconds, it posts what is playing to Light Hunt. It runs as its own process, so a slow or
 * missing network can never hold up fppd. Until you pair it, it only waits.
 *
 * If the owner turns on song voting in the plugin page, it also sends the playlist's songs to Light Hunt,
 * and near the end of each song asks for the visitors' pick and tells FPP to play it next.
 */

require_once __DIR__ . '/lib/lighthunt.php';

use function LightHunt\lh_doctor_new_state;
use function LightHunt\lh_doctor_tick;
use function LightHunt\lh_file;
use function LightHunt\lh_log;
use function LightHunt\lh_new_state;
use function LightHunt\lh_orphaned;
use function LightHunt\lh_safe_tick;
use const LightHunt\LH_POLL_SECONDS;
use const LightHunt\LH_VERSION;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

// This process never creates the data folder (only fpp_install.sh and start.sh do), so after an
// uninstall it finds nothing to lock and ends.
if (lh_orphaned(__FILE__)) {
    exit(0);
}

// One poller at a time. The lock goes away with the process, so a crash never leaves a stale one.
$lockPath = lh_file('daemon.lock');
// 'c' creates the file. If an earlier poller made it as another user, a read-only handle still holds the lock.
$lock = @fopen($lockPath, 'c') ?: @fopen($lockPath, 'r');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}
@ftruncate($lock, 0);
@fwrite($lock, (string) getmypid());
@fflush($lock);

lh_log('Light Hunt poller started (plugin ' . LH_VERSION . ')');

$io = array(
    'status' => 'LightHunt\\lh_fpp_status',
    'tags' => 'LightHunt\\lh_fpp_tags',
    'playlist' => 'LightHunt\\lh_fpp_playlist',
    'post' => function ($key, $json) {
        return \LightHunt\lh_post_json('/api/now-playing', $json, $key);
    },
    // Song voting, used only after the owner turns it on in the plugin page.
    'vote' => 'LightHunt\\lh_vote_post',
    'playlistRaw' => function ($name) {
        return \LightHunt\lh_fpp_playlist($name, false);
    },
    'command' => 'LightHunt\\lh_fpp_insert_next',
);

// Show doctor: 30 minutes before tonight's first scheduled start, check the show and send the summary.
$doctorIo = array(
    'schedule' => function () {
        $r = \LightHunt\lh_http('GET', \LightHunt\lh_fpp_url() . '/api/fppd/schedule', array(), null, 3);
        return $r['code'] === 200 ? $r['json'] : null;
    },
    'check' => 'LightHunt\\lh_doctor_check',
    'post' => 'LightHunt\\lh_post_json',
);

$state = lh_new_state();
$doctor = lh_doctor_new_state();
$doctorError = null;
while (!lh_orphaned(__FILE__)) {
    // Wall time is for what the owner reads. Every interval runs on the monotonic clock, because a Pi
    // with no clock chip steps its wall clock (sometimes backwards) when NTP first answers.
    // (hrtime is a float on a 32-bit Pi, so divide rather than intdiv.)
    $mono = (int) (hrtime(true) / 1e9);
    lh_safe_tick($state, time(), $io, $mono);
    try {
        lh_doctor_tick($doctor, time(), $mono, $doctorIo);
    } catch (\Throwable $e) {
        // A check never stops the now-playing reports. Logged once, not every 5 seconds.
        if ($doctorError !== $e->getMessage()) {
            lh_log('Show doctor error: ' . $e->getMessage());
        }
        $doctorError = $e->getMessage();
    }
    sleep(LH_POLL_SECONDS);
}
