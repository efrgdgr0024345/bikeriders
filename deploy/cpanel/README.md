# cPanel installation and updates

The public site runs on existing PHP hosting. Video processing remains on your laptop.
This installer deploys the **current public-site foundation**, not the unfinished AI
matching system. It starts with an empty, searchable catalogue; automatic laptop sync,
image publication, setup histories, rarity and value estimates are future work.

## Requirements

- PHP **8.1+** with cURL, sessions, JSON, SHA-256, and a working system CA trust store.
- Outbound HTTPS to **raw.githubusercontent.com** (or **api.github.com** when a
  private-repository token is configured). No SSH, Git, Composer or shell access needed.
- A writable website folder and a writable private directory outside the web root.
- HTTPS on your domain. No MySQL database or paid server is needed for this initial
  catalogue; its records are stored in a private JSON file.
- If this GitHub repository is private, supply a fine-grained, read-only Contents token
  scoped to this repository as the `BIKERIDERS_GITHUB_TOKEN` PHP environment variable.
  Never paste tokens into the website form, chat, source code, or a public file. If
  your provider cannot inject PHP environment variables, ask them to configure the
  binding. Making a private repository public is a separate decision, not required.

## Install using only File Manager

1. Select PHP 8.1 or later in cPanel and enable cURL and sessions if needed.
2. Create an **empty folder or subdomain** for Bike + Kit. Existing `index.php` or
   `index.html` websites are not overwritten.
3. Download the root `loader.php` from this GitHub repository and upload it there.
4. Visit `https://YOUR-DOMAIN/YOUR-FOLDER/loader.php`. In **cPanel → File Manager**,
   open the generated **`var/setup-token.php`** beside it. Copy the `setup_token`
   value into the maintenance login, and save it in your password manager. The token
   is never shown on the public page; HTTP requests to that guarded PHP file return
   404. The token file is removed after login and only a password hash is retained.
   Alternatively provision `BIKERIDERS_SETUP_KEY` in your PHP hosting environment.
5. Check the hosting requirements, confirm installation, and press **Install & set up**.
   The console shows stage progress. Open the site once setup succeeds.

This is the same single-file maintenance workflow used by `efrgdgr0024345/map`.
Bike + Kit downloads its small public-site release through a checksum manifest;
it does not need the map project's ZIP/SQLite extensions, timetable import or dependencies.
Apache/cPanel should honour the generated `var/.htaccess` deny rules. On Nginx, deny
access to `var/` in server configuration as well. Do not put source videos in `var/`.

Maintenance sessions expire after one hour of inactivity. Use **Lock maintenance**
on a shared computer. All state-changing browser actions require login and a CSRF token.
The console can rotate the maintenance key; environment-configured keys must be changed
in hosting settings. If a generated key is lost, remove `var/maintenance.php` through
File Manager and reload to generate a new setup token. Catalogue data is unaffected.

The loader downloads only the approved PHP/CSS/JS release files from the fixed GitHub
repository over verified HTTPS. It compares every file against the SHA-256 release
manifest and stages a full release before switching the active pointer. Hashes protect
against corruption and partial updates; the repository itself remains the trust source.

Private state defaults to a `.bikeriders-<installation-id>` folder in the parent of
this domain's document root. The authenticated success message gives its exact path.
If that location is not writable, set `BIKERIDERS_PRIVATE_DIR` to a writable absolute
path **outside all web roots on the account**, not only this domain. The loader can
check this domain's root but cannot discover other domains' document roots. Keep
the private folder out of backups that are downloadable through a public URL.

## Updates and rollback

- Reopen `loader.php`, unlock maintenance if needed, and press **Check updates**.
- Confirm code replacement and press **Back up & update** to fetch the release on `main`.
- The app is staged and verified, then the loader is updated last. Unchanged app files
  are reused from the current release after checking their actual SHA-256 hashes.
- GitHub/CDN caches can delay a new branch release appearing. To install an exact
  revision, optionally set `BIKERIDERS_RELEASE_REF` to a full 40-character Git commit
  SHA. Unset it (or set `main`) to resume tracking normal updates. A pinned revision
  remains pinned until you change that setting.
- Repeating an installation is safe: data is not reset, and the same release is reused.
- **Roll back app** switches to the previous verified app release. Catalogue data is
  unchanged. This version performs no data migrations. The maintenance loader remains
  current; previous loader copies are retained separately in private `backups/`.
