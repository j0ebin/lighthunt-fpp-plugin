<?php
/**
 * Show doctor: checks whether the show on this player is ready for tonight.
 *
 * Two halves. lh_doctor_gather() reads FPP's config files, its local API and the Pi itself.
 * lh_doctor_run() turns what was read into five results and is pure, so tests feed it recorded config.
 *
 * Privacy: controller IP addresses are read only to ping them and never leave this file's functions.
 * Results name controllers by their description in FPP (with any IP address in it removed), and
 * the pre-show report to Light Hunt carries only ids, statuses, counts and those names.
 *
 * No power or wiring advice anywhere (owner rule 2026-10-31): health rows say what the Pi reports, nothing more.
 */

namespace LightHunt;

const LH_DOCTOR_PRESHOW = 1800;        // the pre-show report goes out 30 minutes before tonight's first start
const LH_DOCTOR_SCHEDULE_SECONDS = 300; // the poller rereads the schedule this often
const LH_DOCTOR_MAX_PINGS = 64;
const LH_DOCTOR_MAX_MODELS = 20;
const LH_DOCTOR_IDS = array('controller_unreachable', 'sequence_exceeds_outputs', 'missing_file', 'nothing_tonight', 'pi_health');

/* ---------- Pure helpers ---------- */

/** Removes IPv4 addresses (and a :port after one) from a label. Owners often type the IP into an output's description. */
function lh_doc_strip_ip($text)
{
    $text = preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}(?::\d+)?\b/', '', (string) $text);
    $text = preg_replace('/\(\s*\)|\[\s*\]/', '', $text); // "(10.0.0.9)" leaves empty brackets behind
    $text = trim(preg_replace('/\s{2,}/', ' ', $text), " \t-:,");
    return $text;
}

/**
 * Network controllers from co-universes.json, grouped by address. Pure.
 * Returns [['ip' => '10.0.0.5', 'label' => 'Garage F16'], ...]. Multicast and broadcast outputs have no
 * address to reach and are left out. The ip stays inside this file: lh_doctor_run never copies it into a result.
 */
function lh_doc_controllers($universesDoc)
{
    $byIp = array();
    $outputs = is_array($universesDoc) && isset($universesDoc['channelOutputs']) && is_array($universesDoc['channelOutputs']) ? $universesDoc['channelOutputs'] : array();
    foreach ($outputs as $out) {
        if (!is_array($out) || empty($out['enabled']) || !isset($out['universes']) || !is_array($out['universes'])) {
            continue;
        }
        foreach ($out['universes'] as $u) {
            if (!is_array($u) || (isset($u['active']) && empty($u['active']))) {
                continue;
            }
            // FPP universe types 0 (E1.31 multicast) and 2 (ArtNet broadcast) send to no single address.
            if (isset($u['type']) && in_array((int) $u['type'], array(0, 2), true)) {
                continue;
            }
            $ip = isset($u['address']) ? trim((string) $u['address']) : '';
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || preg_match('/^(22[4-9]|23\d|255)\./', $ip)) {
                continue; // no address, a host name we cannot check without DNS, multicast or broadcast
            }
            $label = lh_doc_strip_ip(isset($u['description']) ? $u['description'] : '');
            if (!isset($byIp[$ip])) {
                $byIp[$ip] = array('labels' => array(), 'ids' => array());
            }
            if ($label !== '' && !in_array($label, $byIp[$ip]['labels'], true)) {
                $byIp[$ip]['labels'][] = $label;
            }
            if (isset($u['id'])) {
                $byIp[$ip]['ids'][] = (int) $u['id'];
                $byIp[$ip]['ids'][] = (int) $u['id'] + lh_doc_universe_count($u) - 1;
            }
        }
    }
    $list = array();
    foreach ($byIp as $ip => $c) {
        if ($c['labels']) {
            $label = implode(' / ', array_slice($c['labels'], 0, 2));
        } elseif ($c['ids']) {
            $min = min($c['ids']);
            $max = max($c['ids']);
            $label = $min === $max ? 'Universe ' . $min : 'Universes ' . $min . '-' . $max;
        } else {
            $label = 'Controller ' . (count($list) + 1);
        }
        $list[] = array('ip' => $ip, 'label' => lh_clip($label, 80));
    }
    return $list;
}

