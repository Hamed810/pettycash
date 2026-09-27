# T110 reopened — the archive was being built in the host's shared temp directory

**Task**: T110 (reopened; storage half reworked, one item open)
**Feature**: PettyCash V1 Core Workflow (`specs/001-pettycash-v1-core`)
**Date**: 2026-09-27
**Pass**: Autopilot implementation pass 1 of 50 (run resumed from `b45e93a`)

## How this was found

The previous pass closed T110 and the next run's convergence assessed the export slice against
`contracts/exports.md` and found the task had been marked complete on a claim that did not hold:

- `contracts/exports.md:10` — "Generated artifacts use private transient application storage and are
  removed after streaming."
- `plan.md:39` — "evidence and transient export artifacts through Nextcloud `IAppData`."
- the implementation — `tempnam(sys_get_temp_dir(), 'pettycash-evidence-')`

`sys_get_temp_dir()` is the host's **shared** temp directory. In this container it is shared with
Nextcloud itself, with PTS, and with every other process on the host. A grouped evidence archive
contains receipts, invoices, and the sheet titles and uploader names attached to them — precisely the
material FR-060's secrecy requirement and ADR-001 exist to protect. "The contract says private storage
and I used the temp directory" is a gap, not a style preference.

There was a second, quieter half. The finished archive was read back with
`file_get_contents($archivePath)` and returned as a `string`. FR-074 permits 100 MB **per file**,
`contracts/exports.md` sets no group ceiling, and nothing bounded the total — so a group export's size
was limited by PHP's `memory_limit` rather than by disk.

## What changed

| | |
|---|---|
| `lib/Application/Port/TransientExportStore.php` | New port: scratch path, streamed `persist()`, streaming `read()`, `deleteAll()`. |
| `lib/Infrastructure/Nextcloud/AppDataExportStore.php` | `IAppData` adapter, `exports` folder, streamed in via `ISimpleFile::write()`, `deleteAll()` for the contract's "removed after streaming". |
| `EvidenceArchiveExporter` | Builds at a scratch path, then **persists into app storage**, then removes the scratch in a `finally`. |
| `EvidenceArchiveResult` | Carries the artifact **name** and its size, not the bytes. |
| `lib/AppInfo/Application.php` | `TransientExportStore` container-registered against `IAppData`. |

**The scratch/artifact split is the point.** `ZipArchive` has no stream API, so the archive *must* be
assembled at a filesystem path. Splitting "where it is built" from "where it lives" is what lets the
build use a path without the contract being quietly violated: the file that persists is never the file
the archive was assembled in, and the scratch copy is removed whether or not the build succeeded.

`persist()` uses `stream_copy_to_stream()` into `ISimpleFile::write()`, so a large archive is bounded by
disk. `ISimpleFile::read()` returns a resource, so the streaming layer that reads it back never needs
the whole archive in memory.

## Why the task stays open

Every one of the 41 tests runs against `InMemoryTransientExportStore`. **No test has executed
`AppDataExportStore` against a real `IAppData`.**

That is the exact gap T236 was created for: thirteen in-memory doubles meant no test ever ran a real
persistence statement, and when T236 finally did, it found **nine broken repositories**, three of which
could not write a single row. The adapter's `getFolder`/`newFolder`/`newFile` usage, its `safeName()`
behaviour, and especially the streamed `persist()` are all unverified — and a streaming write is
precisely the kind of path that only fails against a real filesystem.

Reopening T110 with that stated is more honest than checking it again. The fix belongs in
`roundtrip-runner.php`'s subprocess treatment, not in a thirteenth double.

## Verification

`scripts/dev/phpunit` — **456 tests / 2008 assertions / 1 failure**, the failure being the pre-existing
T275 artifact conflict. Before this pass: 449. No regressions.

`tests/Unit/Export/EvidenceArchiveExporterTest.php` — 41 tests, 131 assertions.

| Mutation | Result |
|---|---|
| Remove `persist()` — the artifact never reaches app storage | fails **9** |
| Skip the scratch cleanup | fails **2** |
| Put the archive bytes back on the result | fails **36** (constructor mismatch) |

A fourth mutation — writing a side copy of the archive to an unrelated path — produced only a warning
and was **discarded as invalid** rather than reported as a pass: it does not change any behaviour the
contract describes, so it is not a mutation of the code under test.

## Also worth recording

The previous pass's own docblock claimed *"Removed whether or not the build succeeded. The archive is
transient by contract"* while the code removed a file from the shared temp directory and never touched
app storage at all. The comment described the requirement; the code did not implement it. That gap is
the reason convergence re-checks completed work against the artifacts rather than against the report that
claims it is finished.
