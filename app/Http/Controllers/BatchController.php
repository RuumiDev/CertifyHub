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
        $record = $batch->records()->first();
        if (!$record) {
            abort(404, 'No records found in this batch');
        }

        $job = new \App\Jobs\GenerateCertificatesBatch($batch);

        // Use reflection to access helper methods on the Job
        $refRender = new \ReflectionMethod($job, 'renderCertificate');
        $refRender->setAccessible(true);

        $refFontPath = new \ReflectionMethod($job, 'resolveFontPath');
        $refFontPath->setAccessible(true);

        $refSysFont = new \ReflectionMethod($job, 'resolveSystemFontFallback');
        $refSysFont->setAccessible(true);

        $refRepoFont = new \ReflectionMethod($job, 'resolveRepositoryFontFallback');
        $refRepoFont->setAccessible(true);

        $refRegistry = new \ReflectionMethod($job, 'buildFontRegistry');
        $refRegistry->setAccessible(true);

        $settings = array_merge(
            $batch->global_settings ?? [],
            $record->override_settings ?? [],
        );

        $templateFullPath = storage_path('app/public/' . ltrim($batch->template_path, '/'));

        // Collect debug info
        $debugInfo = [];
        $debugInfo['record'] = $record->toArray();
        $debugInfo['template_path_exists'] = file_exists($templateFullPath);
        $debugInfo['template_path'] = $templateFullPath;
        $debugInfo['global_settings'] = $batch->global_settings;
        $debugInfo['override_settings'] = $record->override_settings;
        $debugInfo['merged_settings'] = $settings;

        $fontRegistry = $refRegistry->invoke($job, $settings);
        $debugInfo['font_registry'] = $fontRegistry;
        $anyUploadedFont = !empty($fontRegistry) ? array_values($fontRegistry)[0] : null;
        $debugInfo['any_uploaded_font'] = $anyUploadedFont;

        $layersDebug = [];
        $layers = $settings['layers'] ?? [];
        foreach ($layers as $layer) {
            $fontFamily = $layer['fontFamily'] ?? null;
            $layerFontPath = $layer['fontPath'] ?? null;

            $resolvedPath = $refFontPath->invoke($job, $layerFontPath)
                            ?? ($fontRegistry[$fontFamily ?? ''] ?? null)
                            ?? $anyUploadedFont
                            ?? $refRepoFont->invoke($job, $fontFamily)
                            ?? $refSysFont->invoke($job);

            $layersDebug[] = [
                'field' => $layer['field'] ?? '?',
                'text' => $layer['field'] === 'name' ? ($record->recipient_name ?? '') : ($record->group_identifier ?? ''),
                'x_percent' => $layer['x'] ?? null,
                'y_percent' => $layer['y'] ?? null,
                'font_family' => $fontFamily,
                'font_path_stored' => $layerFontPath,
                'font_path_resolved' => $resolvedPath,
                'font_path_exists' => $resolvedPath !== null && file_exists($resolvedPath),
                'font_path_readable' => $resolvedPath !== null && is_readable($resolvedPath),
            ];
        }
        $debugInfo['layers'] = $layersDebug;

        try {
            [$image, $format] = $refRender->invoke($job, $templateFullPath, $record, $settings, 'png');
            $base64Image = base64_encode($image);

            // Output a diagnostic HTML page
            header('Content-Type: text/html');
            echo "<html><head><title>CertifyHub Diagnostic</title><style>body{font-family:sans-serif;background:#f8fafc;color:#0f172a;padding:20px}pre{background:#000;color:#0f0;padding:15px;border-radius:8px;overflow-x:auto}table{width:100%;border-collapse:collapse;margin:15px 0}th,td{border:1px solid #cbd5e1;padding:8px;text-align:left}th{background:#f1f5f9}</style></head><body>";
            echo "<h1>CertifyHub Render Diagnostics</h1>";
            echo "<h3>Environment & Records Info</h3>";
            echo "<table>";
            echo "<tr><th>Parameter</th><th>Value</th></tr>";
            echo "<tr><td>Template Path Exists</td><td>" . ($debugInfo['template_path_exists'] ? "YES" : "NO") . " (" . $debugInfo['template_path'] . ")</td></tr>";
            echo "<tr><td>Record Name</td><td>" . ($record->recipient_name ?? 'NULL') . "</td></tr>";
            echo "<tr><td>Record ID</td><td>" . $record->id . "</td></tr>";
            echo "</table>";

            echo "<h3>Layers Info</h3>";
            echo "<table>";
            echo "<tr><th>Field</th><th>Text</th><th>X%</th><th>Y%</th><th>Font Family</th><th>Stored Path</th><th>Resolved Path</th><th>Exists</th><th>Readable</th></tr>";
            foreach ($debugInfo['layers'] as $ld) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($ld['field']) . "</td>";
                echo "<td>" . htmlspecialchars($ld['text']) . "</td>";
                echo "<td>" . htmlspecialchars($ld['x_percent']) . "</td>";
                echo "<td>" . htmlspecialchars($ld['y_percent']) . "</td>";
                echo "<td>" . htmlspecialchars($ld['font_family']) . "</td>";
                echo "<td>" . htmlspecialchars($ld['font_path_stored'] ?? 'NULL') . "</td>";
                echo "<td>" . htmlspecialchars($ld['font_path_resolved'] ?? 'NULL') . "</td>";
                echo "<td>" . ($ld['font_path_exists'] ? "YES" : "NO") . "</td>";
                echo "<td>" . ($ld['font_path_readable'] ? "YES" : "NO") . "</td>";
                echo "</tr>";
            }
            echo "</table>";

            echo "<h3>Raw Global Settings</h3>";
            echo "<pre>" . htmlspecialchars(json_encode($debugInfo['global_settings'], JSON_PRETTY_PRINT)) . "</pre>";

            echo "<h3>Rendered Output</h3>";
            echo "<img src='data:image/png;base64,{$base64Image}' style='max-width:100%;border:1px solid #000;' />";
            echo "</body></html>";
            exit;
        } catch (\Throwable $e) {
            header('Content-Type: text/plain');
            echo "Error during render:\n";
            echo $e->getMessage() . "\n\n";
            echo $e->getTraceAsString();
            exit;
        }
    }
}