/**
 * The highest channel this player's outputs send, across every channel output file FPP keeps. Pure.
 * Each doc is the JSON of one co-*.json or channeloutputs.json file. Disabled outputs do not count.
 */
function lh_doc_output_channels($docs)
{
    $max = 0;
    foreach ((array) $docs as $doc) {
        $outputs = is_array($doc) && isset($doc['channelOutputs']) && is_array($doc['channelOutputs']) ? $doc['channelOutputs'] : array();
        foreach ($outputs as $out) {
            if (!is_array($out) || empty($out['enabled'])) {
                continue;
            }
            $start = isset($out['startChannel']) ? (int) $out['startChannel'] : 1;
            $count = isset($out['channelCount']) ? (int) $out['channelCount'] : 0;
            if ($count <= 0 && isset($out['universes']) && is_array($out['universes'])) {
                // co-universes.json keeps channelCount -1 on the output, so the span comes from its universes.
                foreach ($out['universes'] as $u) {
                    if (is_array($u) && (!isset($u['active']) || !empty($u['active']))) {
                        $size = isset($u['channelCount']) ? (int) $u['channelCount'] : (isset($u['size']) ? (int) $u['size'] : 0);
                        $end = (isset($u['startChannel']) ? (int) $u['startChannel'] : 1) + $size * lh_doc_universe_count($u) - 1;
                        $max = max($max, $end);
                    }
                }
                continue;
            }
            if ($count > 0) {
                $max = max($max, max(1, $start) + $count - 1);
            }
        }
    }
    return $max;
}

/** How many universes one co-universes.json row covers. DDP (4, 5) and Twinkly (8) ignore universeCount. Pure. */
function lh_doc_universe_count($u)
{
    if (isset($u['type']) && in_array((int) $u['type'], array(4, 5, 8), true)) {
        return 1;
    }
    return max(1, isset($u['universeCount']) ? (int) $u['universeCount'] : 1);
}

/** The total channel count in an .fseq header (v1 and v2 keep it at byte 10, little-endian), or null. Pure. */
function lh_doc_fseq_channels($header)
{
    if (!is_string($header) || strlen($header) < 14 || !in_array(substr($header, 0, 4), array('PSEQ', 'FSEQ'), true)) {
        return null;
    }
    $v = unpack('V', substr($header, 10, 4));
    return $v ? (int) $v[1] : null;
}

/**
 * Every song entry of a playlist and its sub-playlists, once each. Pure.
 * $playlists maps a playlist name to its JSON (null when the file is missing). Returns
 * [['seq' => string|null, 'media' => string|null, 'playlist' => name], ...] and fills $missingPlaylists.
 */
function lh_doc_playlist_items($name, $playlists, &$missingPlaylists, $seen = array())
{
    if (isset($seen[$name])) {
        return array(); // a playlist that includes itself
    }
    $seen[$name] = true;
    if (!isset($playlists[$name]) || !is_array($playlists[$name])) {
        $missingPlaylists[$name] = true;
        return array();
    }
    $items = array();
    foreach (array('leadIn', 'mainPlaylist', 'leadOut') as $section) {
        $entries = isset($playlists[$name][$section]) && is_array($playlists[$name][$section]) ? $playlists[$name][$section] : array();
        foreach ($entries as $e) {
            $type = is_array($e) && isset($e['type']) ? (string) $e['type'] : '';
            if (isset($e['enabled']) && !$e['enabled']) {
                continue; // FPP skips a disabled entry, so its file does not matter tonight
            }
            if ($type === 'playlist') {
                $sub = isset($e['name']) ? trim((string) $e['name']) : '';
                if ($sub === '' && isset($e['playlist']) && is_array($e['playlist'])) {
                    // An older FPP keeps the sub-playlist inline instead of by name.
                    $inline = '(inline) ' . (isset($e['playlist']['name']) ? $e['playlist']['name'] : count($items));
                    $items = array_merge($items, lh_doc_playlist_items($inline, array($inline => $e['playlist']) + $playlists, $missingPlaylists, $seen));
                } elseif ($sub !== '') {
                    $items = array_merge($items, lh_doc_playlist_items($sub, $playlists, $missingPlaylists, $seen));
                }
                continue;
            }
            if (!in_array($type, array('both', 'sequence', 'media'), true)) {
                continue;
            }
            $seq = $type !== 'media' && !empty($e['sequenceName']) ? trim((string) $e['sequenceName']) : null;
            $media = $type !== 'sequence' && !empty($e['mediaName']) ? trim((string) $e['mediaName']) : null;
            if ($seq !== null || $media !== null) {
                $items[] = array('seq' => $seq, 'media' => $media, 'playlist' => $name);
            }
        }
    }
    return $items;
}

