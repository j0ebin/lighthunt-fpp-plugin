<?php
/**
 * Light Hunt background poller. Started by scripts/postStart.sh, stopped by scripts/preStop.sh.
 *
 * Every 5 seconds it reads Falcon Player's local status. When the song changes, and at least every
 * 30 seconds, it posts what is playing to Light Hunt. It runs as its own process, so a slow or
 * missing network can never hold up fppd. Until you pair it, it only waits.
 */

require_once __DIR__ . '/lib/lighthunt.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
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
    'status' => 'lh_fpp_status',
    'tags' => 'lh_fpp_tags',
    'post' => function ($key, $json) {
        return lh_post_json('/api/now-playing', $json, $key);
    },
);

$state = lh_new_state();
while (true) {
    try {
        lh_tick($state, time(), $io);
    } catch (Throwable $e) {
        lh_log('Poller error: ' . $e->getMessage());
    }
    sleep(LH_POLL_SECONDS);
}
