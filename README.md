# Light Hunt for Falcon Player

Shows the song your Falcon Player (FPP) show is playing on your display page at [lightshunt.com](https://lightshunt.com). Visitors see the title, the artist and the time left. The plugin only reads what is playing. It never controls your show.

This folder is laid out as the root of an FPP plugin repository (`lighthunt-fpp-plugin`).

## Supported versions

FPP 9 and later. `pluginInfo.json` lists FPP 9.0 and up, so Plugin Manager marks FPP 8 and earlier as incompatible. It runs on the PHP and curl that FPP ships (PHP 7.4 through 8.x), with no extra packages.

Tested on FPP 9.5, 10.2 and the 10.x nightly. Later versions should work but are untested. FPP 8 isn't supported yet.

FPP 9 installs follow the `fpp9` branch, which only gets commits tested on FPP 9, so pressing **Update** on FPP 9 never pulls in a change made for FPP 10. FPP 10 and later follow `main`.

## Install

1. In FPP, open **Content Setup > Plugin Manager**.
2. Find **Light Hunt - Now Playing** and select **Install**. (Until it is listed there, paste this into the plugin URL box: `https://raw.githubusercontent.com/j0ebin/lighthunt-fpp-plugin/main/pluginInfo.json`.)
3. Open **Status/Control > Light Hunt**.

## Pair

1. Select **Get a pairing code**. The page shows 6 digits.
2. Sign in to Light Hunt as the owner of your display and open the link under the code (`https://lightshunt.com/relay/pair?code=...`). Or open your display page in edit mode and type the code under **Show what's playing**.
3. Confirm. The plugin page updates by itself and shows the display name, the song, when it last reported, and how many people looked at your page in the last 5 minutes.

A display has one sender at a time. Pairing a new FPP, or the Windows Relay for Light-O-Rama, replaces the old one.

## Unpair and uninstall

- **Unpair** on the plugin page removes the key from this player. To also end the link on Light Hunt, press **Unpair** under **Show what's playing** on your display page. If you unpair there first, the plugin notices within 30 seconds and offers to pair again.
- **Uninstall** in Plugin Manager stops the poller and removes the key, the status file and the log from this player.

## What it sends

Only after you pair. Every 5 seconds the plugin reads `http://127.0.0.1/api/fppd/status` on the player. It posts to `https://lightshunt.com/api/now-playing` when the song changes, and at least every 30 seconds. This is the whole payload:

```json
{
  "source": "fpp",
  "state": "playing",
  "name": "Carol of the Bells.fseq",
  "mediaFilename": "Carol of the Bells.mp3",
  "mediaTitle": "Carol of the Bells",
  "mediaArtist": "Mannheim Steamroller",
  "remaining": 134,
  "duration": 212,
  "showName": "Christmas Show",
  "relayVersion": "0.2.2"
}
```

- `state` is `playing`, `between` (paused, or running a script or pause) or `off`. When it is `off` or `between`, the song fields are left out.
- `mediaTitle` and `mediaArtist` come from the audio file's tags, read once per file through FPP's own `/api/media/<file>/meta`. They are left out when the file has none.
- `showName` is the name of the running playlist.
- `relayVersion` is the plugin version. The pairing step sends the plugin version too, then the code check. The plugin does not send the FPP version.

Everything above, the playlist name included, appears on your public Light Hunt display page. Nothing else leaves the player: no sequences, audio files, network settings, location or passwords. Light Hunt sends back only `{ "ok": true, "viewers": 3 }`.

On the player, the key lives in `plugindata/lighthunt-fpp-plugin/config.json` (mode 0600). The one log is `plugin-lighthunt-fpp-plugin.log`; it never holds the key.

## How it works

- `lighthunt-daemon.php` is a small PHP process started by `scripts/postStart.sh` and stopped by `scripts/preStop.sh`. It runs apart from `fppd`, so a slow network can never hold up your show. Until you pair it, it only waits.
- The poller always runs as the media folder's owner (`fpp`), whether `postStart.sh` or the plugin page started it. `preStop.sh`, `fpp_uninstall.sh` and an update find it by its command line, so no copy is missed. While `fppd` is stopped the plugin page does not restart it, and the poller never creates the data folder (only `fpp_install.sh` and `start.sh` run as root do), so nothing comes back after an uninstall.
- Failed sends retry after 5, 10, 20, 40, then 60 seconds. A `429` waits for `Retry-After`. A `401` (unpaired on Light Hunt) stops sending until you pair again.
- `lib/lighthunt.php` (namespace `LightHunt`, so it cannot clash with FPP or other plugins) holds the mapping from FPP's status to the report (`lh_build_report`) and the poll loop (`lh_tick`).
- `api.php` serves the page's endpoints at `/api/plugin/lighthunt-fpp-plugin/`. The browser never sees the key or the pairing secret. `status` is a read-only GET; `ensure` (a POST the page calls when it opens) starts the poller if the player is paired and fppd is running; `pairstart` is refused while the player is already paired, so press Unpair first.
- `status.php`, `about.php` and `menu.inc` are the pages.

## Tests

No framework. With Docker:

```sh
docker run --rm -v "$PWD":/p php:7.4-cli sh -c 'cd /p && php tests/run.php'
docker run --rm -v "$PWD":/p php:8.4-cli sh -c 'cd /p && php tests/run.php'
```

`tests/fixtures/` holds `/api/fppd/status` shapes written from FPP's source (`Playlist::GetCurrentStatus`, `GetCurrentFPPDStatus`).

To try it without touching lightshunt.com, set the `LIGHTHUNT_BASE_URL` environment variable (for example `LIGHTHUNT_BASE_URL=http://192.168.1.20:3055 php lighthunt-daemon.php`) and point it at a local stand-in server. The poller and the plugin pages only read it from their own environment, so it is for development, not for a running player.

## License

MIT, see `LICENSE`.

Light Hunt is not part of the Falcon Player project and is not endorsed by it. Falcon Player and FPP belong to their authors.