/** The playlists a check should look at: the ones the schedule runs, or every playlist when nothing is scheduled. Pure. */
function lh_doc_playlists_to_check($in)
{
    $names = array();
    foreach (isset($in['scheduledPlaylists']) ? (array) $in['scheduledPlaylists'] : array() as $n) {
        $names[(string) $n] = true;
    }
    if (!$names) {
        foreach (array_keys(isset($in['playlists']) ? (array) $in['playlists'] : array()) as $n) {
            $names[(string) $n] = true;
        }
    }
    return array_keys($names);
}

function lh_doc_result($id, $status, $count, $detail, $models = array())
{
    $r = array('id' => $id, 'status' => $status, 'count' => (int) $count, 'detail' => $detail);
    if ($models) {
        $r['models'] = array_values(array_slice(array_unique($models), 0, LH_DOCTOR_MAX_MODELS));
    }
    return $r;
}

function lh_doc_plural($n, $one, $many)
{
    return $n . ' ' . ($n === 1 ? $one : $many);
}

function lh_doc_list($names, $max = 3)
{
    $shown = array_slice($names, 0, $max);
    $more = count($names) - count($shown);
    return implode(', ', $shown) . ($more > 0 ? ' and ' . $more . ' more' : '');
}

/**
 * The five checks. Pure. $in is what lh_doctor_gather() read:
 *   controllers        [['ip', 'label'], ...] from lh_doc_controllers
 *   pings              [ip => bool] (true = answered)
 *   outputChannels     int from lh_doc_output_channels
 *   playlists          [name => playlist JSON or null]
 *   scheduledPlaylists names any enabled schedule entry runs
 *   files              ['sequences' => [lowercase name => true], 'music' => [...], 'videos' => [...]]
 *   fseqChannels       [sequence file name => int|null]
 *   tonight            null when the schedule could not be read, else [['playlist' => name, 'start' => epoch], ...]
 *   health             ['undervoltageNow', 'undervoltageSince', 'throttledNow' (bools or null), 'diskPct', 'tempC' (numbers or null), 'clockSynced' (bool or null)]
 */
