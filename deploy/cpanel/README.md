# cPanel installation and updates

The public site runs on existing PHP hosting. Video processing remains on your laptop.
This installer deploys the **current public-site foundation**, not the unfinished AI
matching system. It starts with an empty, searchable catalogue; automatic laptop sync,
image publication, setup histories, rarity and value estimates are future work.

## Requirements

- PHP **8.1+** with cURL, JSON, SHA-256, and a working system CA trust store.
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

1. Select PHP 8.1 or later in cPanel and enable cURL if needed.
2. Create an **empty folder or subdomain** for Bike + Kit. Existing `index.php` or
   `index.html` websites are not overwritten.
3. Download the root `loader.php` from this GitHub repository and upload it there.
4. Edit **your uploaded copy** in File Manager: set `$setupKey = '';` to a random
   password of at least 32 characters from your password manager. Keep it private.
   Do not edit/commit the key in GitHub. Alternatively set `BIKERIDERS_SETUP_KEY`
   through your hosting provider's PHP environment configuration.
5. Visit `https://YOUR-DOMAIN/YOUR-FOLDER/loader.php`. Check the requirements, enter
   the key and press **Install / update**. Then open `index.php` in the same folder.

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

- Reopen `loader.php`, enter the key, and press **Check updates**.
- Press **Install / update** to fetch the release published on GitHub `main`.
- Repeating an installation is safe: data is not reset, and the same release is reused.
- **Roll back** switches to the previous verified code release. Catalogue data is
  unchanged. This version performs no data migrations.
- A failed checksum/download does not switch the active release. Retry after fixing
  the reported network, quota or permission problem.
- Keep backups of the private `data/` directory before updating. Rollback is a code
  rollback, not a substitute for data backups. Older releases are retained; remove
  obsolete ones manually only after checking `state.json` current/previous entries.
- The loader does **not overwrite itself** or your installation key. If the loader
  needs upgrading, replace it manually from GitHub and preserve your local key, or
  use the environment-variable binding so replacement needs no secret edits.

No unattended updates or cron tasks are configured. Each activation is an explicit,
authenticated action. Hosting execution-time limits can interrupt large future releases;
the current release is small. Ask the provider about limits if downloads time out.

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

1. Edit `deploy/cpanel/app/` and run the tests.
2. Run `python3 tools/build_cpanel_release.py --version X.Y.Z` to regenerate hashes.
3. Commit the app and manifest together and push them to GitHub `main`.
4. Use the site's loader to check/install the update. It never follows a URL supplied
   by a visitor. If GitHub changes while files are downloading, a hash mismatch fails
   safely; retry after publication finishes.

Tests: `python -m pytest`. PHP lifecycle tests run if `php` is on PATH, or if
`PHP_BIN` names a PHP CLI executable. They cover installation, repeat installation,
update, rollback, data preservation, corrupt downloads, path traversal, modified
release detection, and catalogue approval/escaping. Check actual hosting permissions,
PHP extensions and outbound access on cPanel; local tests cannot establish those.

For an opt-in browser-style test against the published GitHub release, run
`python tools/smoke_cpanel.py`. It starts an isolated local PHP server and exercises
the standalone loader, download, installation, existing-site protection, repeated
installation, data preservation and public routes. It never connects to your cPanel.
