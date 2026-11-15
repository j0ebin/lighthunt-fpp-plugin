<div class="container-fluid" id="lh-root">
  <style>
    /* FPP 7 to 9 ship older Bootstrap builds that lack some of these helpers: FPP 7.5 has no
       d-none, so every hidden section showed at once, and the status badges came out blank.
       Same values as Bootstrap 5.3: FPP 10 looks unchanged. */
    #lh-root .d-none { display: none !important; }
    #lh-root .text-bg-success { color: #fff; background-color: #198754; }
    #lh-root .text-bg-warning { color: #000; background-color: #ffc107; }
    #lh-root .text-bg-danger { color: #fff; background-color: #dc3545; }
    #lh-root .text-bg-secondary { color: #fff; background-color: #6c757d; }
    #lh-root .gap-3 { gap: 1rem; }
    #lh-root .align-items-start { align-items: flex-start; }
    #lh-root .fw-semibold { font-weight: 600; }
    #lh-root .ms-2 { margin-left: .5rem; }
    #lh-root .text-body-secondary { color: #6c757d; }
    #lh-root .list-group.mb-3 { margin-bottom: 1rem; }
  </style>
  <h1 class="h3 mb-3">Light Hunt: show what's playing</h1>
  <p class="text-body-secondary">
    Shows the song Falcon Player is playing on your display page at lightshunt.com.
    Falcon Player runs your lights as usual. Unless you turn on song voting below, this plugin only reads what is playing.
  </p>

  <ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item" role="presentation">
      <button type="button" class="nav-link active" id="lh-tab-main" role="tab" aria-controls="lh-pane-main" aria-selected="true">Now playing</button>
    </li>
    <li class="nav-item" role="presentation">
      <button type="button" class="nav-link" id="lh-tab-doctor" role="tab" aria-controls="lh-pane-doctor" aria-selected="false">Show doctor</button>
    </li>
  </ul>

  <div id="lh-pane-doctor" role="tabpanel" aria-labelledby="lh-tab-doctor" class="d-none">
    <div class="card mb-3">
      <div class="card-body">
        <h2 class="h5 card-title">Is the show ready for tonight?</h2>
        <p class="text-body-secondary">Checks this player: controllers, sequences, playlist files, tonight's schedule and the Pi itself. It changes nothing.</p>
        <div id="lh-doc-verdict" class="alert d-none" role="status"></div>
        <ul id="lh-doc-rows" class="list-group mb-3"></ul>
        <p class="mb-3">
          <button type="button" class="btn btn-primary" id="lh-doc-run">Run checks</button>
          <span id="lh-doc-when" class="text-body-secondary small ms-2"></span>
        </p>
        <div class="form-check form-switch mb-1">
          <input class="form-check-input" type="checkbox" role="switch" id="lh-doc-report">
          <label class="form-check-label" for="lh-doc-report">Send a summary to Light Hunt 30 minutes before the show, and after each check</label>
        </div>
        <p class="form-text mb-0" id="lh-doc-report-note">
          It sends which checks passed and the names of anything that needs fixing, so Show tools on Light Hunt can tell you. Never controller addresses.
        </p>
      </div>
    </div>
    <div class="card mb-3">
      <div class="card-body">
        <h2 class="h5 card-title">Channel layout</h2>
        <p class="text-body-secondary">Checks the models and outputs xLights uploaded to this player for channels two props share, props past the last channel and universes used twice. Fix problems in xLights, then upload again with FPP Connect.</p>
        <div id="lh-doc-design-verdict" class="alert d-none" role="status"></div>
        <ul id="lh-doc-design" class="list-group"></ul>
      </div>
    </div>
  </div>

  <div id="lh-pane-main" role="tabpanel" aria-labelledby="lh-tab-main">
  <div id="lh-error" class="alert alert-danger d-none" role="alert"></div>
  <div id="lh-warn" class="alert alert-warning d-none" role="status"></div>

  <div id="lh-unpaired" class="card mb-3 d-none">
    <div class="card-body">
      <h2 class="h5 card-title">Pair with your display</h2>
      <p id="lh-revoked" class="alert alert-warning d-none">Light Hunt no longer accepts the old pairing, usually because it was unpaired on your display page. Pair again to continue.</p>
      <p class="mb-2">Light Hunt is free. Pairing needs a free Light Hunt account with your display on it.</p>
      <ol>
        <li><a href="https://lightshunt.com/auth" target="_blank" rel="noopener noreferrer">Make a free Light Hunt account</a>.</li>
        <li><a href="https://lightshunt.com/for-owners/find" target="_blank" rel="noopener noreferrer">Add or claim your display</a>.</li>
        <li>Select <strong>Get a pairing code</strong> and confirm it on the page that opens. This page updates by itself.</li>
      </ol>
      <p class="form-text">Already have both? Go straight to step 3.</p>
      <button type="button" class="btn btn-primary" id="lh-start">Get a pairing code</button>
    </div>
  </div>

  <div id="lh-pending" class="card mb-3 d-none">
    <div class="card-body">
      <h2 class="h5 card-title">Your pairing code</h2>
      <p class="display-5 font-monospace mb-2" id="lh-code" aria-live="polite"></p>
      <p class="mb-2">
        Open <a id="lh-pair-link" href="#" target="_blank" rel="noopener noreferrer">the Light Hunt pairing page</a>
        and confirm your display. The code works for 10 minutes.
      </p>
      <p class="text-body-secondary mb-0">Waiting for you to confirm...</p>
    </div>
  </div>

  <div id="lh-paired" class="card mb-3 d-none">
    <div class="card-body">
      <h2 class="h5 card-title">Paired</h2>
      <p class="mb-1">Display: <a id="lh-display" href="#" target="_blank" rel="noopener noreferrer"></a></p>
      <p class="mb-1">Status: <span id="lh-result" class="badge"></span> <span id="lh-reason" class="text-body-secondary small"></span></p>
      <p class="mb-1">Now playing: <span id="lh-song"></span></p>
      <p class="mb-1">Last report to Light Hunt: <span id="lh-last"></span></p>
      <p class="mb-3">Visitors in the last 5 minutes: <span id="lh-viewers"></span></p>
      <button type="button" class="btn btn-outline-danger" id="lh-unpair">Unpair</button>
      <p class="form-text mt-2">
        Unpair removes the key from this device. To also end the link on Light Hunt, press
        <em>Unpair</em> under <em>Show what's playing</em> on your display page in edit mode.
      </p>
    </div>
  </div>

  <div id="lh-voting" class="card mb-3 d-none">
    <div class="card-body">
      <h2 class="h5 card-title">Song voting</h2>
      <p>
        Visitors at your display vote for the song that plays next. Near the end of each song, this plugin asks
        Light Hunt for the winner and Falcon Player plays it after the current song finishes. A song is never cut off.
      </p>
      <div class="form-check form-switch mb-2">
        <input class="form-check-input" type="checkbox" role="switch" id="lh-vote-on">
        <label class="form-check-label" for="lh-vote-on">Let visitors pick the next song</label>
      </div>
      <p class="mb-2">Voting: <span id="lh-vote-state"></span></p>
      <p class="form-text mb-0">
        Then finish in <a id="lh-vote-tools" href="https://lightshunt.com/for-owners/show" target="_blank" rel="noopener noreferrer">Show tools on Light Hunt</a>:
        match each song to your setlist and turn on <em>Let visitors vote</em>. Shuffled playlists get no ballots.
      </p>
    </div>
  </div>

  <div id="lh-feedback" class="card mb-3">
    <div class="card-body">
      <h2 class="h5 card-title">Send feedback</h2>
      <div id="lh-fb-done" class="alert alert-success d-none" role="status"></div>
      <div id="lh-fb-error" class="alert alert-danger d-none" role="alert"></div>
      <div class="mb-3">
        <label for="lh-fb-message" class="form-label">Your message</label>
        <textarea class="form-control" id="lh-fb-message" rows="4" maxlength="2000" placeholder="What went wrong?"></textarea>
      </div>
      <div class="mb-3 d-none" id="lh-fb-email-wrap">
        <label for="lh-fb-email" class="form-label">Email (optional, if you want a reply)</label>
        <input type="email" class="form-control" id="lh-fb-email" maxlength="254" autocomplete="email">
      </div>
      <div class="form-check mb-2">
        <input class="form-check-input" type="checkbox" id="lh-fb-log">
        <label class="form-check-label" for="lh-fb-log">Include recent log lines</label>
      </div>
      <p class="mb-2"><button type="button" class="btn btn-link p-0" id="lh-fb-toggle" aria-expanded="false" aria-controls="lh-fb-preview">Show what's sent</button></p>
      <pre id="lh-fb-preview" class="border rounded p-2 small d-none" style="max-height: 16rem; overflow: auto; white-space: pre-wrap;"></pre>
      <button type="button" class="btn btn-primary" id="lh-fb-send" disabled>Send</button>
      <p class="form-text mt-2 mb-0">
        Sends your message, the plugin and FPP versions, the last send status, and your display if paired.
        <a href="plugin.php?plugin=lighthunt-fpp-plugin&amp;page=about.php#feedback">See what this sends</a> /
        <a href="https://lightshunt.com/privacy" target="_blank" rel="noopener noreferrer">privacy policy</a>
      </p>
    </div>
  </div>

  </div>

  <p class="text-body-secondary small">Plugin version <span id="lh-version"></span>. <a href="plugin.php?plugin=lighthunt-fpp-plugin&page=about.php">What this sends</a></p>
