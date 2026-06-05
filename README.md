# CertifyHub

A lightweight, open-access batch certificate generator. Upload a template image and a CSV, design text layers in a live canvas, preview every certificate, then export a ZIP of all generated files — no account required.

---

## Table of Contents

- [Overview](#overview)
- [Tech Stack](#tech-stack)
- [Project Structure](#project-structure)
- [User Workflow](#user-workflow)
- [Data Architecture](#data-architecture)
- [Local Development](#local-development)
- [Render Deployment](#render-deployment)
- [Queue Worker](#queue-worker)
- [CSV Format](#csv-format)
- [Font Support](#font-support)
- [Export Formats](#export-formats)
- [Key Design Decisions](#key-design-decisions)
- [License](#license)

---

## Overview

CertifyHub automates the creation of personalised certificates from a base image template and a structured CSV dataset. It handles three participant layouts out of the box:

| Layout | Description |
|---|---|
| **Standard** | One name per row |
| **Identified** | Name + IC / ID number |
| **Grouped / Team** | Multiple members sharing one group name — rendered as a vertical stack or comma-joined string |

All processing runs in a background queue. The browser polls for progress and presents a download link once the ZIP is ready.

---

## Tech Stack

| Layer | Technology |
|---|---|
| Backend framework | Laravel 12 (PHP 8.2) |
| Frontend framework | React 19 + TypeScript |
| SPA bridge | Inertia.js v3 |
| Build tool | Vite 7 + `@vitejs/plugin-react` v5 |
| CSS utility | Tailwind CSS v4 |
| Animations | GSAP 3 |
| Icons | Heroicons v2 |
| Image rendering | PHP GD (imagettftext / imagettfbbox) |
| Queue backend | Laravel database queue |
| Database | SQLite |
| Archive | PHP ZipArchive |

---

## Project Structure

```
CertifyHub/
├── app/
│   ├── Http/
│   │   └── Controllers/
│   │       ├── BatchController.php      # CSV ingestion, record CRUD, Studio save, Preview
│   │       └── ExportController.php     # Execute/progress/download endpoints + font upload
│   ├── Jobs/
│   │   └── GenerateCertificatesBatch.php  # Background GD rendering + ZIP creation
│   └── Models/
│       ├── Batch.php                    # UUID primary key, global_settings JSON cast
│       └── Record.php                  # team_members/override_settings JSON cast
│
├── database/
│   └── migrations/
│       ├── ..._create_batches_table.php
│       ├── ..._create_records_table.php
│       └── ..._add_team_members_to_records_table.php
│
├── resources/
│   └── js/
│       ├── Pages/
│       │   ├── Landing.tsx      # Step 1 — drop-zone for template + CSV
│       │   ├── Validate.tsx     # Step 2 — data review / inline editing grid
│       │   ├── Studio.tsx       # Step 3 — drag-and-drop layer designer
│       │   └── Preview.tsx      # Step 4 — thumbnail grid + export controls
│       └── Components/
│           ├── DropZone.tsx
│           ├── Studio/
│           │   └── LayerPanel.tsx   # Font, size, colour, alignment controls
│           └── Preview/
│               └── MicroEditor.tsx  # Per-certificate override modal
│
├── routes/
│   └── web.php
│
├── storage/
│   └── app/public/
│       ├── templates/     # Uploaded certificate background images
│       ├── fonts/         # Uploaded custom TTF/OTF/WOFF files
│       └── exports/       # Generated certificate images + batch ZIPs
│
├── public/
│   └── build/             # Vite production output (gitignored)
│
├── vite.config.js
├── package.json
└── composer.json
```

---

## User Workflow

### Step 1 — Upload (Landing)

The user drags or clicks to upload:
- **Template image** — `.png`, `.jpg`, or `.jpeg`
- **Data spreadsheet** — `.csv`

On submission, a new `Batch` record is created and the CSV is parsed and normalised into `Record` rows. Group rows (multiple members sharing the same group name) are automatically detected and their members stored as a JSON array in `team_members`.

### Step 2 — Review Data (Validate)

A data grid shows all parsed records. Users can:
- Edit individual name, IC number, or group fields inline
- Add new rows manually
- Remove incorrect rows
- Save changes before proceeding

Group records display their member list as green chip badges.

### Step 3 — Design Studio (Studio)

An interactive canvas overlaid on the template image. Visible layers depend on which data columns are present:

| Layer | Shown when |
|---|---|
| Participant Name | Always |
| IC Number | Any record has an IC value |
| Group / Team | Any record has a group identifier |

Each layer is independently draggable (X + Y) and configurable:
- **Font family** — system fonts or uploaded custom fonts
- **Font size** — px slider / numeric input
- **Colour** — colour picker
- **Alignment** — Left / Center / Right
- **Group format** — Vertical stack or Horizontal comma string (name layer only)

Saving captures the canvas render width so the backend can scale font sizes proportionally against the full-resolution template.

### Step 4 — Preview & Export (Preview)

A responsive grid shows a thumbnail of every certificate with overlaid text. Clicking any card opens **MicroEditor** — a per-certificate override modal where layer positions, sizes, and fonts can be adjusted for that record alone without affecting the rest of the batch.

Export flow:
1. A queue job is dispatched
2. Browser polls `/batch/{id}/progress` every second
3. Progress bar animates via GSAP
4. When `zip_ready` is confirmed server-side, a download button appears

---

## Data Architecture

### `batches`

| Column | Type | Notes |
|---|---|---|
| `id` | UUID | Primary key |
| `template_path` | string | Relative path under `storage/app/public/` |
| `export_format` | enum | `pdf`, `png`, `jpg` |
| `global_settings` | JSON | `{ layers: Layer[], canvasWidth: number }` |
| `created_at` | timestamp | Used in ZIP filename |

### `records`

| Column | Type | Notes |
|---|---|---|
| `id` | integer | Auto-increment |
| `batch_id` | UUID | Foreign key → batches |
| `recipient_name` | string\|null | Individual name |
| `identification_number` | string\|null | IC / ID field |
| `group_identifier` | string\|null | Group/team name |
| `team_members` | JSON\|null | `["Ahmad","Akmal","Abdullah"]` |
| `override_settings` | JSON\|null | Per-record layer overrides |
| `generation_status` | enum | `pending`, `processing`, `completed`, `failed` |

---

## Local Development

### Requirements

- PHP 8.2 with **GD extension** enabled
- Composer
- Node.js 20+
- XAMPP (Windows) or equivalent

### Setup

```bash
# 1. Clone the repository
git clone https://github.com/RuumiDev/CertifyHub.git
cd CertifyHub

# 2. Install PHP dependencies
composer install

# 3. Install JS dependencies
npm install

# 4. Copy environment file and generate app key
cp .env.example .env
php artisan key:generate

# 5. Run database migrations
php artisan migrate

# 6. Create storage symlink (exposes storage/app/public via public/storage)
php artisan storage:link
```

### Enable GD (Windows / XAMPP)

In `php.ini`, ensure this line is present and uncommented:

```ini
extension=gd
```

Restart Apache / the PHP process after the change.

### Running (3 terminals)

```bash
# Terminal 1 — Laravel dev server
php artisan serve

# Terminal 2 — Vite asset watcher
npm run dev

# Terminal 3 — Queue worker
php artisan queue:work --timeout=600
```

> **Important:** Always kill and restart the queue worker after any PHP code change. It loads code once at startup and serves stale bytecode until restarted.

### Production build

```bash
npm run build
```

---

## Render Deployment

### Platform constraints

- Render free web services use an ephemeral filesystem. Uploaded templates, uploaded fonts, and generated ZIP archives under `storage/app/public/` are fine for demos, but they are lost after instance restart or sleep.
- For persistent production storage, move `public` disk to S3, Cloudinary, MinIO, or another object store.
- SQLite is not suitable on Render free instances. Use PostgreSQL.

### Recommended Render setup

Fastest path is Blueprint deploy from [render.yaml](d:\Hirumi's D\Miki\Downloads\Code\CertifyHub\render.yaml). It creates:

- one Docker-based web service using Apache + PHP 8.2 + PostgreSQL extensions
- one managed PostgreSQL database

Render will build from [Dockerfile](d:\Hirumi's D\Miki\Downloads\Code\CertifyHub\Dockerfile), run migrations as `preDeployCommand`, and health-check `/up`.

If you deploy manually instead of Blueprint, use Docker runtime and point Render at this repository's `Dockerfile`.

### Required environment variables

| Key | Value |
|---|---|
| `APP_NAME` | `CertifyHub` |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | Your Render app URL, set manually during first Blueprint sync |
| `APP_KEY` | Generated production key |
| `DB_CONNECTION` | `pgsql` |
| `DB_URL` | Injected automatically from Blueprint database reference |
| `QUEUE_CONNECTION` | `database` |
| `QUEUE_FALLBACK_AFTER_RESPONSE` | `true` on free tier without dedicated worker |

### Free-tier queue fallback

CertifyHub now includes production fallback for Render free tier in [app/Providers/AppServiceProvider.php](d:\Hirumi's D\Miki\Downloads\Code\CertifyHub\app\Providers\AppServiceProvider.php). When all of these are true:

- app runs in `production`
- request is web request, not console command
- `QUEUE_FALLBACK_AFTER_RESPONSE=true`
- queue driver is `database`
- `jobs` table exists and has pending rows

app will execute one queued job after response is sent by running `php artisan queue:work --once` in-process. This is fallback for demo or low-volume deployments where free tier cannot run separate worker.

For normal production, keep dedicated queue worker and leave `QUEUE_FALLBACK_AFTER_RESPONSE=false`.

---

## Queue Worker

The certificate generation job (`GenerateCertificatesBatch`) runs in the database-backed queue.

**Kill stale workers and start fresh (Windows PowerShell):**

```powershell
Get-CimInstance Win32_Process -Filter "name='php.exe'" |
  Where-Object { $_.CommandLine -like '*queue:work*' } |
  Stop-Process -Force

php artisan queue:work --timeout=600
```

---

## CSV Format

The parser normalises all column headers to lowercase. Recognised column names:

| Header (case-insensitive) | Maps to |
|---|---|
| `name`, `recipient`, `participant` | `recipient_name` |
| `ic`, `ic number`, `identification` | `identification_number` |
| `group`, `team`, `group name` | `group_identifier` |

**Individual example:**

```csv
name,ic
Ahmad Faiz,010203-04-1234
Siti Rahimah,900112-05-5678
```

**Group / team example:**

```csv
name,group
Ahmad,Innovara
Akmal,Innovara
Abdullah,Innovara
Farah,Nexara
```

Rows sharing the same group value are collapsed into a single `Record` with a JSON `team_members` array. Each group generates one certificate with all member names rendered together.

---

## Font Support

Custom fonts can be uploaded per layer inside the Design Studio (`.ttf`, `.otf`, `.woff`, `.woff2` — max 5 MB per file).

- The browser uses the **FontFace API** for instant canvas preview.
- The file is stored at `storage/app/public/fonts/` for backend GD rendering.
- The server path is saved in the layer's `fontPath` field within `global_settings`.
- **Font registry:** all layers sharing the same `fontFamily` name automatically inherit the uploaded TTF — uploading once covers every layer using that font.

System font fallback order (when no custom font is uploaded):

| OS | Fallback order |
|---|---|
| Windows | Arial → Verdana → Calibri |
| Linux | DejaVu Sans → Liberation Sans → FreeSans |
| macOS | Arial → Helvetica |

---

## Export Formats

| Format | Behaviour |
|---|---|
| PNG | Full quality, alpha transparency preserved |
| JPG | JPEG at quality 92, smaller file size |
| PDF | Rendered as PNG (PHP GD does not produce true PDF) |

Downloaded archive filename: `CertifyHub_Batch_DDMMYYYY.zip`

---

## Key Design Decisions

**Percentage-based coordinates**
Layer `x`/`y` are stored as a percentage of the canvas dimensions. The backend scales to full image resolution:
```
scaledFontSize = (studioFontSize / canvasWidth) × imageWidth
```

**No authentication required**
All batches are anonymous. The `batches` table can accept a `user_id` foreign key to support a freemium model in future (e.g., limit anonymous batches to 50 certificates).

**GD over headless browser**
Lightweight image rendering via PHP GD + FreeType. No Puppeteer, Chrome, or wkhtmltopdf binary required.

**SQLite**
Zero-config, single-file database. Sufficient for the stateless batch processing model.

**`zip_ready` guard**
The frontend download button appears only after polling confirms the ZIP exists on disk with non-zero size — eliminates partial-download race conditions.

**`imagettfbbox` false guard**
If FreeType cannot parse a font file, `imagettfbbox` returns `false`. The backend detects this, logs a warning, and falls back to `imagestring` (built-in pixel font) rather than throwing a fatal error.

---

## License

MIT
