# filefix

**Find it. Fix it. Never `rm -rf` a client's fileadmin by accident.**

A TYPO3 backend extension that keeps `fileadmin` honest: it catches files whose content doesn't match their extension, finds files nobody references anymore, finds identical copies of the same file, shrinks images that are far bigger than any page needs, and shows how much space all of that costs on the dashboard — instead of a one-way delete button and a prayer.

---

## Table of contents

- [Introduction](#why-this-exists)
- [The tools](#the-tools)
  - [1. MIME Fix — inside the native Filelist](#1-mime-fix--inside-the-native-filelist)
  - [2. File Cleanup — fast, direct deletion](#2-file-cleanup--fast-direct-deletion)
  - [3. Duplicate report — identical files, with a deep usage check](#3-duplicate-report--identical-files-with-a-deep-usage-check)
  - [4. Oversized images — find and resize in place](#4-oversized-images--find-and-resize-in-place)
  - [5. Dashboard widgets — unused files at a glance](#5-dashboard-widgets--unused-files-at-a-glance)
- [Requirements](#requirements)
- [Installation](#installation)
- [Extension configuration](#extension-configuration)
- [CLI reference](#cli-reference)
- [Database](#database)
- [Safety design](#safety-design)
- [Backend modules & permissions](#backend-modules--permissions)
- [Localization](#localization)
- [Architecture](#architecture)
- [Changelog](#changelog)

---

## Introduction

Real-world `fileadmin` folders rot in a few specific ways:

1. **Content/extension mismatches.** Someone renames a `.png` to `.jpg`, or an old CMS re-exported a Photoshop file as `.jpg` without converting it. The browser silently mis-renders it, or breaks entirely, and nobody notices until a client complains.
2. **Unused files pile up.** Thousands of uploads that no page, no content element, and no FlexForm references anymore. They just sit there, unindexed clutter, until someone panics and deletes the whole folder — sometimes deleting things that *were* still in use.
3. **The same file, uploaded again and again.** Identical content under different names and folders, each copy taking its own disk space and its own metadata.
4. **Camera-original images.** 6000 px wide, 8 MB JPEGs uploaded straight from the camera, for a slot that never renders wider than 1900 px.

`filefix` addresses all of these, with purpose-built tools living where editors already work.

---

## The tools

### 1. MIME Fix — inside the native Filelist

No separate module to learn. A **"Scan MIME"** button gets injected directly into TYPO3's own *File > Filelist* toolbar for the folder you're viewing.

Click it and you get a report of every file in that folder where:

- the **actual file content** doesn't match what the **extension** claims it is (`finfo` vs. extension), or
- the **database's `mime_type`** is out of sync with what it should be.

Supported types: `jpg`, `jpeg`, `png`, `gif`, `webp`, `bmp`, `tiff`/`tif`, plus the text-based `css`, `js`, `yaml`/`yml`, and `dotx`.

**How fixing works, depending on the case:**

| Situation | What happens |
|---|---|
| Image content genuinely doesn't match extension (e.g. a PNG saved as `.jpg`) | Converted in place via ImageMagick / GraphicsMagick, written to a temp file first so a failed conversion never corrupts the original |
| Photoshop file (`.psd` content, wrong extension) | Converted via PHP's `Imagick` extension if available (GraphicsMagick has no PSD decoder); falls back to ImageMagick `convert` directly, reading only the merged/composite layer so you don't end up with `file-0`, `file-1`, `file-2`... |
| `css` / `js` / `yaml` / `yml` / `dotx` | `finfo` can never correctly detect these as anything but a generic MIME type — so instead of a bogus "conversion," the extension just corrects the `sys_file.mime_type` DB record directly |

Select the issues you want to fix, hit **Fix selected**, done. The FAL index gets re-read afterward so TYPO3 immediately reflects the corrected type.

### 2. File Cleanup — fast, direct deletion

*File > File Cleanup.* Straightforward, for when you already trust the result and just want it gone.

- Lists files with **zero active `sys_file_reference`** and **zero soft references** (`sys_refindex`) — i.e. truly unreferenced by any page, content element, or FlexForm.
- Filter by **Present on disk / Physically missing / All**, by **extension**, and by how many pages deep to look (**Level 1** = this folder only, up through **Level 3**, or unlimited).
- **Download as ZIP** before you delete anything, per-file download links, and per-row "physically missing" warning badges so you're never guessing.
- **Delete selected** or **Delete all filtered** — the bulk delete requires typing the exact file count to confirm before it'll run. No accidental one-click wipes.
- Uses the browser's own file-storage tree on the left (same one *Filelist* uses) to navigate — no separate folder picker to keep in sync.

This tool deletes physical files **and** their `sys_file`/`sys_file_reference` rows immediately. There's no undo — double-check your filters before running a bulk delete.

### 3. Duplicate report — identical files, with a deep usage check

*File > Filelist* → **Find duplicates** button in the toolbar (admins only). Lists every group of files in the storage with identical content (same `sys_file.sha1`) where at least one copy lies in the current folder or below; the other copies are looked up in the whole storage.

- **Only real copies count.** Each copy is checked on disk, sizes are read from disk — `sys_file.missing` and `sys_file.size` are not trusted. Records whose file is gone are left out and counted in the summary. Several `sys_file` records pointing to the same path count as one copy (their usages are added up).
- **Sorted by wasted space** (`(copies − 1) × size`), 25 groups per page, with lazy-loaded thumbnails.
- **Per copy:** path, file-level metadata (title, alternative, description), number of file references and soft references (e.g. `t3://file` links in RTE fields). Click the usage count to see each reference: table, field, record, page, language, hidden state, and whether the reference has its own title/alternative — with edit links that open in the module and return to the report.
- **Suggested keeper** (star icon): the copy with the most usages, then the oldest one.
- **Badges** when copies carry *different* file-level metadata (merging would need it copied into the references) or when soft references exist.
- **CSV export** of the folder's groups: one row per file reference (or per copy without references), incl. keeper flag, metadata and reference details — for planning a merge outside TYPO3.

**Deep check & delete.** Each copy has a *Deep check* that searches the whole database live for usages of that one file:

1. `sys_file_reference` (every workspace and language),
2. the reference index `sys_refindex` (relation fields, links in RTE/link fields, form definitions),
3. a text search in every string/text/JSON column of every table for the public path (also URL-encoded), the combined identifier (`1:/path/file.jpg`), `t3://file?uid=N` and the legacy `file:N` syntax — exact-matched, so `file:12` never matches `file:123` and `foo.jpg` never matches `foo.jpg.bak`,
4. other `sys_file` records indexing the same physical file.

Technical tables (caches, logs, history, `sys_file*`, `sys_refindex`, sessions, `tx_filefix_*`) are skipped and listed. Usages by deleted records are shown but don't block deletion. Text matches stop at 50.

Only when the check finds **zero active usages** does a **Delete file** button appear. After a second confirmation the server runs the deep check **again** and refuses (HTTP 409) if the file is in use — the browser result is never trusted. Deletion goes through FAL (physical file, `sys_file`, metadata, processed files) and is written to `tx_filefix_log` as `delete_duplicate`.

The report itself is read-only; nothing is merged or re-linked automatically.

### 4. Oversized images — find and resize in place

*File > Filelist* → **Oversized images** button in the toolbar (admins only). Lists images in the current folder (subfolders optional) whose width or height exceeds the configured maximum, with the estimated saving if resized to fit.

- **Max width/height from [extension configuration](#extension-configuration)** (default 1900 × 1900 px). Checked types: `jpg,jpeg,jfif,png,webp,tif,tiff,bmp` (raster only; `gif` left out because it may be animated). Max width/height and *Include subfolders* can be changed in the report for that view only.
- Dimensions come from `sys_file_metadata`; files are checked on disk and sizes read from disk. Images missing on disk or without known dimensions are left out and counted in the summary.
- **Estimated saving** = `size × (new pixels / old pixels)` — shown as an estimate, real results depend on format and compression. Sorted by largest saving, 25 per page, with reference count and lazy-loaded thumbnail.

**Resize** (per image):

1. **Dry run** — the image is really converted on the server, the result is measured and discarded. A modal shows before/after dimensions and file size.
2. **Resize now** — the server checks the file again and replaces it via FAL `replaceFile()`.

What a resize does and doesn't do:

- **Same file name, same `sys_file` uid, same format** — references, crops (stored relative) and links keep working. `size`, `sha1` and dimensions are updated; processed files are regenerated because the sha1 changed.
- Resizes to the limits from the **extension configuration** (not the values typed into the report form), aspect ratio kept, via TYPO3's configured ImageMagick/GraphicsMagick. JPEG quality follows core `$GLOBALS['TYPO3_CONF_VARS']['GFX']['jpg_quality']`. The embedded colour profile is always kept, regardless of `GFX/processor_stripColorProfileByDefault` — the original is overwritten, so a stripped profile could not be restored.
- The result is only kept when it is at least **10 KB** smaller (a resized PNG can even grow); otherwise the original stays untouched.
- Refused for: types other than `jpg`, `jpeg`, `png` (`jfif`, `webp`, `tif`, `tiff`, `bmp` are report-only), files indexed by more than one `sys_file` record, non-Local storage drivers, missing or non-writable files, images already within the limits (checked on the real file, not metadata).

> **Warning:** resizing overwrites the original file. There is **no backup and no entry in `tx_filefix_log`**. Back up `fileadmin` first if you may need the originals.

### 5. Dashboard widgets — unused files at a glance

Requires EXT:dashboard (optional). Widget group **filefix** with four widgets:

| Widget | Shows |
|---|---|
| Unused files | Number of unused files on disk |
| Reclaimable space | Disk space deleting them would free (e.g. `6.4 GB`) |
| Unused files by type | Doughnut chart by extension (biggest types get a slice, the rest is "other") |
| Unused space by type | Same chart, by size; tooltip e.g. `jpg (4.5 GB)` |

Counting is honest: each physical file is counted once, only when **all** its `sys_file` records are unused, only when it exists on disk, with its size read from disk. Files still used through another record pointing to the same path, and records whose file is missing, are left out (`filefix:stats:update` prints both numbers).

The underlying query is too expensive for every dashboard load, so the result is cached in `sys_registry` (namespace `tx_filefix`, key `stats.storage.<uid>`) and rebuilt when older than **1 hour**, or on demand via the schedulable [`filefix:stats:update`](#filefixstatsupdate) command. Widgets use the default storage.

---

## Requirements

- TYPO3 `^12.4 || ^13.4 || ^14.0` (core, backend, extbase, fluid)
- PHP with the `fileinfo` extension (used for content-vs-extension detection)
- ImageMagick or GraphicsMagick configured in TYPO3's `$GLOBALS['TYPO3_CONF_VARS']['GFX']` (standard TYPO3 image processing setup) for MIME fix conversion, image resizing and report thumbnails
- PHP `imagick` extension — **optional**, only needed for fixing misnamed Photoshop (`.psd`) files, since GraphicsMagick has no PSD decoder
- `typo3/cms-dashboard` — **optional**, only needed for the dashboard widgets (they are not registered without it)

## Installation

Already wired up as a local Composer path package:

```json
"repositories": [{ "type": "path", "url": "packages/*" }],
"require": {
    "anubit/filefix": "@dev"
}
```

```bash
composer update anubit/filefix
vendor/bin/typo3 extension:setup -e filefix
vendor/bin/typo3 database:updateschema
vendor/bin/typo3 cache:flush
```

## Extension configuration

*Admin Tools > Settings > Extension Configuration > filefix*, tab **images**. Used by the oversized image report and the resize. Invalid or empty values fall back to the defaults (also before the settings were ever saved).

| Setting | Description | Default |
|---|---|---|
| `imageMaxWidth` | Max width in px (100–10000) | `1900` |
| `imageMaxHeight` | Max height in px (100–10000) | `1900` |

Everything else is fixed on purpose: checked and resizable types (see [Oversized images](#4-oversized-images--find-and-resize-in-place)), JPEG quality (from core `GFX/jpg_quality`), colour profile (always kept), minimum saving (10 KB).

## CLI reference

The cleanup commands are meant for scheduled/unattended runs (cron, TYPO3 Scheduler) once you trust the tool for a given site.

### `filefix:cleanup:scan`

Populates the quarantine queue — same engine as Quick Scan in the backend module.

| Option | Description | Default |
|---|---|---|
| `--storage` | Storage UID to scan | `1` |
| `--folder` | Restrict to a FAL folder identifier (e.g. `/images/`) | *(all)* |
| `--older-than` | Duration string: `90d`, `2w`, `24h` | `90d` |
| `--limit` | Max candidates per scan type | `5000` |
| `--include-unused` | Scan for unreferenced FAL files | off |
| `--include-missing` | Scan for FAL records whose file is gone | off |
| `--include-physical-orphans` | Scan for un-indexed physical files | off |

```bash
vendor/bin/typo3 filefix:cleanup:scan --folder=/2021/ --older-than=180d --include-unused
```

### `filefix:cleanup:flush`

Processes what's already in the queue.

| Option | Description | Default |
|---|---|---|
| `--storage` | Storage UID | `1` |
| `--status` | Which status to process: `candidate` or `quarantined` | `candidate` |
| `--older-than` | Only records queued longer than this (e.g. `14d`) | *(none)* |
| `--limit` | Max records per run | `1000` |
| `--move-to-quarantine` | `candidate → quarantined` | — |
| `--delete` | `quarantined → flushed` (permanent) | — |
| `--scan-id` | Restrict to one scan's results | *(none)* |
| `--force` | Allow `candidate → flushed` directly, skipping quarantine | off |
| `--dry-run` | Simulate, no changes made | off |

```bash
# The intended safe pipeline for a cron job:
vendor/bin/typo3 filefix:cleanup:flush --status=candidate   --move-to-quarantine
vendor/bin/typo3 filefix:cleanup:flush --status=quarantined --delete --older-than=14d
```

`--delete` on `candidate` status is blocked unless you pass `--force` — the lifecycle is meant to go through quarantine first.

### `filefix:stats:update`

Rebuilds the unused-file statistics for the [dashboard widgets](#5-dashboard-widgets--unused-files-at-a-glance). Schedulable — add it as a Scheduler task (e.g. nightly) on large installations so no dashboard load has to rebuild the snapshot.

| Option | Description | Default |
|---|---|---|
| `--storage` | Storage UID | default storage |

```bash
vendor/bin/typo3 filefix:stats:update
```

## Safety design

- **Path traversal guards** on every file operation — physical paths are resolved with `realpath()` and checked against the real storage base before any rename/unlink/read.
- **Type-to-confirm** on "Delete all filtered" in File Cleanup — must type the exact file count before it'll submit; "Delete selected" uses a standard confirmation modal.
- **ZIP backup download** before deleting, in File Cleanup.
- **Re-validation before flush** — even queued/scheduled `flush` runs re-check the file still exists and is still safe to touch immediately before acting, not just at scan time.
- **Server-side deep check before every duplicate delete** — the delete endpoint re-runs the full usage search and refuses when anything is found; admin only, POST only.
- **Resize safeguards** — dry run first, checks repeated on the real file at resize time, result discarded unless it saves at least 10 KB. Note: no backup of the original (see [Oversized images](#4-oversized-images--find-and-resize-in-place)).
- **Disk over database** — duplicate report, oversized report and widgets check every file on disk and read sizes from disk instead of trusting `sys_file.missing` / `sys_file.size`.
- **XSS-safe rendering** — record titles and other user data from AJAX results are inserted with `textContent` only.
- **Self-healing scans** — if reality no longer matches a stale DB flag (a "missing" file turns out to be present again), the tool corrects the flag and drops the stale candidate instead of asking you to clean it up.

Namespace: `Anubit\Filefix\`. Extension key: `filefix`.

## Changelog

### 1.2.0

- **New:** Duplicate report with deep usage check, safe single-file delete and CSV export (admin only).
- **New:** Oversized image report with saving estimate and in-place resize (dry run first).
- **New:** Dashboard widgets: unused files, reclaimable space, unused files/space by type (optional EXT:dashboard).
- **New:** `filefix:stats:update` command (schedulable).
- **New:** Extension configuration for the image features (max width/height).
- **New:** German translation; remaining hard-coded labels moved to XLIFF.

### 1.1.1

- Compatibility across TYPO3 v12–v14, loading overlays, unused file counts by extension, query optimizations.
