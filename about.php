<div class="container-fluid">
  <h1 class="h3 mb-3">Light Hunt: what this sends</h1>
  <p>
    This plugin tells <strong>lightshunt.com</strong> which song your show is playing, so visitors see it on your display page.
    It does nothing until you pair it, and it never controls your show. The one other thing it can send is feedback, and only when you press <em>Send</em> on the plugin page (see below).
  </p>

  <h2 class="h5">What leaves this device</h2>
  <ul>
    <li>The song now playing: the title and artist from the audio file's tags if they exist, the audio file name, and the sequence file name.</li>
    <li>Whether a song is playing, between songs, or the show is off, and how many seconds are left and how long the song is.</li>
    <li>The name of the playlist that is running, and the next songs in it (up to three, with their title and artist tags and file names).</li>
    <li>The plugin version.</li>
    <li>Your pairing key, so Light Hunt knows which display the report is for.</li>
  </ul>
  <p><strong>What it sends, including the playlist name, appears on your public Light Hunt display page</strong>, where anyone who visits can see it.</p>
  <p>Nothing else is sent while it runs. It sends nothing to anyone but lightshunt.com. It does not send your sequences, audio files, passwords, network settings or location.</p>

  <h2 class="h5" id="feedback">Send feedback</h2>
  <p>The <em>Send feedback</em> card on the plugin page sends a report to Light Hunt <strong>only when you press Send</strong>. Nothing is sent as you type, and <em>Show what's sent</em> on that card shows the details before you decide. A report contains:</p>
  <ul>
    <li>Your message (5 to 2000 characters).</li>
    <li>Your email address, only if you type one in. It is used only to reply to you.</li>
    <li>The plugin version, the Falcon Player version and branch, and the PHP version.</li>
    <li>Whether this player is paired, the result of the last send to Light Hunt (for example <code>ok</code> or <code>offline</code>) with its reason code, and the time of the last report.</li>
    <li>The last 20 lines of the plugin log, only if you tick <em>Include recent log lines</em>. Before sending, home folder names become <code>&lt;user&gt;</code>, IP addresses become <code>&lt;ip&gt;</code> and pairing keys become <code>&lt;key&gt;</code>. The log never holds the key to begin with.</li>
    <li>If the player is paired, the pairing key goes along as a sign-in header (not in the message), so Light Hunt can tell which display wrote. Unpaired, the report is anonymous apart from any email you type.</li>
  </ul>
  <p>Light Hunt support reads the report. No sequences, audio files, network settings, location or passwords are included. See the <a href="https://lightshunt.com/privacy" target="_blank" rel="noopener noreferrer">privacy policy</a>.</p>

  <h2 class="h5">What stays on this device</h2>
  <ul>
    <li>Your pairing key, in a file only the Falcon Player user can read (<code>plugindata/lighthunt-fpp-plugin/config.json</code>).</li>
    <li>A small status file for the plugin page, and the log <code>plugin-lighthunt-fpp-plugin.log</code>. The log never contains the key.</li>
  </ul>

  <h2 class="h5">How often</h2>
  <p>It reads Falcon Player's own status every 5 seconds. It sends a report when the song changes and at least every 30 seconds. If Light Hunt hears nothing for 60 seconds, visitors see your setlist instead.</p>

  <h2 class="h5">Stopping it</h2>
  <p>Select <em>Unpair</em> on the plugin page, then also press <em>Unpair</em> under <em>Show what's playing</em> on your display page. Uninstalling the plugin removes its key, status file and log from this device.</p>

  <h2 class="h5">About this plugin</h2>
  <p>
    Developed by Light Hunt (lightshunt.com).<br />
    <a href="https://github.com/j0ebin/lighthunt-fpp-plugin" target="_blank" rel="noopener noreferrer">Git Repository</a><br />
    <a href="https://github.com/j0ebin/lighthunt-fpp-plugin/issues" target="_blank" rel="noopener noreferrer">Bug Reporter</a>
  </p>

  <p class="text-body-secondary small">Light Hunt is not part of the Falcon Player project and is not endorsed by it.</p>
</div>