function lh_doctor_run($in)
{
    $results = array();

    // 1. Controllers that do not answer a ping.
    $controllers = isset($in['controllers']) ? (array) $in['controllers'] : array();
    $pings = isset($in['pings']) ? (array) $in['pings'] : array();
    $down = array();
    foreach ($controllers as $c) {
        if (isset($pings[$c['ip']]) && $pings[$c['ip']] === false) {
            $down[] = $c['label'];
        }
    }
    if (!$controllers) {
        $results[] = lh_doc_result('controller_unreachable', 'ok', 0, 'No network controllers with an address are set up on this player.');
    } elseif ($down) {
        $n = count($down);
        $results[] = lh_doc_result('controller_unreachable', 'fail', $n,
            lh_doc_plural($n, 'controller does', 'controllers do') . ' not answer: ' . lh_doc_list($down) . '. Check it is switched on and on the show network.', $down);
    } else {
        $results[] = lh_doc_result('controller_unreachable', 'ok', 0, 'All ' . lh_doc_plural(count($controllers), 'controller answers', 'controllers answer') . '.');
    }

    // Songs in the playlists that matter tonight.
    $playlists = isset($in['playlists']) ? (array) $in['playlists'] : array();
    $missingPlaylists = array();
    $items = array();
    foreach (lh_doc_playlists_to_check($in) as $name) {
        $items = array_merge($items, lh_doc_playlist_items($name, $playlists, $missingPlaylists));
    }

    // 2. Sequences that use more channels than the outputs send.
    $outCh = isset($in['outputChannels']) ? (int) $in['outputChannels'] : 0;
    $fseq = isset($in['fseqChannels']) ? (array) $in['fseqChannels'] : array();
    $over = array();
    $biggest = 0;
    $seqs = array();
    foreach ($items as $it) {
        if ($it['seq'] !== null) {
            $seqs[$it['seq']] = true;
        }
    }
    foreach (array_keys($seqs) as $seq) {
        $ch = isset($fseq[$seq]) ? $fseq[$seq] : null;
        if ($ch !== null && $outCh > 0 && $ch > $outCh) {
            $over[] = (string) $seq;
            $biggest = max($biggest, $ch);
        }
    }
    if (!$seqs) {
        $results[] = lh_doc_result('sequence_exceeds_outputs', 'ok', 0, 'No sequences to compare.');
    } elseif ($outCh === 0) {
        $results[] = lh_doc_result('sequence_exceeds_outputs', 'warn', 0, 'No channel outputs are set up on this player, so its sequences light nothing here. Fine if this player only drives remotes.');
    } elseif ($over) {
        $n = count($over);
        $results[] = lh_doc_result('sequence_exceeds_outputs', 'warn', $n,
            lh_doc_plural($n, 'sequence uses', 'sequences use') . ' up to ' . number_format($biggest) . ' channels, but the outputs send ' . number_format($outCh)
            . ': ' . lh_doc_list($over) . '. Channels past ' . number_format($outCh) . ' go nowhere.', $over);
    } else {
        $results[] = lh_doc_result('sequence_exceeds_outputs', 'ok', 0, 'Every sequence fits in the ' . number_format($outCh) . ' channels the outputs send.');
    }

    // 3. Files a playlist names that are not on this player.
    $files = isset($in['files']) ? (array) $in['files'] : array();
    $has = function ($dir, $name) use ($files) {
        return isset($files[$dir][strtolower($name)]);
    };
    $missing = array();
    foreach (array_keys($missingPlaylists) as $p) {
        $missing[] = $p . ' (playlist)';
    }
    foreach ($items as $it) {
        if ($it['seq'] !== null && !$has('sequences', $it['seq'])) {
            $missing[] = $it['seq'];
        }
        if ($it['media'] !== null && !$has('music', $it['media']) && !$has('videos', $it['media'])) {
            $missing[] = $it['media'];
        }
    }
    $missing = array_values(array_unique($missing));
    if ($missing) {
        $n = count($missing);
        $results[] = lh_doc_result('missing_file', 'fail', $n, lh_doc_plural($n, 'file named in a playlist is', 'files named in playlists are') . ' not on this player: ' . lh_doc_list($missing) . '.', $missing);
    } elseif (!$items) {
        $results[] = lh_doc_result('missing_file', 'ok', 0, 'No playlist songs to check.');
    } else {
        $results[] = lh_doc_result('missing_file', 'ok', 0, 'Every sequence and audio file the playlists name is on this player.');
    }

    // 4. Nothing scheduled tonight, or tonight's playlist has no songs.
    $tonight = array_key_exists('tonight', $in) ? $in['tonight'] : null;
    if ($tonight === null) {
        $results[] = lh_doc_result('nothing_tonight', 'warn', 0, 'Could not read the schedule from Falcon Player.');
    } elseif (!$tonight) {
        $results[] = lh_doc_result('nothing_tonight', 'warn', 0, 'Nothing is scheduled to play tonight. Fine if you start the show by hand.');
    } else {
        $empty = array();
        foreach ($tonight as $t) {
            $none = array();
            if (!lh_doc_playlist_items($t['playlist'], $playlists, $none)) {
                $empty[] = $t['playlist'];
            }
        }
        $empty = array_values(array_unique($empty));
        $first = $tonight[0];
        if ($empty) {
            $n = count($empty);
            $results[] = lh_doc_result('nothing_tonight', 'fail', $n, 'Tonight\'s ' . lh_doc_plural($n, 'playlist has', 'playlists have') . ' no songs: ' . lh_doc_list($empty) . '.', $empty);
        } else {
            $results[] = lh_doc_result('nothing_tonight', 'ok', 0, 'Tonight starts at ' . date('g:i A', (int) $first['start']) . ' with ' . $first['playlist'] . '.');
        }
    }

    // 5. The Pi itself.
    $h = isset($in['health']) ? (array) $in['health'] : array();
    $fails = array();
    $warns = array();
    $labels = array();
    if (!empty($h['undervoltageNow'])) {
        $fails[] = 'The Pi reports under-voltage right now.';
        $labels[] = 'under-voltage';
    } elseif (!empty($h['undervoltageSince'])) {
        $warns[] = 'The Pi reported under-voltage since it started.';
        $labels[] = 'under-voltage';
    }
    if (!empty($h['throttledNow'])) {
        $warns[] = 'The Pi is slowing itself down (throttled).';
        $labels[] = 'throttled';
    }
    if (isset($h['diskPct']) && $h['diskPct'] !== null && $h['diskPct'] > 90) {
        $msg = 'The storage is ' . (int) floor($h['diskPct']) . '% full.';
        if ($h['diskPct'] >= 98) {
            $fails[] = $msg;
        } else {
            $warns[] = $msg;
        }
        $labels[] = 'disk';
    }
    if (isset($h['clockSynced']) && $h['clockSynced'] === false) {
        $warns[] = 'The clock is not synced to internet time, so the schedule may start at the wrong time.';
        $labels[] = 'clock';
    }
    if (isset($h['tempC']) && $h['tempC'] !== null && $h['tempC'] >= 80) {
        $warns[] = 'The CPU is at ' . (int) round($h['tempC']) . ' C.';
        $labels[] = 'temperature';
    }
    $problems = array_merge($fails, $warns);
    if ($problems) {
        $results[] = lh_doc_result('pi_health', $fails ? 'fail' : 'warn', count($problems), implode(' ', $problems), $labels);
    } else {
        $results[] = lh_doc_result('pi_health', 'ok', 0, 'Power, storage, clock and temperature look fine.');
    }

    return $results;
}