</div>

<script><?php readfile(__DIR__ . '/js/showdoctor.js'); ?></script>
<script>
(function () {
  var API = '/api/plugin/lighthunt-fpp-plugin/';
  var timer = null;
  var $ = function (id) { return document.getElementById(id); };
  var show = function (id, on) { $(id).classList.toggle('d-none', !on); };

  function call(method, path, body) {
    var opts = { method: method, headers: { 'Accept': 'application/json' } };
    if (body !== undefined) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    return fetch(API + path, opts).then(function (r) { return r.json(); });
  }

  function fail(message) {
    $('lh-error').textContent = message;
    show('lh-error', !!message);
  }

  var netWarn = '';
  function warn(message) {
    $('lh-warn').textContent = message;
    show('lh-warn', !!message);
  }

  function ago(seconds) {
    if (!seconds) { return 'not yet'; }
    var s = Math.max(0, Math.round(Date.now() / 1000 - seconds));
    return s < 90 ? s + ' seconds ago' : new Date(seconds * 1000).toLocaleTimeString();
  }

  var RESULTS = {
    ok: ['Live', 'text-bg-success'],
    offline: ['Cannot reach Light Hunt, retrying', 'text-bg-warning'],
    fpp_unreachable: ['Falcon Player is not answering', 'text-bg-warning'],
    unpaired: ['Not paired', 'text-bg-secondary'],
    cloned: ['Stopped: copy of another player', 'text-bg-danger'],
    remote: ['Stopped: multisync remote', 'text-bg-warning']
  };

  var VOTING = {
    off: 'Off on this player.',
    waiting: 'On. Waiting for a playlist to play.',
    shuffled: 'On, but this playlist is shuffled, so no ballots open. Turn off shuffle to use voting.',
    setup: 'On. Light Hunt has your song list. Turn on voting in Show tools.',
    paused: 'Paused on Light Hunt. Unpause it in Show tools.',
    live: 'Live. A ballot opens with each song.'
  };
  var voteBusy = false;

  function render(s) {
    $('lh-version').textContent = s.version;
    var revoked = s.paired && s.result === 'revoked';
    show('lh-revoked', revoked);
    show('lh-unpaired', (!s.paired || revoked) && !s.pending);
    show('lh-pending', (!s.paired || revoked) && !!s.pending);
    show('lh-paired', s.paired && !revoked);
    // A paired player's key tells Light Hunt who is writing, so only an unpaired one asks for an email.
    show('lh-fb-email-wrap', !s.paired || revoked);
    if (!s.pending) { netWarn = ''; }
    warn(s.pending ? (netWarn || s.pending.warning || '') : '');
    if (s.pending) {
      $('lh-code').textContent = s.pending.code.replace(/^(\d{3})(\d{3})$/, '$1 $2');
      $('lh-pair-link').href = s.pending.url;
    }
    if (s.paired && !revoked) {
      var d = s.display || {};
      $('lh-display').textContent = d.name || 'Your display';
      $('lh-display').href = d.url || '#';
      var r = !s.pollerRunning ? ['The background poller is not running. It starts with Falcon Player; if this stays, restart Falcon Player.', 'text-bg-danger']
        : (s.result === null ? ['Starting...', 'text-bg-secondary'] : (RESULTS[s.result] || RESULTS.ok));
      $('lh-result').textContent = r[0];
      $('lh-result').className = 'badge ' + r[1];
      // The server sends a reason only when there is a problem to explain (offline, a copied card, a multisync remote).
      $('lh-reason').textContent = s.pollerRunning && s.reason ? s.reason : '';
      var stopped = s.result === 'cloned' || s.result === 'remote';
      var nowText = stopped ? 'Not reporting' : s.state === 'playing' ? (s.song || 'Playing') : (s.state === 'between' ? 'Between songs' : 'Nothing right now');
      $('lh-song').textContent = nowText;
      $('lh-last').textContent = ago(s.lastReportAt);
      $('lh-viewers').textContent = s.viewers === null || s.viewers === undefined ? 'unknown' : s.viewers;
      if (!voteBusy) { $('lh-vote-on').checked = !!s.votingEnabled; }
      $('lh-vote-state').textContent = !s.votingEnabled ? VOTING.off : (VOTING[s.voting] || 'Starting...');
      if (d.id) { $('lh-vote-tools').href = 'https://lightshunt.com/for-owners/show?display=' + encodeURIComponent(d.id); }
    }
    show('lh-voting', s.paired && !revoked);
    // Keep polling while a code is waiting or the plugin is paired.
    clearTimeout(timer);
    if (s.pending) { timer = setTimeout(checkPair, 6000); }
    else if (s.paired || revoked) { timer = setTimeout(refresh, 10000); }
  }

  function refresh() {
    return call('GET', 'status').then(render).catch(function () { fail('Could not read the plugin status.'); });
  }

  // Starts the poller if it is not running (a GET never does). The server skips it while Falcon Player is stopped.
  function ensure() {
    return call('POST', 'ensure').catch(function () {}).then(refresh);
  }

  function checkPair() {
    call('POST', 'paircheck').then(function (r) {
      if (r.status === 'expired') { fail('That code expired. Get a new one.'); }
      netWarn = r.warning || '';
      if (r.status === 'paired') { ensure(); } else { refresh(); }
    }).catch(function () { refresh(); });
  }

  $('lh-start').addEventListener('click', function () {
    fail('');
    call('POST', 'pairstart').then(function (r) {
      if (!r.ok) { fail(r.error); return; }
      netWarn = '';
      refresh();
    }).catch(function () { fail('Could not start pairing.'); });
  });

  $('lh-unpair').addEventListener('click', function () {
    if (!confirm('Remove the Light Hunt pairing from this Falcon Player?')) { return; }
    call('POST', 'unpair').then(function () { fail(''); refresh(); }).catch(function () { fail('Could not unpair. Try again.'); });
  });

  $('lh-vote-on').addEventListener('change', function () {
    var on = $('lh-vote-on').checked;
    voteBusy = true;
    fail('');
    call('POST', 'voting', { enabled: on }).then(function (r) {
      if (!r.ok) { fail(r.error); $('lh-vote-on').checked = !on; }
    }).catch(function () {
      fail('Could not change song voting. Try again.');
      $('lh-vote-on').checked = !on;
    }).then(function () { voteBusy = false; refresh(); });
  });

  // Feedback: the browser sends only the words and the log checkbox. The context is built on the player.
  var fbContext = null;
  var fbOpen = false;

  function fbMessage() { return $('lh-fb-message').value.trim(); }

  function fbSync() {
    $('lh-fb-send').disabled = fbMessage().length < 5;
    renderPreview();
  }

  function renderPreview() {
    if (!fbOpen) { return; }
    if (!fbContext) { $('lh-fb-preview').textContent = 'Loading...'; return; }
    var ctx = {};
    Object.keys(fbContext).forEach(function (k) { if (k !== 'log' || $('lh-fb-log').checked) { ctx[k] = fbContext[k]; } });
    var shown = { source: 'fpp', message: fbMessage() || '(what you type above)' };
    var email = $('lh-fb-email').value.trim();
    if (email && !$('lh-fb-email-wrap').classList.contains('d-none')) { shown.email = email; }
    shown.context = ctx;
    $('lh-fb-preview').textContent = JSON.stringify(shown, null, 2);
  }

  $('lh-fb-toggle').addEventListener('click', function () {
    fbOpen = !fbOpen;
    $('lh-fb-toggle').setAttribute('aria-expanded', fbOpen ? 'true' : 'false');
    $('lh-fb-toggle').textContent = fbOpen ? 'Hide what\'s sent' : 'Show what\'s sent';
    show('lh-fb-preview', fbOpen);
    if (fbOpen) {
      fbContext = null;
      renderPreview();
      call('GET', 'feedbackpreview').then(function (r) { fbContext = r.context || {}; renderPreview(); })
        .catch(function () { $('lh-fb-preview').textContent = 'Could not read what would be sent.'; });
    }
  });

  ['lh-fb-message', 'lh-fb-email', 'lh-fb-log'].forEach(function (id) {
    $(id).addEventListener('input', fbSync);
    $(id).addEventListener('change', fbSync);
  });

  $('lh-fb-send').addEventListener('click', function () {
    show('lh-fb-error', false);
    show('lh-fb-done', false);
    $('lh-fb-send').disabled = true;
    call('POST', 'feedback', { message: fbMessage(), email: $('lh-fb-email').value.trim(), includeLog: $('lh-fb-log').checked })
      .then(function (r) {
        if (r.ok) {
          $('lh-fb-message').value = '';
          $('lh-fb-email').value = '';
          $('lh-fb-log').checked = false;
          $('lh-fb-done').textContent = 'Thanks. Your feedback was sent.';
          show('lh-fb-done', true);
        } else {
          $('lh-fb-error').textContent = r.reason || 'Could not send your feedback. Try again.';
          show('lh-fb-error', true);
        }
        fbSync();
      })
      .catch(function () {
        $('lh-fb-error').textContent = 'Could not send your feedback. Try again.';
        show('lh-fb-error', true);
        fbSync();
      });
  });

  // Show doctor tab.
  var DOC_TITLES = {
    controller_unreachable: 'Controllers answer',
    sequence_exceeds_outputs: 'Sequences fit the outputs',
    missing_file: 'Playlist files are on this player',
    nothing_tonight: 'Something plays tonight',
    pi_health: 'Pi health'
  };
  // The word carries the meaning; the colour only repeats it.
  var DOC_BADGE = { ok: ['OK', 'text-bg-success'], warn: ['Look', 'text-bg-warning'], fail: ['Fix', 'text-bg-danger'] };
  var DOC_ALERT = { ok: 'alert-success', warn: 'alert-warning', fail: 'alert-danger' };
  var docLoaded = false;
  var docBusy = false;

  function renderDoctor(r) {
    $('lh-doc-report').checked = !!r.report;
    var last = r.last;
    var rows = $('lh-doc-rows');
    rows.textContent = '';
    if (!last || !last.results) {
      show('lh-doc-verdict', false);
      $('lh-doc-when').textContent = 'Not checked yet.';
      $('lh-doc-run').textContent = 'Run checks';
      return;
    }
    $('lh-doc-run').textContent = 'Run again';
    $('lh-doc-verdict').className = 'alert ' + (DOC_ALERT[last.verdict.status] || 'alert-secondary');
    $('lh-doc-verdict').textContent = last.verdict.text;
    show('lh-doc-verdict', true);
    last.results.forEach(function (res) {
      rows.appendChild(docRow(res.status, DOC_TITLES[res.id] || res.id, [res.detail]));
    });
    $('lh-doc-when').textContent = 'Checked ' + ago(last.checkedAt) + '.';
  }

  function docRow(status, title, lines) {
    var li = document.createElement('li');
    li.className = 'list-group-item d-flex gap-3 align-items-start';
    var b = DOC_BADGE[status] || DOC_BADGE.warn;
    var badge = document.createElement('span');
    badge.className = 'badge ' + b[1] + ' mt-1';
    badge.style.minWidth = '3rem';
    badge.textContent = b[0];
    var body = document.createElement('div');
    var t = document.createElement('div');
    t.className = 'fw-semibold';
    t.textContent = title;
    body.appendChild(t);
    lines.forEach(function (line) {
      if (!line) { return; }
      var d = document.createElement('div');
      d.className = 'small text-body-secondary';
      d.textContent = line;
      body.appendChild(d);
    });
    li.appendChild(badge);
    li.appendChild(body);
    return li;
  }

  // Channel layout: the same checks as lightshunt.com/doctor, run here in the browser. Nothing is sent.
  function renderDesign(cfg) {
    var list = $('lh-doc-design');
    var verdict = $('lh-doc-design-verdict');
    list.textContent = '';
    var models = cfg && cfg.overlays && cfg.overlays.models;
    if (!window.LightHuntShowDoctor || !models || !models.length) {
      verdict.className = 'alert alert-secondary';
      verdict.textContent = window.LightHuntShowDoctor
        ? 'This player has no models from xLights, so there is nothing to check. Upload them from xLights with FPP Connect.'
        : 'The channel layout check did not load. Reload the page.';
      show('lh-doc-design-verdict', true);
      return;
    }
    var r = window.LightHuntShowDoctor.runFpp(cfg);
    var worst = r.findings.some(function (f) { return f.severity === 'fail'; }) ? 'fail' : r.findings.length ? 'warn' : 'ok';
    verdict.className = 'alert ' + DOC_ALERT[worst];
    verdict.textContent = r.verdict + ' (' + r.models + ' models checked).';
    show('lh-doc-design-verdict', true);
    r.findings.forEach(function (f) {
      var names = f.models.length > 5 ? f.models.slice(0, 5).join(', ') + ' and ' + (f.models.length - 5) + ' more' : f.models.join(', ');
      list.appendChild(docRow(f.severity, f.title, [f.models.length > 2 ? names : '', f.fixHint]));
    });
  }

  function loadDesign() {
    call('GET', 'doctordesign').then(renderDesign).catch(function () {
      $('lh-doc-design-verdict').className = 'alert alert-secondary';
      $('lh-doc-design-verdict').textContent = 'Could not read the models on this player.';
      show('lh-doc-design-verdict', true);
    });
  }

  function loadDoctor() {
    call('GET', 'doctor').then(function (r) { docLoaded = true; renderDoctor(r); })
      .catch(function () { $('lh-doc-when').textContent = 'Could not read the last check.'; });
    loadDesign();
  }

  function selectTab(doctor) {
    $('lh-tab-main').classList.toggle('active', !doctor);
    $('lh-tab-doctor').classList.toggle('active', doctor);
    $('lh-tab-main').setAttribute('aria-selected', doctor ? 'false' : 'true');
    $('lh-tab-doctor').setAttribute('aria-selected', doctor ? 'true' : 'false');
    show('lh-pane-main', !doctor);
    show('lh-pane-doctor', doctor);
    if (doctor && !docLoaded) { loadDoctor(); }
  }
  $('lh-tab-main').addEventListener('click', function () { selectTab(false); });
  $('lh-tab-doctor').addEventListener('click', function () { selectTab(true); });

  $('lh-doc-run').addEventListener('click', function () {
    if (docBusy) { return; }
    docBusy = true;
    $('lh-doc-run').disabled = true;
    $('lh-doc-when').textContent = 'Checking...';
    call('POST', 'doctorrun').then(function (r) {
      renderDoctor({ last: r.last, report: $('lh-doc-report').checked });
      loadDesign();
    }).catch(function () {
      $('lh-doc-when').textContent = 'The check did not finish. Try again.';
    }).then(function () { docBusy = false; $('lh-doc-run').disabled = false; });
  });

  $('lh-doc-report').addEventListener('change', function () {
    var on = $('lh-doc-report').checked;
    call('POST', 'doctorsetting', { report: on }).then(function (r) {
      if (!r.ok) { $('lh-doc-report').checked = !on; $('lh-doc-when').textContent = r.error; }
    }).catch(function () { $('lh-doc-report').checked = !on; });
  });

  if (location.hash === '#doctor') { selectTab(true); }

  ensure();
})();
</script>
