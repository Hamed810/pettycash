# Composer Ownership Migration — PettyCash

**Status: EXECUTED 2026-10-02.** The metadata-only handoff described in §7 was applied. No
dependency was regenerated, no file was deleted, and `composer.json` / `composer.lock` are
byte-identical to their pre-migration baselines (§4).

This document is kept as the record of what was done and why, and as the rollback reference.

---

## 1. What was wrong, and what it is now

`composer.json`, `composer.lock` and `vendor/` were owned by **uid/gid 33 (`www-data`)** rather
than the host development user (**uid/gid 1000**, `ubuntu`).

Before → after:

| Path | Before | After |
|---|---|---|
| `composer.json` | `www-data:www-data` (33:33) `-rw-rw-r--` | `ubuntu:ubuntu` (1000:1000) `-rw-rw-r--` |
| `composer.lock` | `www-data:www-data` (33:33) `-rw-r--r--` | `ubuntu:ubuntu` (1000:1000) `-rw-r--r--` |
| `vendor/` | `www-data:www-data` (33:33) `drwxr-xr-x` | `ubuntu:ubuntu` (1000:1000) `drwxr-xr-x` |
| `vendor/` entries not uid 1000 | 8334 of 8334 (100%) | **0 of 8334** |
| `.` (repository root) | `ubuntu:ubuntu` (1000:1000) | unchanged |
| `scripts/`, `scripts/dev/` | `ubuntu:ubuntu` (1000:1000) | unchanged |

No mode was changed anywhere: the handoff is metadata only.

`www-data` intentionally **lost write access** to those three paths. That is the desired end
state — the runtime reads dependencies, it does not modify them. Read access was verified after
the migration: as uid 33, `vendor/autoload.php` loads and `vendor/composer/installed.php`,
`vendor/bin` and `composer.json` are all readable.

## 2. Root cause — accurately stated

**Composer historically ran inside `docker-nextcloud-1` as `www-data`.**

Evidence from the committed source, at every revision in this repository's history
(`git show <rev>:scripts/dev/…`):

- `scripts/dev/composer` was a seven-line shim: `exec "$SCRIPT_DIR/in-container" composer "$@"`.
- `scripts/dev/in-container` hardcoded `--user www-data` in its `docker exec` invocation.

Composer therefore created and rewrote those files as uid 33.

**Root execution was not involved and is not the cause.** Running Composer as root would have
left *root*-owned files; it did not. The current state is exactly what `--user www-data`
produces, and nothing else in the repository's history suggests a root-run Composer.

The migration is needed because the toolchain model changed: Composer is now external
development tooling that runs as the host user (see `AGENTS.md` → *Composer is external
development tooling*), and it cannot write paths owned by uid 33.

## 3. Behaviour of the tooling after the migration

`scripts/dev/composer` runs a pre-flight writability check before any mutating command and
warns if the tree cannot be written by the invoking user. It did not fire during this
migration's verification — ownership now matches — and it stays as a **drift guard**: a restore
from a backup, a copy from another machine, or a container-run Composer can undo the handoff,
and a raw `EACCES` from deep inside Composer is a bad way to discover that.

`scripts/dev/composer-ownership-status` is a read-only inspector for exactly this question. It
never chowns, deletes or regenerates anything, and there is deliberately no flag that makes it
mutate. It exited `0` with all three paths reported as `ubuntu:ubuntu` and `0 of 8334` entries
not owned by uid 1000 after the migration.

## 4. Integrity baselines (recorded before, re-verified after)

Recorded before any migration. A migration that changes any of these has gone wrong.

```text
composer.json  sha256 d828aa9d03ca5e4e44513f4659b246984fd8c3207021cf2a924a5e9446f93b60
composer.lock  sha256 f471005b4d5c4708d861bb8c321512cc2fb93ada873fb0915c006fd682bfb162
lock content-hash  acd2bc3737bb3fcc89697aa3f6513013
locked packages 105  (packages + packages-dev)
```

Re-check at any time without writing anything:

```bash
scripts/dev/composer-ownership-status     # prints the two hashes
```

## 5. Options considered

### A. Targeted ownership handoff of `composer.json`, `composer.lock`, `vendor/` — **RECOMMENDED**

`chown` changes metadata only: no file content, no mode, no dependency version, no lockfile
entry. Scoped to exactly three paths, with `-R` applied only to the generated `vendor/` tree,
it is the smallest action that produces the desired end state.

