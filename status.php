<div class="container-fluid" id="lh-root">
  <h1 class="h3 mb-3">Light Hunt: show what's playing</h1>
  <p class="text-body-secondary">
    Shows the song Falcon Player is playing on your display page at lightshunt.com.
    Falcon Player runs your lights as usual; this plugin only reads what is playing.
  </p>

  <div id="lh-error" class="alert alert-danger d-none" role="alert"></div>
  <div id="lh-warn" class="alert alert-warning d-none" role="status"></div>

  <div id="lh-unpaired" class="card mb-3 d-none">
    <div class="card-body">
      <h2 class="h5 card-title">Pair with your display</h2>
      <p id="lh-revoked" class="alert alert-warning d-none">Light Hunt no longer accepts the old pairing, usually because it was unpaired on your display page. Pair again to continue.</p>
      <ol>
        <li>Select <strong>Get a pairing code</strong>.</li>
        <li>Sign in to Light Hunt with the account that owns your display and open the link shown with your code. Or open your display page in edit mode and type the code under <em>Show what's playing</em>.</li>
        <li>This page updates by itself when you confirm.</li>
      </ol>
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

  <p class="text-body-secondary small">Plugin version <span id="lh-version"></span>. <a href="plugin.php?plugin=lighthunt-fpp-plugin&page=about.php">What this sends</a></p>
</div>

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
    }
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

  ensure();
})();
</script>