/** One line for the top of the tab. Pure. */
function lh_doctor_verdict($results)
{
    $fail = 0;
    $warn = 0;
    foreach ($results as $r) {
        $fail += $r['status'] === 'fail' ? 1 : 0;
        $warn += $r['status'] === 'warn' ? 1 : 0;
    }
    if ($fail) {
        return array('status' => 'fail', 'text' => 'Not ready: ' . lh_doc_plural($fail, 'problem needs', 'problems need') . ' fixing before tonight.');
    }
    if ($warn) {
        return array('status' => 'warn', 'text' => 'Ready, with ' . lh_doc_plural($warn, 'thing', 'things') . ' to look at.');
    }
    return array('status' => 'ok', 'text' => 'Ready for tonight.');
}

/** The pre-show report body for POST /api/relay/doctor: ids, statuses, counts and names only. Pure. */
function lh_doctor_report_body($results, $checkedAt)
{
    $out = array();
    foreach ($results as $r) {
        $row = array('id' => $r['id'], 'status' => $r['status'], 'count' => (int) $r['count']);
        if (!empty($r['models'])) {
            $row['models'] = array();
            foreach (array_slice($r['models'], 0, LH_DOCTOR_MAX_MODELS) as $m) {
                $row['models'][] = lh_clip(lh_doc_strip_ip($m), 120);
            }
        }
        $out[] = $row;
    }
    return array('checkedAt' => gmdate('c', (int) $checkedAt), 'results' => $out);
}

/**
 * Tonight's schedule from fppd's /api/fppd/schedule answer. Pure. Returns entries that start between
 * noon today and noon tomorrow on this player's clock, sorted by start, or null when the answer is unusable.
 */
