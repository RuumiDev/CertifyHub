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
            'global_settings' => [],
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
            'templateUrl'  => Storage::disk('public')->url($batch->template_path),
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
            'templateUrl' => Storage::disk('public')->url($batch->template_path),
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
}
