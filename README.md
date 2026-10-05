# filefix

**Find it. Fix it. Never `rm -rf` a client's fileadmin by accident.**

A TYPO3 backend extension that keeps `fileadmin` honest: it catches files whose content doesn't match their extension, finds files nobody references anymore, and gives you a safe, reversible way to get rid of them — instead of a one-way delete button and a prayer.

---

## Table of contents

- [Why this exists](#why-this-exists)
- [The two tools](#the-two-tools)
  - [1. MIME Fix — inside the native Filelist](#1-mime-fix--inside-the-native-filelist)
  - [2. File Cleanup — fast, direct deletion](#2-file-cleanup--fast-direct-deletion)
- [Requirements](#requirements)
- [Installation](#installation)
- [CLI reference](#cli-reference)
- [Database](#database)
- [Safety design](#safety-design)
- [Backend modules & permissions](#backend-modules--permissions)
- [Architecture](#architecture)

---

## Why this exists

Real-world `fileadmin` folders rot in two specific ways:

1. **Content/extension mismatches.** Someone renames a `.png` to `.jpg`, or an old CMS re-exported a Photoshop file as `.jpg` without converting it. The browser silently mis-renders it, or breaks entirely, and nobody notices until a client complains.
2. **Unused files pile up.** Thousands of uploads that no page, no content element, and no FlexForm references anymore. They just sit there, unindexed clutter, until someone panics and deletes the whole folder — sometimes deleting things that *were* still in use.

`filefix` addresses both, with two purpose-built tools living where editors already work.

---

## The two tools

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

---

## Requirements

- TYPO3 `^12.4` (core, backend, extbase, fluid)
- PHP with the `fileinfo` extension (used for content-vs-extension detection)
- ImageMagick or GraphicsMagick configured in TYPO3's `$GLOBALS['TYPO3_CONF_VARS']['GFX']` (standard TYPO3 image processing setup) for actual file conversion
- PHP `imagick` extension — **optional**, only needed for fixing misnamed Photoshop (`.psd`) files, since GraphicsMagick has no PSD decoder

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

## CLI reference

Both commands are meant for scheduled/unattended runs (cron, TYPO3 Scheduler) once you trust the tool for a given site.

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

## Database

| Table | Purpose |
|---|---|
| `tx_filefix_quarantine` | The live queue: one row per candidate/quarantined/resolved file, with status, reason, scan ID, size, SHA1, and quarantine path |
| `tx_filefix_log` | Append-only action log (delete / quarantine / restore / skip / recheck), independent of queue state, for audit purposes |

## Safety design

- **Path traversal guards** on every file operation — physical paths are resolved with `realpath()` and checked against the real storage base before any rename/unlink/read.
- **Type-to-confirm** on "Delete all filtered" in File Cleanup — must type the exact file count before it'll submit; "Delete selected" uses a standard confirmation modal.
- **ZIP backup download** before deleting, in File Cleanup.
- **Re-validation before flush** — even queued/scheduled `flush` runs re-check the file still exists and is still safe to touch immediately before acting, not just at scan time.
- **Self-healing scans** — if reality no longer matches a stale DB flag (a "missing" file turns out to be present again), the tool corrects the flag and drops the stale candidate instead of asking you to clean it up.

## Backend modules & permissions

Registered under the *File* menu, `access: user` (needs to be granted per backend user group, same as any other module):

| Module | Route | Purpose |
|---|---|---|
| File Cleanup | `filefix_cleanup` | Direct find & delete |

The quarantine backend module is currently disabled (see `Configuration/Backend/Modules.php`); its underlying scan/flush engine still runs via the CLI commands below.

MIME Fix has no separate module — it's a toolbar button injected into TYPO3 core's own `media_management` (Filelist) route via `FilelistMimeFixMiddleware`, so it inherits whatever access your users already have to Filelist.

## Architecture

```
Classes/
├── Command/            CLI: scan + flush
├── Controller/          FileCleanupController, QuarantineController
├── Middleware/          FilelistMimeFixMiddleware (injects "Scan MIME" into core Filelist)
├── Repository/          QuarantineRepository, LogRepository
└── Service/
    ├── MimeTypeService              content/extension mismatch detection + fixing
    ├── FileCleanupService           unused-file queries + deletion
    ├── QuarantineScanService        unused / missing / orphan detection for the queue
    ├── QuarantineFlushService       move-to-quarantine / permanent delete
    ├── QuarantineRestoreService     move back out of quarantine
    ├── QuarantineValidationService  re-check-before-acting safety net
    └── ActionLogger                 writes to tx_filefix_log
```

Namespace: `Anubit\Filefix\`. Extension key: `filefix`.