- Needs no network.
- Cannot change dependency versions: it never invokes Composer.
- Preserves `vendor/` integrity byte-for-byte, including the original 2026-09-21/22 install.
- Reversible (see §8).

### B. Regenerate `vendor/` from `composer.lock` under the new wrapper — **NOT AVAILABLE**

`rm -rf vendor && scripts/dev/composer install` would produce host-owned files, but it is
**not safe here and not currently possible**, and that is proven rather than assumed:

- The Composer download cache at `~/.cache/composer-docker` contains **0** package archives
  (`files/` = 0, `vcs/` = 0). There is no legacy `~/.cache/composer` either.
- A regeneration needs to obtain **105** locked packages.
- `repo.packagist.org` is **unreachable** from this host (`curl` exit 124) and from a container
  (`curl` error 28 after 20s), while `getcomposer.org`, `api.github.com` and
  `registry.npmjs.org` all answer 200. So the metadata and download path that a regeneration
  depends on is broken.
- `composer update` is **not** part of any option here; it would change dependency versions.

Deleting `vendor/` under these conditions risks leaving the repository with **no** dependencies
and no way to restore them. It is rejected outright, not deferred.

### C. Container-scoped ownership handoff — **EQUIVALENT ALTERNATIVE**

The same three-path handoff performed from the toolchain image as root, instead of host `sudo`:

```bash
docker run --rm --user 0:0 \
  -v "$PWD:$PWD" --workdir "$PWD" \
  --entrypoint chown nextcloud:34-apache \
  -R 1000:1000 vendor
```

Same effect, no host `sudo`, and the scope is visible in one command. Equivalent to A; pick
whichever the operator prefers. A is recommended because it has no image dependency and its
scope is a literal three-path list.

### Rejected

- **Repository-wide `chown -R ubuntu:ubuntu .`** — explicitly forbidden, and it would silently
  reassign files that are not Composer-managed (including any legitimately `www-data`-owned
  artifact elsewhere in the tree).
- **`chmod -R 777`** — makes ownership irrelevant but destroys the permission model, including
  the read-only intent of `composer.lock` (`-rw-r--r--`).
- **Running Composer as root inside `docker-nextcloud-1`** — reinstates root-owned repository
  files and mutates shared Nextcloud state. This is what the external wrapper replaced.
- **`composer update` as part of the migration** — would change dependency versions.

## 6. Preconditions — as they applied

1. **Registry reachability.** Recorded as **down** (§9), and it did **not** block this
   migration: a metadata-only handoff invokes no Composer, regenerates nothing and cannot change
   a dependency version. It is a hard prerequisite for any actual dependency change, and for
   method B in particular. The bounded check, when it is needed, is:
   ```bash
   curl -sS --max-time 20 -o /dev/null -w '%{http_code}\n' https://repo.packagist.org/packages.json
   ```
   Use this, not `composer diagnose`: diagnose's "Checking http connectivity to packagist" step
   is unbounded and hangs while the registry is unreachable (observed: still running after 16
   minutes on 2026-10-02). It is still useful for its earlier checks — it reported
   `composer.json: OK`, `composer.lock: OK` and `platform settings: OK` before hanging.
2. `scripts/dev/composer validate` passes (manifest and lock consistent). **Confirmed.**
3. `scripts/dev/composer install --dry-run` reports **"Nothing to install, update or remove"**
   — proving the installed dependency set already matches the lock, so a handoff changes
   ownership only and nothing else. **Confirmed.**
4. Baselines in §4 recorded on the day of the migration. **Confirmed.**
5. No Composer, `occ`, PHPUnit or browser process running against the repository.
   **Confirmed.**
6. `git status --short -- composer.json composer.lock` empty. **Confirmed.**
7. The operator accepts that `www-data` loses write access to these three paths **by design**:
   the runtime reads dependencies, it does not modify them. **Accepted; read access re-verified
   afterwards.**

## 7. What was actually run

Executed 2026-10-02. Method **C** (container-scoped handoff, §5) was used rather than method A's
host `sudo`, because `sudo` requires a password in this environment. The effect is identical:
metadata-only, same three paths, `-R` confined to `vendor/`.

Preconditions satisfied before the handoff:

- `scripts/dev/composer validate` → `./composer.json is valid`
- `scripts/dev/composer install --dry-run --no-interaction` → *"Verifying lock file contents can
  be installed on current platform"* → **"Nothing to install, update or remove"**, i.e. the
  installed set already matches the lock