function lh_doc_tonight($scheduleDoc, $now)
{
    if (!is_array($scheduleDoc) || !isset($scheduleDoc['schedule']['items']) || !is_array($scheduleDoc['schedule']['items'])) {
        return null;
    }
    $noon = strtotime('today 12:00', $now);
    $from = $now < $noon ? $noon - 86400 : $noon;
    $to = $from + 86400;
    $out = array();
    foreach ($scheduleDoc['schedule']['items'] as $it) {
        if (!is_array($it) || !isset($it['startTime'])) {
            continue;
        }
        $start = (int) $it['startTime'];
        $end = isset($it['endTime']) ? (int) $it['endTime'] : $start;
        if (isset($it['command']) && $it['command'] !== 'Start Playlist') {
            continue; // a scheduled FPP command, not a show
        }
        $name = isset($it['args'][0]) ? (string) $it['args'][0] : (isset($it['playlist']) ? (string) $it['playlist'] : '');
        // A show already running counts too: it started earlier and ends after now.
        if ($name !== '' && (($start >= $from && $start < $to) || ($start < $from && $end > $now))) {
            $out[] = array('playlist' => $name, 'start' => $start);
        }
    }
    usort($out, function ($a, $b) {
        return $a['start'] - $b['start'];
    });
    return $out;
}

/** Playlist names any enabled entry of schedule.json runs. Pure. */
function lh_doc_scheduled_playlists($scheduleJson)
{
    $names = array();
    foreach (is_array($scheduleJson) ? $scheduleJson : array() as $e) {
        if (is_array($e) && !empty($e['enabled']) && !empty($e['playlist'])) {
            $names[(string) $e['playlist']] = true;
        }
    }
    return array_keys($names);
}

/** Reads vcgencmd's get_throttled bits. Pure. */
function lh_doc_throttled($value)
{
    if (!is_string($value) || !preg_match('/0x([0-9a-f]+)/i', $value, $m)) {
        return array('undervoltageNow' => null, 'undervoltageSince' => null, 'throttledNow' => null);
    }
    $bits = hexdec($m[1]);
    return array(
        'undervoltageNow' => ($bits & 0x1) !== 0,
        'undervoltageSince' => ($bits & 0x10000) !== 0,
        'throttledNow' => ($bits & 0x4) !== 0,
    );
}

/* ---------- Reading the player ---------- */

function lh_doc_read_json_file($path)
{
    $raw = @file_get_contents($path);
    $data = $raw === false ? null : json_decode($raw, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
    return is_array($data) ? $data : null;
}

/** Lowercased file names in a media folder (subfolders included, as FPP lists them: "Halloween/Song.fseq"). */
function lh_doc_list_dir($dir)
{
    $out = array();
    if (!is_dir($dir)) {
        return $out;
    }
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile()) {
            $out[strtolower(ltrim(substr($f->getPathname(), strlen($dir)), '/'))] = true;
        }
    }
    return $out;
}

/** Pings every address at once, one second each. Returns [ip => bool]. The addresses never go anywhere else. */
function lh_doc_ping($ips)
{
    $ips = array_slice(array_values(array_unique($ips)), 0, LH_DOCTOR_MAX_PINGS);
    if (!$ips) {
        return array();
    }
    $cmd = '';
    foreach ($ips as $ip) {
        // Each ip passed FILTER_VALIDATE_IP; escapeshellarg anyway.
        $cmd .= '(ping -c 1 -W 1 ' . escapeshellarg($ip) . ' >/dev/null 2>&1 && echo ' . escapeshellarg($ip) . ') & ';
    }
    $out = array();
    @exec($cmd . 'wait', $out);
    $up = array_flip(array_map('trim', $out));
    $result = array();
    foreach ($ips as $ip) {
        $result[$ip] = isset($up[$ip]);
    }
    return $result;
}

