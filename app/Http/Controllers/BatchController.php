<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class BatchController extends Controller
{
    /**
     * Landing page — Phase 1.
     */
    public function index(): Response
    {
        return Inertia::render('Landing');
    }

    /**
     * Ingest template + CSV, return to Phase 2.
     */
    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'template' => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:20480'],
            'csv'      => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        // Store template
        $templatePath = $request->file('template')->store('templates', 'public');

        // Create batch
        $batch = Batch::create([
            'template_path' => $templatePath,
            'export_format' => 'pdf',
            'global_settings' => null,
        ]);

        // Stream-parse CSV
        $csvPath = $request->file('csv')->getRealPath();
        $handle = $csvPath ? fopen($csvPath, 'r') : false;

        if ($handle === false) {
            return back()->withErrors([
                'csv' => 'Could not open CSV file. Please upload a valid CSV.',
            ])->withInput();
        }

        $headers = null;
        $grouped = [];
        $parsedRows = 0;

        // Helper: case-insensitive column lookup against normalised lowercase key map
        $lookup = function (array $normalised, array ...$candidates): string {
            foreach ($candidates as $keys) {
                foreach ($keys as $key) {
                    $k = strtolower(trim($key));
                    if (isset($normalised[$k])) {
                        return trim($normalised[$k]);
                    }
                }
            } 
            return '';
        };

        while (($row = fgetcsv($handle)) !== false) {
            if ($headers === null) {
                $headers = array_map('trim', $row);
                continue;
            }

            // Skip malformed rows that do not match the header column count.
            if (count($row) !== count($headers)) {
                continue;
            }

            // Normalise to lowercase keys for case-insensitive column matching
            $raw  = array_combine($headers, $row);

            if ($raw === false) {
                continue;
            }

            $data = array_combine(
                array_map('strtolower', array_keys($raw)),
                array_values($raw),
            );

            $name  = $lookup($data, ['name', 'participant name', 'full name', 'recipient']);
            $ic    = $lookup($data, ['ic', 'ic number', 'identification_number', 'id number']);
            $group = $lookup($data, ['group', 'group name', 'group_identifier', 'team', 'team name']);

            if (empty($name)) {
                continue;
            }

            $parsedRows++;

            if (!empty($group)) {
                $grouped[$group][] = $name;
            } else {
                $batch->records()->create($this->normalizeRecordPayload([
                    'recipient_name'        => $name,
                    'identification_number' => $ic,
                    'group_identifier'      => null,
                    'team_members'          => null,
                ]) + [
                    'generation_status' => 'pending',
                ]);
            }
        }

        fclose($handle);

        if ($parsedRows === 0) {
            Storage::disk('public')->delete($templatePath);
            $batch->delete();

            return back()->withErrors([
                'csv' => 'No rows could be parsed. Check your CSV format.',
            ])->withInput();
        }

        // Create one record per group, storing members as a JSON array
        foreach ($grouped as $groupName => $members) {
            $batch->records()->create($this->normalizeRecordPayload([
                'recipient_name'        => null,
                'identification_number' => null,
                'group_identifier'      => $groupName,
                'team_members'          => $members,
            ]) + [
                'generation_status' => 'pending',
            ]);
        }

        return redirect()->route('batch.validate', $batch->id);
    }

    /**
     * Phase 2 — Data validation dashboard.
     */
    public function validate(Batch $batch): Response
    {
        return Inertia::render('Validate', [
            'batch'   => $batch,
            'records' => $batch->records()->orderBy('id')->get(),
        ]);
    }

    /**
     * Update records inline (Phase 2 saves).
     */
    public function updateRecords(Request $request, Batch $batch): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'records'                             => ['required', 'array'],
            'records.*.id'                        => ['sometimes', 'integer', 'exists:records,id'],
            'records.*.recipient_name'            => ['nullable'],
            'records.*.identification_number'     => ['nullable'],
            'records.*.group_identifier'          => ['nullable'],
            'records.*.team_members'              => ['nullable'],
        ]);

        foreach ($validated['records'] as $row) {
            $payload = $this->normalizeRecordPayload($row);

            // Skip rows that are still empty after normalization.
            if (
                $payload['recipient_name'] === null
                && $payload['group_identifier'] === null
                && empty($payload['team_members'])
            ) {
                continue;
            }

            if (isset($row['id'])) {
                $record = $batch->records()->find($row['id']);
                if ($record) {
                    $record->update($payload);
                }
            } else {
                $batch->records()->create($payload + [
                    'generation_status' => 'pending',
                ]);
            }
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Normalize request/frontend rows into a strict Record payload for PostgreSQL.
     */
    private function normalizeRecordPayload(array $row): array
    {
        $recipientName = $this->normalizeNullableString($row['recipient_name'] ?? null);
        $identificationNumber = $this->normalizeNullableString($row['identification_number'] ?? null);
        $groupIdentifier = $this->normalizeNullableString($row['group_identifier'] ?? null);
        $teamMembers = $this->normalizeTeamMembers($row['team_members'] ?? null);

        // Keep one canonical shape:
        // - Team record: group + team_members, recipient_name null.
        // - Individual record: recipient_name set, group/team_members null.
        if (!empty($teamMembers)) {
            $recipientName = null;
        } else {
            $teamMembers = null;
            $groupIdentifier = null;
        }

        return [
            'recipient_name'        => $recipientName,
            'identification_number' => $identificationNumber,
            'group_identifier'      => $groupIdentifier,
            'team_members'          => $teamMembers,
        ];
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = implode(', ', array_filter(array_map(function ($item) {
                return is_scalar($item) ? trim((string) $item) : '';
            }, $value)));
        } elseif (!is_scalar($value) && $value !== null) {
            $value = '';
        }

        $normalized = trim((string) ($value ?? ''));
        return $normalized === '' ? null : $normalized;
    }

    private function normalizeTeamMembers(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $value = $decoded;
            } else {
                $value = preg_split('/[,\n]+/', $value) ?: [];
            }
        }

        if (is_object($value)) {
            $value = (array) $value;
        }

        if (!is_array($value)) {
            return null;
        }

        $members = [];

        foreach ($value as $member) {
            if (is_array($member) || is_object($member)) {
                $candidate = null;
                if (is_array($member)) {
                    $candidate = $member['name'] ?? $member['label'] ?? $member['value'] ?? null;
                } else {
                    $candidate = $member->name ?? $member->label ?? $member->value ?? null;
                }
                $member = $candidate;
            }

            if (!is_scalar($member) && $member !== null) {
                continue;
            }

            $text = trim((string) ($member ?? ''));
            if ($text !== '') {
                $members[] = $text;
            }
        }

        return empty($members) ? null : array_values($members);
    }

    /**
     * Phase 3 — Design Studio.
     */
    public function studio(Batch $batch): Response
    {
        return Inertia::render('Studio', [
            'batch'        => $batch,
            'templateUrl'  => '/storage/' . ltrim($batch->template_path, '/'),
            'records'      => $batch->records()->orderBy('id')->get(),
        ]);
    }

    /**
     * Save global design settings from Studio.
     */
    public function saveSettings(Request $request, Batch $batch): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'global_settings' => ['required', 'array'],
        ]);

        unset($validated['global_settings']['canvasWidth']);

        $batch->update(['global_settings' => $validated['global_settings']]);

        return response()->json(['ok' => true]);
    }

    /**
     * Phase 4 — Preview grid.
     */
    public function preview(Batch $batch): Response
    {
        return Inertia::render('Preview', [
            'batch'       => $batch,
            'templateUrl' => '/storage/' . ltrim($batch->template_path, '/'),
            'records'     => $batch->records()->orderBy('id')->get(),
        ]);
    }

    /**
     * Save per-record override settings.
     */
    public function saveOverride(Request $request, Batch $batch, Record $record): \Illuminate\Http\JsonResponse
    {
        abort_unless($record->batch_id === $batch->id, 403);

        $validated = $request->validate([
            'override_settings' => ['required', 'array'],
        ]);

        $record->update(['override_settings' => $validated['override_settings']]);

        return response()->json(['ok' => true]);
    }

    /**
     * Synchronous debug endpoint for template rendering.
     */
    public function debugRender(Batch $batch): void
    {
        // Set headers early to prevent 500 error response page in browser
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Debug-Status: active');

        try {
            $record = $batch->records()->first();
            if (!$record) {
                echo "ERROR: No records found in this batch.\n";
                exit;
            }

            $settings = array_merge(
                $batch->global_settings ?? [],
                $record->override_settings ?? [],
            );

            $templateFullPath = storage_path('app/public/' . ltrim($batch->template_path, '/'));

            $exists = file_exists($templateFullPath);
            $readable = $exists ? is_readable($templateFullPath) : false;
            $size = $exists ? filesize($templateFullPath) : 0;

            if (!$exists) {
                throw new \RuntimeException("Template file does not exist at path: " . $templateFullPath);
            }
            if (!$readable) {
                throw new \RuntimeException("Template file exists but is NOT readable at path: " . $templateFullPath);
            }
            if ($size === 0) {
                throw new \RuntimeException("Template file exists but is EMPTY (0 bytes) at path: " . $templateFullPath);
            }

            $src = match (true) {
                str_ends_with(strtolower($templateFullPath), '.png')  => @\imagecreatefrompng($templateFullPath),
                str_ends_with(strtolower($templateFullPath), '.jpg'),
                str_ends_with(strtolower($templateFullPath), '.jpeg') => @\imagecreatefromjpeg($templateFullPath),
                default => null,
            };

            if (!$src) {
                $gdError = error_get_last();
                throw new \RuntimeException("GD could not open template image at: " . $templateFullPath . ". GD Error: " . json_encode($gdError));
            }

            $width  = \imagesx($src);
            $height = \imagesy($src);

            // Convert indexed to truecolor
            $wasIndexed = !\imageistruecolor($src);
            if ($wasIndexed) {
                $truecolor = \imagecreatetruecolor($width, $height);
                \imagealphablending($truecolor, false);
                \imagesavealpha($truecolor, true);
                \imagecopy($truecolor, $src, 0, 0, 0, 0, $width, $height);
                \imagedestroy($src);
                $src = $truecolor;
            }

            \imagealphablending($src, true);
            \imagesavealpha($src, true);

            // Build Font Registry
            $fontRegistry = [];
            foreach ($settings['layers'] ?? [] as $layer) {
                $family = $layer['fontFamily'] ?? '';
                $path   = $layer['fontPath'] ?? null;
                if ($family === '' || $path === null || isset($fontRegistry[$family])) {
                    continue;
                }
                if ($path) {
                    $normalized = ltrim(str_replace('\\', '/', $path), '/');
                    $candidates = [
                        storage_path('app/public/' . $normalized),
                        public_path($normalized),
                        public_path('storage/' . $normalized),
                        public_path('assets/fonts/' . basename($normalized)),
                    ];
                    foreach ($candidates as $absPath) {
                        if (file_exists($absPath) && is_readable($absPath)) {
                            $fontRegistry[$family] = $absPath;
                            break;
                        }
                    }
                }
            }
            $anyUploadedFont = !empty($fontRegistry) ? array_values($fontRegistry)[0] : null;

            // Fallbacks
            $systemFallbackCandidates = [
                public_path('assets/fonts/Inter.ttf'),
                storage_path('app/public/fonts/Inter.ttf'),
                public_path('assets/fonts/DejaVuSans.ttf'),
                public_path('assets/fonts/Arial.ttf'),
                '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
                '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
                '/usr/share/fonts/truetype/freefont/FreeSans.ttf',
            ];
            $resolvedSystemFont = null;
            foreach ($systemFallbackCandidates as $path) {
                if (file_exists($path) && is_readable($path)) {
                    $resolvedSystemFont = $path;
                    break;
                }
            }

            $layers = $settings['layers'] ?? [];
            $scaleMultiplier = $width / 800.0;

            $trace = [];

            foreach ($layers as $layer) {
                $field = $layer['field'] ?? 'name';

                // Resolve text
                $text = '';
                if ($field === 'name') {
                    $members = $record->team_members;
                    if (is_string($members) && $members !== '') {
                        $members = json_decode($members, true) ?? [];
                    }
                    if (!empty($members) && is_array($members)) {
                        $groupFormat = $layer['groupFormat'] ?? 'vertical';
                        $text = $groupFormat === 'horizontal' ? implode(', ', $members) : implode("\n", $members);
                    } else {
                        $text = $record->recipient_name ?? '';
                    }
                } else if ($field === 'ic') {
                    $text = $record->identification_number ?? '';
                } else if ($field === 'group') {
                    $text = $record->group_identifier ?? '';
                }

                if ($text === '') {
                    $trace[] = ['field' => $field, 'status' => 'skipped (empty text)'];
                    continue;
                }

                $fontSize  = (int) ($layer['fontSize'] ?? 24);
                $scaledFontSize = (int) round($fontSize * $scaleMultiplier);
                if ($scaledFontSize < 1) $scaledFontSize = 1;

                $colorHex  = $layer['color'] ?? '#000000';
                $xPercent  = (float) ($layer['x'] ?? 50.0);
                $yPercent  = (float) ($layer['y'] ?? 50.0);

                // Font Path Resolution
                $fontPath = null;
                if ($layer['fontPath'] ?? null) {
                    $normalized = ltrim(str_replace('\\', '/', $layer['fontPath']), '/');
                    $candidates = [
                        storage_path('app/public/' . $normalized),
                        public_path($normalized),
                        public_path('storage/' . $normalized),
                        public_path('assets/fonts/' . basename($normalized)),
                    ];
                    foreach ($candidates as $absPath) {
                        if (file_exists($absPath) && is_readable($absPath)) {
                            $fontPath = $absPath;
                            break;
                        }
                    }
                }
                if (!$fontPath && isset($layer['fontFamily']) && isset($fontRegistry[$layer['fontFamily']])) {
                    $fontPath = $fontRegistry[$layer['fontFamily']];
                }
                if (!$fontPath) {
                    $fontPath = $anyUploadedFont;
                }
                if (!$fontPath && isset($layer['fontFamily'])) {
                    $base = trim(pathinfo($layer['fontFamily'], PATHINFO_FILENAME));
                    $candidates = [
                        public_path('assets/fonts/' . $base . '.ttf'),
                        public_path('assets/fonts/' . $base . '.otf'),
                        storage_path('app/public/fonts/' . $base . '.ttf'),
                        storage_path('app/public/fonts/' . $base . '.otf'),
                    ];
                    foreach ($candidates as $p) {
                        if (file_exists($p) && is_readable($p)) {
                            $fontPath = $p;
                            break;
                        }
                    }
                }
                if (!$fontPath) {
                    $fontPath = $resolvedSystemFont;
                }

                $fontFileSize = $fontPath ? filesize($fontPath) : 0;
                $fontHeaderHex = "";
                $fontHeaderChars = "";
                if ($fontPath && $fontFileSize > 0) {
                    $fp = @fopen($fontPath, 'r');
                    if ($fp) {
                        $bytes = @fread($fp, 32);
                        if ($bytes) {
                            $fontHeaderHex = bin2hex($bytes);
                            $fontHeaderChars = preg_replace('/[^\x20-\x7E]/', '.', $bytes);
                        }
                        @fclose($fp);
                    }
                }

                $x = (int) ($xPercent / 100 * $width);
                $y = (int) ($yPercent / 100 * $height);

                $hex = ltrim($colorHex, '#');
                $r = hexdec(substr($hex, 0, 2));
                $g = hexdec(substr($hex, 2, 2));
                $b = hexdec(substr($hex, 4, 2));
                $color = \imagecolorallocate($src, $r, $g, $b);

                $drawResult = null;
                $bboxResult = null;
                $drawX = null;
                $drawY = null;
                $drawMethod = 'none';

                if ($fontPath && file_exists($fontPath) && is_readable($fontPath)) {
                    $align      = strtolower((string) ($layer['align'] ?? 'center'));
                    $ptSize     = $scaledFontSize;
                    $lines      = explode("\n", $text);
                    $totalLines = count($lines);
                    $lineGapPx  = (int) round($scaledFontSize * 0.40);

                    $drawResult = [];
                    $bboxResult = [];

                    foreach ($lines as $i => $line) {
                        $bbox = @\imagettfbbox($ptSize, 0, $fontPath, $line);
                        $bboxResult[] = [
                            'line' => $line,
                            'bbox' => $bbox,
                        ];

                        if ($bbox === false || !$bbox) {
                            $textWidth  = (int) round(strlen($line) * $ptSize * 0.6);
                            $textHeight = $ptSize;
                        } else {
                            $textWidth  = abs($bbox[2] - $bbox[0]);
                            $textHeight = abs($bbox[5] - $bbox[1]);
                        }

                        $drawX = match ($align) {
                            'center' => (int) round($x - $textWidth / 2),
                            'left'   => $x,
                            default  => $x - $textWidth,
                        };

                        if ($totalLines > 1) {
                            $blockHeight    = $totalLines * $textHeight + ($totalLines - 1) * $lineGapPx;
                            $blockCenterOff = (int) round($blockHeight / 2);
                            $drawY = $y - $blockCenterOff + $i * ($textHeight + $lineGapPx) + $textHeight;
                        } else {
                            $drawY = $y + (int) round($textHeight / 2);
                        }

                        $drawn = @\imagettftext($src, $ptSize, 0, $drawX, $drawY, $color, $fontPath, $line);
                        $drawMethod = 'imagettftext';
                        $drawResult[] = [
                            'line' => $line,
                            'x' => $drawX,
                            'y' => $drawY,
                            'ptSize' => $ptSize,
                            'success' => $drawn !== false,
                            'result_array' => $drawn,
                        ];

                        if ($drawn === false) {
                            $fallbackDrawn = @\imagestring($src, 5, $drawX, $drawY, $line, $color);
                            $drawMethod = 'imagettftext_fallback_imagestring';
                        }
                    }
                } else {
                    $align = strtolower((string) ($layer['align'] ?? 'center'));
                    $drawResult = [];
                    $drawMethod = 'imagestring';
                    foreach (explode("\n", $text) as $i => $line) {
                        $charWidth = 8;
                        $textWidth = strlen($line) * $charWidth;
                        $drawX = match ($align) {
                            'center' => (int) round($x - $textWidth / 2),
                            'left'   => $x,
                            default  => $x - $textWidth,
                        };
                        $drawY = $y + $i * 16;
                        $drawn = @\imagestring($src, 5, $drawX, $drawY, $line, $color);
                        $drawResult[] = [
                            'line' => $line,
                            'x' => $drawX,
                            'y' => $drawY,
                            'success' => $drawn !== false,
                        ];
                    }
                }

                $trace[] = [
                    'field' => $field,
                    'text' => $text,
                    'fontSize' => $fontSize,
                    'scaledFontSize' => $scaledFontSize,
                    'color' => $colorHex,
                    'color_index' => $color,
                    'color_rgb' => [$r, $g, $b],
                    'x_percent' => $xPercent,
                    'y_percent' => $yPercent,
                    'x_px' => $x,
                    'y_px' => $y,
                    'font_path' => $fontPath,
                    'font_file_size' => $fontFileSize,
                    'font_header_hex' => $fontHeaderHex,
                    'font_header_chars' => $fontHeaderChars,
                    'draw_method' => $drawMethod,
                    'bbox_result' => $bboxResult,
                    'draw_result' => $drawResult,
                ];
            }

            // Render to binary
            ob_start();
            \imagepng($src);
            $rasterBinary = ob_get_clean();
            \imagedestroy($src);

            $base64Image = base64_encode($rasterBinary);

            // Output Diagnostic Page as text/html
            header('Content-Type: text/html');
            echo "<html><head><title>CertifyHub Diagnostic Trace</title><style>body{font-family:sans-serif;background:#f8fafc;color:#0f172a;padding:20px}pre{background:#1e293b;color:#f8fafc;padding:15px;border-radius:8px;overflow-x:auto}table{width:100%;border-collapse:collapse;margin:15px 0}th,td{border:1px solid #cbd5e1;padding:8px;text-align:left}th{background:#f1f5f9}</style></head><body>";
            echo "<h1>CertifyHub Render Diagnostics (Deep Trace)</h1>";
            echo "<h3>Image dimensions</h3>";
            echo "<p>Width: {$width}px, Height: {$height}px, Scale Multiplier: {$scaleMultiplier}, Was Indexed: " . ($wasIndexed ? 'YES (converted to truecolor)' : 'NO') . "</p>";
            echo "<h3>Trace Details</h3>";
            echo "<pre>" . htmlspecialchars(json_encode($trace, JSON_PRETTY_PRINT)) . "</pre>";
            echo "<h3>Rendered Output</h3>";
            echo "<img src='data:image/png;base64,{$base64Image}' style='max-width:100%;border:1px solid #000;' />";
            echo "</body></html>";
            exit;
        } catch (\Throwable $e) {
            header('Content-Type: text/plain');
            echo "DIAGNOSTIC ERROR CAUGHT SYNCHRONOUSLY:\n";
            echo "Message: " . $e->getMessage() . "\n";
            echo "File: " . $e->getFile() . " on line " . $e->getLine() . "\n\n";
            echo "Stack Trace:\n";
            echo $e->getTraceAsString();
            exit;
        }
    }
}