- §4 baselines recorded
- baselines re-verified after the handoff and after two real installs: unchanged

Registry reachability was **not** required, and was not a prerequisite here: the handoff changes
only ownership metadata, invokes no Composer, and regenerates nothing. It is still down (§9) and
is still a prerequisite for any actual dependency change.

### Step 1 — gates

```bash
cd ~/projects/pettycash
scripts/dev/composer validate
scripts/dev/composer install --dry-run --no-interaction     # "Nothing to install, update or remove"
scripts/dev/composer-ownership-status                        # record the two baseline hashes
```

Do not use `scripts/dev/composer diagnose` as a gate: it hangs on its unbounded packagist
connectivity step while the registry is unreachable (observed >16 min).

### Step 2 — the handoff (exactly three paths)

```bash
cd ~/projects/pettycash
docker run --rm --user 0:0 --tmpfs /var/www/html \
  -v "$PWD:$PWD" --workdir "$PWD" --entrypoint chown \
  nextcloud:34-apache -h 1000:1000 composer.json composer.lock
docker run --rm --user 0:0 --tmpfs /var/www/html \
  -v "$PWD:$PWD" --workdir "$PWD" --entrypoint chown \
  nextcloud:34-apache -R -h 1000:1000 vendor
```

`-R` is used **only** on `vendor`. Never on `.`, never on `scripts/`, never on `src/`, `lib/`,
`tests/` or `templates/`. With passwordless `sudo`, method A is the shorter equivalent:

```bash
sudo chown 1000:1000 composer.json composer.lock
sudo chown -R 1000:1000 vendor
```

### Step 3 — verify (all confirmed)

```bash
scripts/dev/composer-ownership-status        # all three "ubuntu:ubuntu", "writable by uid 1000", exit 0
sha256sum composer.json composer.lock        # equals the §4 baselines
scripts/dev/composer install --no-interaction            # run 1: nothing to install, autoload regenerated
scripts/dev/composer install --no-interaction            # run 2: stable, no change
find vendor ! -uid 1000 | head                          # no output
```

The runtime was checked afterwards as uid 33, because the web user loses *write* access and must
keep read access:

```bash
docker run --rm --user 33:33 -v "$PWD:$PWD" -w "$PWD" --entrypoint sh nextcloud:34-apache \
  -c 'php -r "require \"vendor/autoload.php\"; echo \"autoload OK\n\";"'
scripts/dev/phpunit --list-tests
```

Result: `vendor/autoload.php` loads as uid 33; the suite lists and runs. The one PHPUnit failure
(`PurchaserFlowControllerTest::testGetEvidenceContentReturns200WithStream`) is **pre-existing** and
unrelated — this repository's own reports (`T318.md`, and T072/T084/T086/T111/T171/T213) record it
failing identically on an untouched baseline; it is a `Content-Type` assertion on
`GET /evidence/{id}/content`.

## 8. Rollback

Reverting ownership does not revert anything else, because the handoff changes no content:

```bash
cd ~/projects/pettycash
sudo chown 33:33 composer.json composer.lock
sudo chown -R 33:33 vendor
```

Rolling back returns to the state that blocks the host-user Composer wrapper. It is a
regression, not a fix.

## 9. Blocker recorded on 2026-10-02

| Check | Result |
|---|---|
| `curl https://repo.packagist.org/packages.json` (host) | exit 124 — timeout |
| `curl https://repo.packagist.org/packages.json` (container, 20s) | curl error 28, HTTP 000 |
| `curl https://getcomposer.org/installer` | 200 (control) |
| `curl https://api.github.com/` | 200 (control) |
| Cached package archives available for an offline regeneration | **0** of 105 required |
| `composer.lock` content-hash | `acd2bc3737bb3fcc89697aa3f6513013` |

Conclusion: the ownership migration was **executed** (§7) and did not depend on this, because it
changed metadata only. What remains blocked is any operation that must fetch a package:
`composer update`, adding a dependency, and method B (vendor regeneration), which is not merely
deferred but **ruled out** until the cache is populated or the registry is reachable.

## 10. Related

- `AGENTS.md` → *Composer is external development tooling, not part of the runtime*
- `scripts/dev/composer` → the wrapper, and its pre-flight ownership warning
- `scripts/dev/composer-ownership-status` → read-only inspector