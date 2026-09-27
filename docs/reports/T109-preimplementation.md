# T109 pre-implementation findings — what is established, and what remains

**Task**: T109 (`lib/Infrastructure/Export/PdfExporter.php`) — **not started, nothing built**
**Feature**: PettyCash V1 Core Workflow (`specs/001-pettycash-v1-core`)
**Date**: 2026-09-27
**Pass**: Autopilot implementation pass 2 of 50 (logical run)

No code was written. Every finding below was established mechanically, so the next pass does not repeat
the discovery. Read this before starting T109.

## 1. The brief's dependency claim is wrong — corrected

The handoff brief said "tecnickcom/tcpdf is already installed". **It is not.**

```
vendor/tecnickcom/  →  tc-lib-pdf, tc-lib-pdf-barcode/…/font/…/graph/…/image/…/page/…/parser/…/sign,
                        tc-lib-color, tc-lib-file, tc-lib-unicode, tc-lib-unicode-data
```

`composer.json` requires `tecnickcom/tc-lib-pdf: ^8.76`. TCPDF proper (`tecnickcom/tcpdf`) is absent.
The main class is `Com\Tecnick\Pdf\Tcpdf`; `Com\Tecnick\Pdf\TCPDF` does not exist. Anything written
against the familiar TCPDF API (`AddFont`, `SetFont`, `Output`) will not resolve.

## 2. Persian shaping IS available — this was the real risk, and it is fine

The load-bearing question is whether `tc-lib-pdf` can render Persian at all. A PDF stores explicit
glyphs, and a viewer does **not** apply Arabic contextual shaping, so a library without it renders
Persian as isolated, disconnected letters in left-to-right order. That would have made the contract's
acceptance check — "PDF renders Persian RTL text, money, dates, and mixed-direction identifiers
correctly" — unimplementable with this stack.

It is available:

- `Com\Tecnick\Unicode\Bidi` — UAX#9 bidi reordering, invoked from
  `tc-lib-pdf/src/Text.php::prepareText()`.
- `Com\Tecnick\Unicode\Bidi\Shaping\Arabic` plus `tc-lib-unicode-data/src/Arabic.php` — the
  contextual-shaping tables, reached from `Text::replaceUnicodeChars()` →
  `Substitution::replaceChars()`.
- `Tcpdf::setRTL(bool)` sets the base paragraph direction.

`prepareText()` does the reordering, then `replaceUnicodeChars()` does the shaping — in that order,
which is the correct sequence. So mixed-direction identifiers (`AWS-12` inside Persian) and the
date/number runs are handled by the library rather than by hand.

**Verified, not assumed:** the code path exists and is called. What is *not* yet verified is that a
produced PDF renders correctly to a human — that needs the actual output, and a test that extracts the
text stream to confirm the shaping tables fired.

## 3. The font is a container system file, not a project asset — the open decision

`contracts/exports.md` requires a "Persian RTL-capable embedded font". The only Persian-capable font
present is:

```
/usr/share/fonts/truetype/noto/NotoNaskhArabic-Regular.ttf   175,792 bytes
```

- **Licence: OFL-1.1** (`/usr/share/doc/fonts-noto-core/copyright`), so redistributable provided the
  licence travels with it.
- It is a **container system font**. Referencing that path from application code would tie the export
  to one machine's font packages and fail on a fresh install or in CI.
- No `.ttf` ships in `vendor/`, so there is no in-stack alternative.

**The decision the next pass must make, explicitly:** vendor the font into the repository (for example
`lib/Infrastructure/Export/fonts/NotoNaskhArabic-Regular.ttf` with its OFL text) so it becomes a
project asset and the path is stable, or accept the system path and document the dependency. Vendoring
is the only option that satisfies "embedded font" portably; it costs 176 KB in the repository and an
attribution file. **Neither the artifacts nor any open task records where an export font should live** —
`plan.md` places `l10n/` for "Persian source and generated translation artifacts" and says nothing about
an export font. This is a gap for convergence, not a licence to guess silently.

## 4. The font-registration API is not the familiar one

`Tcpdf` has **no public `setFont`/`AddFont`**. Reflection over the whole hierarchy
(`Tcpdf ← Output ← MetaInfo ← HTML ← JavaScript ← CSS ← SVG ← Text ← Cell ← Base`) finds only
`setHTMLMonospaceFont()` and `addXObjectFontID()` among font-named public methods. The registration
entry point is `Com\Tecnick\Pdf\Font\Buffer::addFont(int &$objnum, string $font, string $style,
string $ifile, ?bool $subset)`, reachable from `Base`'s font buffer. The next pass should introspect at
runtime rather than read further — reflection over the live class answered in one call what several
`grep` passes did not.

## 5. What the next pass should build, and what "done" means

- Vendor the font (decision 3) with OFL attribution.
- `PdfExporter` persisting through `TransientExportStore`, exactly as the evidence archive and XLSX
  exporter do — scratch assembly path, streamed persist, `finally` cleanup. That pattern is now proven
  three times over and must not be re-invented.
- Money, dates and mixed-direction identifiers, per the contract's acceptance check.
- **No custom letterhead or signature** — the contract forbids both; a test should assert their absence
  rather than trusting the absence of code.
- Tests must read the produced PDF back. Asserting on a valid PDF and extractable text is **not**
  sufficient for this task: the shaping claim is the one that matters, and it needs the text stream
  inspected for shaped presentation forms, or a rendered comparison.

## Why nothing was committed for T109

Three of these findings would have been re-derived by the next pass at significant cost — the wrong
dependency name, the shaping-capability question, and the font/API location. Discovering that a font
must be vendored is a decision with repository and licensing implications that deserves to be made
deliberately rather than as a side effect of building an exporter. The alternative was to build
against a container system font path and discover the portability problem in acceptance instead.

## Limitations carried forward

- `composer` is absent from the container PATH, so `php-cs-fixer` cannot run
  (`nextcloud/coding-standard` is in `vendor/` but absent from the generated autoloader). Psalm's
  758-error baseline is pre-existing. Both are tooling limitations, not product defects.
