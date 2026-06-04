<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateCertificatesBatch;
use App\Models\Batch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class ExportController extends Controller
{
    /**
     * Dispatch the generation queue job.
     */
    public function execute(Request $request, Batch $batch): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'export_format' => ['required', 'in:pdf,png,jpg'],
        ]);

        $batch->update(['export_format' => $validated['export_format']]);
        $batch->records()->update(['generation_status' => 'pending']);

        GenerateCertificatesBatch::dispatch($batch);

        return response()->json(['ok' => true, 'batch_id' => $batch->id]);
    }

    /**
     * Poll progress endpoint.
     */
    public function progress(Batch $batch): \Illuminate\Http\JsonResponse
    {
        $total     = $batch->records()->count();
        $completed = $batch->records()->where('generation_status', 'completed')->count();
        $failed    = $batch->records()->where('generation_status', 'failed')->count();
        $done      = ($completed + $failed) >= $total && $total > 0;

        $zipPath   = storage_path("app/public/exports/{$batch->id}.zip");
        $zipReady  = $done && $completed > 0
                     && file_exists($zipPath)
                     && filesize($zipPath) > 0;

        return response()->json([
            'total'     => $total,
            'completed' => $completed,
            'failed'    => $failed,
            'done'      => $done,
            'zip_ready' => $zipReady,
        ]);
    }

    /**
     * Download ZIP archive.
     */
    public function download(Batch $batch): \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\RedirectResponse
    {
        $zipPath = storage_path("app/public/exports/{$batch->id}.zip");

        if (!file_exists($zipPath) || filesize($zipPath) === 0) {
            Log::error('CertifyHub: ZIP archive missing or empty on download request.', [
                'batch_id'  => $batch->id,
                'zip_path'  => $zipPath,
                'exists'    => file_exists($zipPath),
                'size'      => file_exists($zipPath) ? filesize($zipPath) : null,
            ]);

            return redirect()->back()->withErrors([
                'export' => 'The certificate archive could not be found. Please re-run the export and ensure the queue worker is running.',
            ]);
        }

        // Use batch creation date for a clean, human-readable archive title.
        $dateStamp = $batch->created_at->format('dmY');
        $filename  = "CertifyHub_Batch_{$dateStamp}.zip";

        return response()->download(
            $zipPath,
            $filename,
            [
                'Content-Type'  => 'application/zip',
                'Cache-Control' => 'no-cache, must-revalidate',
            ]
        )->deleteFileAfterSend(false);
    }

    /**
     * Upload a custom font asset.
     */
    public function uploadFont(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'font' => [
                'required',
                'file',
                // mimes: checks file extension, more reliable than MIME sniffing for fonts
                'extensions:ttf,otf,woff,woff2',
                'max:5120',
            ],
        ]);

        $path = $request->file('font')->store('fonts', 'public');

        return response()->json([
            'path' => $path,
            'url'  => asset("storage/{$path}"),
            'name' => $request->file('font')->getClientOriginalName(),
        ]);
    }
}
