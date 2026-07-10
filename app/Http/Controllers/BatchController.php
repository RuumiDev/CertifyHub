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
                Record::create([
                    'batch_id'               => $batch->id,
                    'recipient_name'         => $name,
                    'identification_number'  => $ic ?: null,
                    'group_identifier'       => null,
                    'generation_status'      => 'pending',
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
            Record::create([
                'batch_id'               => $batch->id,
                'recipient_name'         => null,            // null for team records
                'identification_number'  => null,
                'group_identifier'       => $groupName,
                'team_members'           => $members,        // ["Ahmad","Akmal","Abdullah"]
                'generation_status'      => 'pending',
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
            'records.*.recipient_name'            => ['nullable', 'string', 'max:255'],
            'records.*.identification_number'     => ['nullable', 'string', 'max:255'],
            'records.*.group_identifier'          => ['nullable', 'string', 'max:255'],
            'records.*.team_members'              => ['nullable', 'array'],
            'records.*.team_members.*'            => ['string', 'max:255'],
        ]);

        foreach ($validated['records'] as $row) {
            if (isset($row['id'])) {
                Record::where('id', $row['id'])->where('batch_id', $batch->id)->update([
                    'recipient_name'           => $row['recipient_name'] ?? null,
                    'identification_number'    => $row['identification_number'] ?? null,
                    'group_identifier'         => $row['group_identifier'] ?? null,
                    'team_members'             => isset($row['team_members']) ? json_encode($row['team_members']) : null,
                ]);
            } else {
                Record::create([
                    'batch_id'               => $batch->id,
                    'recipient_name'         => $row['recipient_name'] ?? null,
                    'identification_number'  => $row['identification_number'] ?? null,
                    'group_identifier'       => $row['group_identifier'] ?? null,
                    'team_members'           => $row['team_members'] ?? null,
                    'generation_status'      => 'pending',
                ]);
            }
        }

        return response()->json(['ok' => true]);
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
