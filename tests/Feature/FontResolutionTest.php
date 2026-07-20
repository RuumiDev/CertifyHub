<?php

namespace Tests\Feature;

use App\Jobs\GenerateCertificatesBatch;
use App\Models\Batch;
use ReflectionMethod;
use Tests\TestCase;

class FontResolutionTest extends TestCase
{
    public function test_system_font_fallback_resolves_and_downloads(): void
    {
        $batch = new Batch();
        $job = new GenerateCertificatesBatch($batch);

        $method = new ReflectionMethod(GenerateCertificatesBatch::class, 'resolveSystemFontFallback');
        $method->setAccessible(true);

        $fontPath = $method->invoke($job);

        $this->assertNotNull($fontPath);
        $this->assertFileExists($fontPath);
        $this->assertStringContainsString('Inter.ttf', $fontPath);
    }
}