- A failed checksum/download does not switch the active release. Retry after fixing
  the reported network, quota or permission problem.
- Keep backups of the private `data/` directory before updating. Rollback is a code
  rollback, not a substitute for data backups. Older releases are retained; remove
  obsolete ones manually only after checking `state.json` current/previous entries.
- The loader **updates itself** after verifying its release checksum and a backup of
  its previous contents. Backups are named `backups/loader-<sha256>.php` in the private
  directory. Maintenance credentials are in `var/maintenance.php`, separate from code.
- To migrate from the earlier manually edited loader, upload the new `loader.php` once
  and sign in with your previous key. If it existed only inside the old file, copy it
  privately into your password manager before replacement and use it as an environment
  key, or use the new generated setup token. If the old inline key is retained in the
  uploaded file, the first successful login migrates it to a password hash before any
  self-update. Existing private catalogue paths and managed `index.php` are preserved.

The progress console reports the current stage, elapsed time and last report age.
Percentages describe stages, not time remaining. An interrupted operation is detected
when its OS lock is free but its last progress report says it was running. Do not delete
lock files to force an update; a lock file remaining on disk is normal.

No unattended updates or cron tasks are configured. Each activation is an explicit,
authenticated action. Hosting execution-time limits can interrupt large future releases;
the current release is small. Ask the provider about limits if downloads time out.

Optional cPanel Terminal commands (no sudo required):

```sh
php loader.php --check
php loader.php --show-setup-token  # Only before initial maintenance login
php loader.php --install
php loader.php --update
php loader.php --status
php loader.php --rollback
```

CLI installation is authorised by access to the hosting account and does not require
a browser login. The token command is local-only and never serves the key over HTTP.
Visit the loader in your browser once before CLI installation so `var/storage.php`
records the domain document root and private storage path. For a CLI-only first install,
set `BIKERIDERS_DOCUMENT_ROOT` to the domain's absolute document root; optionally set
`BIKERIDERS_PRIVATE_DIR` too. This prevents subfolder installations accidentally using
different storage locations between the browser and cPanel Terminal.

## Current catalogue format

The initial site reads `PRIVATE_DIRECTORY/data/catalog.json`. It only displays records
whose `approved` field is exactly `true`. You can edit this file through File Manager
for initial curated records. Back it up before editing, and replace it atomically when
possible. Keep private source videos, stills, names, exact routes, and identifying
information out of this catalogue. There is no public write endpoint or image route.

```json
{
  "schema": 1,
  "sightings": [
    {
      "approved": true,
      "setup_id": "setup-001",
      "bike": "Red gravel bike",
      "kit": "Navy jersey, black shorts",
      "seen_at": "2026-11-14",
      "tags": ["gravel", "red", "navy"]
    }
  ]
}
```

Only bike/kit descriptions, tags and sighting dates are rendered. This first version
does not publish images. Bike/garment-only assets and the review/sync pipeline must
be completed before enabling public photographs.

## Publishing a code update (maintainers)

1. Edit `deploy/cpanel/app/` and/or `loader.php`.
2. Run `python3 tools/build_cpanel_release.py --version X.Y.Z` to regenerate hashes.
3. Run the tests, then commit the app, loader and manifest together and push to `main`.
4. Use the site's loader to check/install the update. It never follows a URL supplied
   by a visitor. If GitHub changes while files are downloading, a hash mismatch fails
   safely; retry after publication finishes.

Tests: `python -m pytest`. PHP lifecycle tests run if `php` is on PATH, or if
`PHP_BIN` names a PHP CLI executable. They cover installation, repeat installation,
update, rollback, data preservation, corrupt downloads, path traversal, modified
release detection, loader self-update/backup, failed self-update recovery, generated
tokens, key rotation, legacy-key migration, CSRF, sessions, and catalogue approval/escaping.
Check actual hosting permissions,
PHP extensions and outbound access on cPanel; local tests cannot establish those.

For an opt-in browser-style test against the published GitHub release, run
`python tools/smoke_cpanel.py`. It starts an isolated local PHP server and exercises
the standalone loader, download, installation, existing-site protection, repeated
installation, data preservation and public routes. It never connects to your cPanel.
