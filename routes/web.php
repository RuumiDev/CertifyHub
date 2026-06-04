<?php

use App\Http\Controllers\BatchController;
use App\Http\Controllers\ExportController;
use Illuminate\Support\Facades\Route;

// Phase 1 — Landing / Ingestion
Route::get('/', [BatchController::class, 'index'])->name('home');
Route::post('/batch', [BatchController::class, 'store'])->name('batch.store');

// Phase 2 — Data Validation
Route::get('/batch/{batch}/validate', [BatchController::class, 'validate'])->name('batch.validate');
Route::put('/batch/{batch}/records', [BatchController::class, 'updateRecords'])->name('batch.records.update');

// Phase 3 — Design Studio
Route::get('/batch/{batch}/studio', [BatchController::class, 'studio'])->name('batch.studio');
Route::put('/batch/{batch}/settings', [BatchController::class, 'saveSettings'])->name('batch.settings.save');

// Phase 4 — Preview
Route::get('/batch/{batch}/preview', [BatchController::class, 'preview'])->name('batch.preview');
Route::put('/batch/{batch}/records/{record}/override', [BatchController::class, 'saveOverride'])->name('batch.record.override');

// Phase 5 — Export
Route::post('/batch/{batch}/execute', [ExportController::class, 'execute'])->name('batch.execute');
Route::get('/batch/{batch}/progress', [ExportController::class, 'progress'])->name('batch.progress');
Route::get('/batch/{batch}/download', [ExportController::class, 'download'])->name('batch.download');

// Font upload
Route::post('/fonts/upload', [ExportController::class, 'uploadFont'])->name('fonts.upload');

