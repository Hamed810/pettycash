# T110 verified against a real `IAppData` — the task that had been reopened

**Task**: T110 (reopened → complete)
**Feature**: PettyCash V1 Core Workflow (`specs/001-pettycash-v1-core`)
**Date**: 2026-09-27
**Pass**: Autopilot implementation pass 1 of 50 (continuing the pass that reopened T110)

## What this closes

The previous pass reworked the store onto `IAppData` and then recorded, honestly, that **every** test for it
still ran against `InMemoryTransientExportStore`. That is the exact gap T236 was created for: thirteen
in-memory doubles meant no test ever executed a real persistence statement, and when T236 finally did, it
found **nine broken repositories**, three of which could not write a single row. A fourteenth double would
have hidden whatever is wrong in an adapter that has never touched a real filesystem.

This pass adds the real-filesystem verification and reconciles T110.

## The harness

An `appdata` scenario in the existing `tests/Integration/Persistence/roundtrip-runner.php`, which
bootstraps the Nextcloud server in a subprocess — the established pattern, because `tests/bootstrap.php`
deliberately does not bootstrap Nextcloud and loading `lib/base.php` into the PHPUnit process would
replace the global server.

The app-scoped `IAppData` is reached the same way the AppFramework reaches it:

```php
\OCP\Server::get(\OCP\Files\AppData\IAppDataFactory::class)->get('pettycash')
```

which is what `lib/private/AppFramework/DependencyInjection/DIContainer.php:121-122` does
(`$c->get(IAppDataFactory::class)->get($c->get('appName'))`). So the test exercises the same object the
running application uses, not a lookalike.

Assertions live in `tests/Integration/Persistence/AppDataExportStoreRoundTripTest.php`; the runner reports
observations. That split is the point — a bug in the runner cannot agree with itself into a green test.

## What is now proven against a real filesystem

| Claim | Evidence |
|---|---|
| `persist()` writes through real `IAppData` | the file appears in the `exports` folder listing, and a mutation that bypassed `IAppData` reported the real path `/appdata_oculd8krif6b/pettycash/exports/` |
| The artifact reads back | a 4 KB random payload plus a marker, read through `ISimpleFile::read()`, byte-identical with a matching SHA1 — compared as a digest as well as a length, so a truncated-then-padded artifact cannot pass |
| It is a real ZIP, and the manifest survives | the stored bytes are extracted and `manifest.csv` read out of them: all 15 columns, with the Persian uploader name intact, and the evidence member byte-identical |
| Replace semantics | persisting the same name twice leaves the second write |
| `delete()` | removes one artifact, leaves the others, quiet on an absent name |
| `deleteAll()` | genuinely empties the folder — checked **inside** the scenario, because the runner's own `finally` calls `deleteAll()`, so a test asserting emptiness afterwards would be asserting its own cleanup |
| Scratch cleanup on success | no new file in the shared temp directory after the build |
| Scratch cleanup on **failure** | a deliberately throwing reader leaves no scratch behind, and the error propagates |

## How the artifact/scratch distinction is proven

Not by comparing paths — `ISimpleFolder` deliberately does not expose an absolute one. **By ordering**:
the exporter deletes its scratch in a `finally`, and the artifact is still in app storage afterwards. If
the artifact were the scratch file, deleting the scratch would have taken it with it.

`scratchRemoved` and `artifactOutlivedScratch` are both asserted.

## Two probe bugs this found, which were not product defects

Both are worth recording because in each case the first version of the probe would have been read as a
product failure.

**"The Persian text was lost from the archive."** The probe scanned the *raw archive bytes* for the
string. A ZIP member is deflated, so plaintext is not there — the text was compressed, not lost. The
probe now extracts the archive and reads `manifest.csv` out of it. The first version proved nothing
either way, which is worse than failing.

**"A scratch file leaked."** The probe globbed for leftover scratch files without first removing its own
probe scratch, so it reported its own file as a leak. The scenario now unlinks its probe file and then
*diffs* the scratch set across the exporter's build, so only files that appeared during the build can be
counted as leaks.

## Mutations

| Mutation | Result |
|---|---|
| `persist()` bypasses `IAppData` and copies to the shared temp dir | fails loudly; the error names the real appdata path |
| `deleteAll()` is a no-op | fails **1** |
| The exporter's `finally` does not clean up | fails **2** |
| The `finally` only cleans up on the happy path | fails **2** |

## Verification

`scripts/dev/phpunit` — **474 tests / 2057 assertions / 1 failure**, the failure being the pre-existing
T275 artifact conflict. Before this pass: 456.

`AppDataExportStoreRoundTripTest.php` — 18 tests, 49 assertions.

## No litter, no environment change

After the run: the `exports` AppData folder is **empty**, the shared system temp directory has no
`pettycash-*` or `probe-*.zip` residue, and the pre-existing `evidence` folder is untouched. The runner
sweeps AppData in its `finally` with the same two-discipline the database sweep uses, because a generated
export is a real file on disk and would otherwise outlive the run.

Nothing outside PettyCash's own AppData was read or written. No Docker, credential, VM, or global-tooling
change, and no dependency was installed.

## T103 still open

This is the umbrella RED task for all four exporters. T107 (CSV), T108 (XLSX) and T110 (ZIP) are now
built and verified; **T109 (PDF) is the one remaining format**, and T103 becomes writable once it lands.
