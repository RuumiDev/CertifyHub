<?php

namespace App\Jobs;

use App\Models\Batch;
use App\Models\Record;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class GenerateCertificatesBatch implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;
    public int $tries = 1;

    public function __construct(public readonly Batch $batch) {}

    public function handle(): void
    {
        $exportDir = storage_path("app/public/exports/{$this->batch->id}");

        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0755, true);
        }

        $records = $this->batch->records()->where('generation_status', 'pending')->get();

        foreach ($records as $record) {
            $record->update(['generation_status' => 'processing']);

            try {
                $this->generateCertificate($record, $exportDir);
                $record->update(['generation_status' => 'completed']);
            } catch (\Throwable $e) {
                Log::error("CertifyHub: certificate generation failed for record #{$record->id}: {$e->getMessage()}", [
                    'batch_id' => $this->batch->id,
                    'record_id' => $record->id,
                    'trace' => $e->getTraceAsString(),
                ]);
                $record->update(['generation_status' => 'failed']);
                report($e);
            }
        }

        // Bundle into ZIP
        $this->createZip($exportDir);
    }

    private function generateCertificate(Record $record, string $exportDir): void
    {
        $settings = array_merge(
            $this->batch->global_settings ?? [],
            $record->override_settings ?? [],
        );

        // Use storage_path() for absolute local filesystem resolution.
        // Storage::disk('public')->path() resolves via symlink and can fail
        // in CLI/queue contexts where the web root is not accessible.
        $templateFullPath = storage_path('app/public/' . ltrim($this->batch->template_path, '/'));

        if (!file_exists($templateFullPath)) {
            throw new \RuntimeException(
                "Asset invalidation — template file missing at: {$templateFullPath}"
            );
        }

        $format = $this->batch->export_format;

        [$image, $actualFormat] = $this->renderCertificate($templateFullPath, $record, $settings, $format);

        $outputFile = $exportDir . '/' . $this->safeFilename($record) . '.' . $actualFormat;
        file_put_contents($outputFile, $image);
    }

    private function renderCertificate(
        string $templatePath,
        Record $record,
        array $settings,
        string $format,
    ): array {
        // GD functions live in the global namespace; the \ prefix is required inside App\Jobs.
        $src = match (true) {
            str_ends_with(strtolower($templatePath), '.png')  => \imagecreatefrompng($templatePath),
            str_ends_with(strtolower($templatePath), '.jpg'),
            str_ends_with(strtolower($templatePath), '.jpeg') => \imagecreatefromjpeg($templatePath),
            default => throw new \RuntimeException('Unsupported template format.'),
        };

        if (!$src) {
            throw new \RuntimeException("GD could not open template: {$templatePath}");
        }

        // Preserve alpha for PNG sources
        \imagealphablending($src, true);
        \imagesavealpha($src, true);

        $width  = \imagesx($src);
        $height = \imagesy($src);

        $layers = $settings['layers'] ?? [];
        // Canvas width (px) at the time the user saved the design — used to
        // scale font sizes proportionally onto the full-resolution image.
        $canvasWidth = (float) ($settings['canvasWidth'] ?? 800);
        if ($canvasWidth <= 0) $canvasWidth = 800;

        // Build a fontFamily → resolved-path registry from all layers so that every layer
        // sharing a fontFamily inherits the TTF even if fontPath was only set on one of them.
        $fontRegistry = $this->buildFontRegistry($settings);

        // Also collect the first usable uploaded font path as an absolute last-resort fallback.
        // Covers old saved settings where some layers have no fontPath at all.
        $anyUploadedFont = !empty($fontRegistry) ? array_values($fontRegistry)[0] : null;

        foreach ($layers as $layer) {
            try {
                $text = $this->resolveLayerText($layer, $record);
                if ($text === '') continue;

                $fontSize  = (int) ($layer['fontSize'] ?? 24);
                // Scale font size proportionally: studio px → actual image px
                // Formula: (studioFontSize / canvasWidth) × imageWidth
                $scaledFontSize = (int) round(($fontSize / $canvasWidth) * $width);
                if ($scaledFontSize < 1) $scaledFontSize = 1;

                Log::info('CertifyHub: font scale', [
                    'layer'          => $layer['field'] ?? '?',
                    'record_id'      => $record->id,
                    'studioFontSize' => $fontSize,
                    'canvasWidth'    => $canvasWidth,
                    'imageWidth'     => $width,
                    'scaledFontSize' => $scaledFontSize,
                    'scaleRatio'     => round($width / $canvasWidth, 2),
                ]);
                $colorHex  = $layer['color'] ?? '#000000';
                $xPercent  = (float) ($layer['x'] ?? 50.0);
                $yPercent  = (float) ($layer['y'] ?? 50.0);
                $fontPath  = $this->resolveFontPath($layer['fontPath'] ?? null)
                             ?? ($fontRegistry[$layer['fontFamily'] ?? ''] ?? null)
                             ?? $anyUploadedFont
                             ?? $this->resolveSystemFontFallback();

                Log::info('CertifyHub: font resolution', [
                    'layer'           => $layer['field'] ?? 'unknown',
                    'record_id'       => $record->id,
                    'stored_fontPath' => $layer['fontPath'] ?? null,
                    'resolved_path'   => $fontPath,
                    'path_exists'     => $fontPath !== null && file_exists($fontPath),
                ]);

                $x = (int) ($xPercent / 100 * $width);
                $y = (int) ($yPercent / 100 * $height);

                [$r, $g, $b] = $this->hexToRgb($colorHex);
                $color = \imagecolorallocate($src, $r, $g, $b);

                if ($fontPath && file_exists($fontPath)) {
                    $align      = $layer['align'] ?? 'center';
                    $ptSize     = $scaledFontSize;
                    $lines      = explode("\n", $text);
                    $totalLines = count($lines);
                    $lineGapPx  = (int) round($scaledFontSize * 0.40);

                    foreach ($lines as $i => $line) {
                        $bbox = \imagettfbbox($ptSize, 0, $fontPath, $line);
                        if ($bbox === false) {
                            // imagettfbbox failed: FreeType may not support this font.
                            // Fall back to built-in imagestring for this line.
                            Log::warning('CertifyHub: imagettfbbox returned false — font unusable by GD/FreeType, using imagestring fallback.', [
                                'fontPath'  => $fontPath,
                                'ptSize'    => $ptSize,
                                'record_id' => $record->id,
                            ]);
                            \imagestring($src, 5, $x, $y + $i * 16, $line, $color);
                            continue;
                        }
                        $textWidth  = abs($bbox[2] - $bbox[0]);
                        $textHeight = abs($bbox[5] - $bbox[1]);

                        // Center text at the layer's X coordinate (not always image centre).
                        // Default X is 50 %, so existing designs are unaffected.
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

                        \imagettftext($src, $ptSize, 0, $drawX, $drawY, $color, $fontPath, $line);
                    }
                } else {
                    // Last-resort: GD built-in imagestring — fixed 8×16px glyphs.
                    // This path should rarely be hit since resolveSystemFontFallback
                    // covers common Windows and Linux system font locations.
                    $align = $layer['align'] ?? 'center';
                    foreach (explode("\n", $text) as $i => $line) {
                        $charWidth = 8;
                        $textWidth = strlen($line) * $charWidth;
                        $drawX = match ($align) {
                            'center' => (int) round($width / 2 - $textWidth / 2),
                            'left'   => $x,
                            default  => $x - $textWidth,
                        };
                        \imagestring($src, 5, $drawX, $y + $i * 16, $line, $color);
                    }
                }
            } catch (\Throwable $e) {
                Log::error('CertifyHub: layer render error — skipping layer.', [
                    'layer'     => $layer['field'] ?? 'unknown',
                    'record_id' => $record->id,
                    'error'     => $e->getMessage(),
                ]);
                // Continue rendering remaining layers
            }
        }

        ob_start();
        // PDF format: GD cannot produce real PDF — output PNG and rename to .png
        $actualFormat = $format === 'pdf' ? 'png' : $format;
        match ($actualFormat) {
            'png'  => \imagepng($src),
            default => \imagejpeg($src, null, 92),
        };
        $binary = ob_get_clean();
        \imagedestroy($src);

        return [$binary, $actualFormat];
    }

    private function resolveLayerText(array $layer, Record $record): string
    {
        $field = $layer['field'] ?? 'name';

        if ($field === 'name') {
            // team_members may arrive as an already-decoded array (model cast)
            // or as a raw JSON string if the cast didn't apply.
            $members = $record->team_members;
            if (is_string($members) && $members !== '') {
                $members = json_decode($members, true) ?? [];
            }

            if (!empty($members) && is_array($members)) {
                $groupFormat = $layer['groupFormat'] ?? 'vertical';
                return $groupFormat === 'horizontal'
                    ? implode(', ', $members)
                    : implode("\n", $members); // vertical: newline-separated for multi-line draw
            }

            // Individual record
            return $record->recipient_name ?? '';
        }

        return match ($field) {
            'ic'    => $record->identification_number ?? '',
            'group' => $record->group_identifier ?? '',
            default => $record->recipient_name ?? '',
        };
    }

    /**
     * Build a map of fontFamily → resolved absolute path from all layers in the settings.
     * Allows layers that share a fontFamily to inherit a single uploaded TTF file,
     * regardless of which layer was active when the font was uploaded.
     */
    private function buildFontRegistry(array $settings): array
    {
        $registry = [];
        foreach ($settings['layers'] ?? [] as $layer) {
            $family = $layer['fontFamily'] ?? '';
            $path   = $layer['fontPath'] ?? null;
            if ($family === '' || $path === null || isset($registry[$family])) {
                continue;
            }
            $resolved = $this->resolveFontPath($path);
            if ($resolved !== null) {
                $registry[$family] = $resolved;
            }
        }
        return $registry;
    }

    /**
     * Try common system font locations so text renders at full scale
     * even when the user hasn't uploaded a custom font.
     * Returns null only if none of the candidates exist.
     */
    private function resolveSystemFontFallback(): ?string
    {
        $candidates = [
            // Windows
            'C:\Windows\Fonts\arial.ttf',
            'C:\Windows\Fonts\Arial.ttf',
            'C:\Windows\Fonts\verdana.ttf',
            'C:\Windows\Fonts\calibri.ttf',
            // Linux
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
            '/usr/share/fonts/truetype/freefont/FreeSans.ttf',
            // macOS
            '/Library/Fonts/Arial.ttf',
            '/System/Library/Fonts/Helvetica.ttc',
        ];

        foreach ($candidates as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    private function resolveFontPath(?string $relativePath): ?string
    {
        if (!$relativePath) {
            return null;
        }

        // Use storage_path() for unambiguous absolute local resolution.
        // Storage::disk('public')->path() is equivalent but storage_path() is
        // explicit and avoids any filesystem disk config ambiguity.
        $absPath = storage_path('app/public/' . ltrim($relativePath, '/'));

        if (!file_exists($absPath)) {
            Log::error('CertifyHub: custom font asset missing — falling back to built-in font.', [
                'expected_path' => $absPath,
                'relative_path' => $relativePath,
                'batch_id'      => $this->batch->id,
            ]);
            return null; // caller will fall back to imagestring()
        }

        return $absPath;
    }

    private function createZip(string $exportDir): void
    {
        $zipPath = storage_path("app/public/exports/{$this->batch->id}.zip");
        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Cannot create ZIP at {$zipPath}");
        }

        $files = glob($exportDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                $zip->addFile($file, basename($file));
            }
        }

        $zip->close();
    }

    private function safeFilename(Record $record): string
    {
        $name = $record->group_identifier ?? $record->recipient_name ?? 'record';
        return preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name) . '_' . $record->id;
    }

    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }
}
