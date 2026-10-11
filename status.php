<div class="container-fluid" id="lh-root">
  <h1 class="h3 mb-3">Light Hunt: show what's playing</h1>
  <p class="text-body-secondary">
    Shows the song Falcon Player is playing on your display page at lightshunt.com.
    Falcon Player runs your lights as usual; this plugin only reads what is playing.
  </p>

  <div id="lh-error" class="alert alert-danger d-none" role="alert"></div>

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
      <p class="mb-1">Status: <span id="lh-result" class="badge"></span></p>
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

  <p class="text-body-secondary small">Plugin version <span id="lh-version"></span>. <a href="plugin.php?plugin=lighthunt-fpp-plugin&page=about.php">What this sends</a></p>
</div>

<script>
(function () {
  var API = '/api/plugin/lighthunt-fpp-plugin/';
  var timer = null;
  var $ = function (id) { return document.getElementById(id); };
  var show = function (id, on) { $(id).classList.toggle('d-none', !on); };

  function call(method, path) {
    return fetch(API + path, { method: method, headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); });
  }

  function fail(message) {
    $('lh-error').textContent = message;
    show('lh-error', !!message);
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
    unpaired: ['Not paired', 'text-bg-secondary']
  };

  function render(s) {
    $('lh-version').textContent = s.version;
    var revoked = s.paired && s.result === 'revoked';
    show('lh-revoked', revoked);
    show('lh-unpaired', (!s.paired || revoked) && !s.pending);
    show('lh-pending', (!s.paired || revoked) && !!s.pending);
    show('lh-paired', s.paired && !revoked);
    if (s.pending) {
      $('lh-code').textContent = s.pending.code.replace(/^(\d{3})(\d{3})$/, '$1 $2');
      $('lh-pair-link').href = s.pending.url;
    }
    if (s.paired && !revoked) {
      var d = s.display || {};
      $('lh-display').textContent = d.name || 'Your display';
      $('lh-display').href = d.url || '#';
      var r = !s.pollerRunning ? ['The background poller is not running. It is being started; if this stays, restart Falcon Player.', 'text-bg-danger']
        : (s.result === null ? ['Starting...', 'text-bg-secondary'] : (RESULTS[s.result] || RESULTS.ok));
      $('lh-result').textContent = r[0];
      $('lh-result').className = 'badge ' + r[1];
      var nowText = s.state === 'playing' ? (s.song || 'Playing') : (s.state === 'between' ? 'Between songs' : 'Nothing right now');
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

  function checkPair() {
    call('GET', 'paircheck').then(function (r) {
      if (r.status === 'expired') { fail('That code expired. Get a new one.'); }
      refresh();
    }).catch(function () { refresh(); });
  }

  $('lh-start').addEventListener('click', function () {
    fail('');
    call('POST', 'pairstart').then(function (r) {
      if (!r.ok) { fail(r.error); return; }
      refresh();
    }).catch(function () { fail('Could not start pairing.'); });
  });

  $('lh-unpair').addEventListener('click', function () {
    if (!confirm('Remove the Light Hunt pairing from this Falcon Player?')) { return; }
    call('POST', 'unpair').then(function () { fail(''); refresh(); });
  });

  refresh();
})();
</script>