function lh_doc_health()
{
    $media = lh_media_dir();
    $total = @disk_total_space($media);
    $free = @disk_free_space($media);
    $h = array(
        'diskPct' => $total ? (1 - $free / $total) * 100 : null,
        'tempC' => null,
        'clockSynced' => null,
    );
    $t = @file_get_contents('/sys/class/thermal/thermal_zone0/temp');
    if ($t !== false && is_numeric(trim($t))) {
        $h['tempC'] = (int) trim($t) / 1000;
    }
    $throttled = @file_get_contents('/sys/devices/platform/soc/soc:firmware/get_throttled');
    if ($throttled !== false && trim($throttled) !== '') {
        $throttled = 'throttled=0x' . trim($throttled);
    } else {
        $throttled = is_executable('/usr/bin/vcgencmd') ? @exec('/usr/bin/vcgencmd get_throttled 2>/dev/null') : '';
    }
    $h = array_merge($h, lh_doc_throttled($throttled));
    $sync = @exec('timedatectl show -p NTPSynchronized --value 2>/dev/null');
    if ($sync === 'yes' || $sync === 'no') {
        $h['clockSynced'] = $sync === 'yes';
    }
    return $h;
}

/** Everything lh_doctor_run needs, read from this player. $now is the wall clock. */
/**
 * The config the channel layout check reads in the browser: the models xLights uploads (model-overlays.json) and
 * every channel output file. Stays on this player; the page builds its findings from it and sends nothing.
 */
function lh_doctor_design_config($cfgDir)
{
    $others = array();
    foreach (array('co-pixelStrings.json', 'co-bbbStrings.json', 'co-other.json', 'channeloutputs.json') as $f) {
        $others[] = lh_doc_read_json_file($cfgDir . '/' . $f);
    }
    return array(
        'overlays' => lh_doc_read_json_file($cfgDir . '/model-overlays.json'),
        'universes' => lh_doc_read_json_file($cfgDir . '/co-universes.json'),
        'others' => $others,
    );
}

function lh_doctor_gather($now)
{
    $media = lh_media_dir();
    $cfg = $media . '/config';
    $universes = lh_doc_read_json_file($cfg . '/co-universes.json');
    $outputDocs = array($universes);
    foreach (array('co-pixelStrings.json', 'co-bbbStrings.json', 'co-other.json', 'channeloutputs.json') as $f) {
        $outputDocs[] = lh_doc_read_json_file($cfg . '/' . $f);
    }
    $controllers = lh_doc_controllers($universes);

    $playlists = array();
    foreach ((array) @glob($media . '/playlists/*.json') as $path) {
        $playlists[basename($path, '.json')] = lh_doc_read_json_file($path);
    }
    $scheduled = lh_doc_scheduled_playlists(lh_doc_read_json_file($cfg . '/schedule.json'));

    $fseq = array();
    foreach ($playlists as $pl) {
        foreach (array('leadIn', 'mainPlaylist', 'leadOut') as $s) {
            foreach (is_array($pl) && isset($pl[$s]) && is_array($pl[$s]) ? $pl[$s] : array() as $e) {
                $seq = is_array($e) && !empty($e['sequenceName']) ? (string) $e['sequenceName'] : '';
                if ($seq !== '' && !array_key_exists($seq, $fseq)) {
                    $fh = @fopen($media . '/sequences/' . $seq, 'rb');
                    $fseq[$seq] = $fh ? lh_doc_fseq_channels(fread($fh, 32)) : null;
                    if ($fh) {
                        fclose($fh);
                    }
                }
            }
        }
    }

    $sched = lh_http('GET', lh_fpp_url() . '/api/fppd/schedule', array(), null, 3);
    return array(
        'controllers' => $controllers,
        'pings' => lh_doc_ping(array_map(function ($c) {
            return $c['ip'];
        }, $controllers)),
        'outputChannels' => lh_doc_output_channels($outputDocs),
        'playlists' => $playlists,
        'scheduledPlaylists' => $scheduled,
        'files' => array(
            'sequences' => lh_doc_list_dir($media . '/sequences'),
            'music' => lh_doc_list_dir($media . '/music'),
            'videos' => lh_doc_list_dir($media . '/videos'),
        ),
        'fseqChannels' => $fseq,
        'tonight' => $sched['code'] === 200 ? lh_doc_tonight($sched['json'], $now) : null,
        'health' => lh_doc_health(),
    );
}

/* ---------- Settings, saved results, the pre-show report ---------- */

/** The owner's switch for the pre-show report. On unless the owner turned it off. */
function lh_doctor_report_enabled()
{
    $s = lh_read_json('doctor-settings.json');
    return !(is_array($s) && isset($s['report']) && $s['report'] === false);
}

/**
 * Runs every check, saves the results for the tab (no IPs in them), and returns
 * ['checkedAt' => int, 'results' => [...], 'verdict' => [...]]. $gather is for tests.
 */
function lh_doctor_check($now, $gather = null)
{
    $in = call_user_func($gather ? $gather : 'LightHunt\\lh_doctor_gather', $now);
    $results = lh_doctor_run($in);
    $doc = array('checkedAt' => $now, 'results' => $results, 'verdict' => lh_doctor_verdict($results));
    lh_write_json('doctor-results.json', $doc, 0644);
    return $doc;
}

/**
 * Sends a finished check to Light Hunt when the player is paired and the owner left the report on.
 * Returns 'sent', 'off', 'unpaired', 'missing' (Light Hunt has no such endpoint yet) or 'failed'.
 * A 404 is logged once, then quietly skipped until a report gets through.
 */
function lh_doctor_send($doc, $post = null)
{
    if (!lh_doctor_report_enabled()) {
        return 'off';
    }
    $cfg = lh_config();
    if (!$cfg) {
        return 'unpaired';
    }
    $body = lh_doctor_report_body($doc['results'], $doc['checkedAt']);
    $r = call_user_func($post ? $post : 'LightHunt\\lh_post_json', '/api/relay/doctor', lh_encode_report($body), $cfg['key']);
    if ($r['code'] === 200 || $r['code'] === 201 || $r['code'] === 204) {
        if (is_file(lh_file('doctor-404'))) {
            @unlink(lh_file('doctor-404'));
        }
        return 'sent';
    }
    if ($r['code'] === 404) {
        // The poller and the page are separate processes, so "logged once" is a marker file, not a variable.
        if (!is_file(lh_file('doctor-404'))) {
            lh_log('Light Hunt does not take Show doctor reports yet (HTTP 404). The checks still run on this player.');
            @touch(lh_file('doctor-404'));
        }
        return 'missing';
    }
    lh_log('Show doctor report not sent (' . ($r['code'] === 0 ? 'network' : 'HTTP ' . (int) $r['code']) . ')');
    return 'failed';
}

/** The poller's part: once a night, 30 minutes before the first scheduled start, check and report. */
function lh_doctor_new_state()
{
    return array('scheduleAt' => null, 'nextStart' => null, 'sentFor' => null);
}

/**
 * One pass, called from the poll loop. Cheap: it rereads fppd's schedule every 5 minutes and does nothing
 * else until a start is 30 minutes away. $io: 'schedule' => fn(): array|null (fppd's /api/fppd/schedule),
 * 'check' => fn(now): doc, 'post' => fn(path, json, key): http result.
 */
function lh_doctor_tick(&$D, $now, $mono, $io)
{
    if (!lh_config() || !lh_doctor_report_enabled()) {
        return;
    }
    if ($D['scheduleAt'] === null || $mono - $D['scheduleAt'] >= LH_DOCTOR_SCHEDULE_SECONDS) {
        $D['scheduleAt'] = $mono;
        $tonight = lh_doc_tonight(call_user_func($io['schedule']), $now);
        // Only the night's first start: a show split into two schedule entries gets one report.
        $D['nextStart'] = $tonight && $tonight[0]['start'] > $now ? $tonight[0]['start'] : null;
    }
    $start = $D['nextStart'];
    if ($start === null || $D['sentFor'] === $start || $now < $start - LH_DOCTOR_PRESHOW || $now >= $start) {
        return;
    }
    $D['sentFor'] = $start;
    $doc = call_user_func($io['check'], $now);
    lh_doctor_send($doc, $io['post']);
}
