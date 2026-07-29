<?php

declare(strict_types=1);

use App\Jobs\PurgeFileJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
    Storage::fake('local');
});

describe('PurgeFileJob::handle()', function (): void {
    it('removes the file from disk when it exists', function (): void {
        $path = 'files/generic/test.jpg';
        Storage::disk('public')->put($path, 'bytes');

        expect(Storage::disk('public')->exists($path))->toBeTrue();

        (new PurgeFileJob(42, 'public', $path))->handle();

        expect(Storage::disk('public')->exists($path))->toBeFalse();
    });

    it('logs a warning when the file is already gone (idempotent re-run)', function (): void {
        Log::spy();

        // No file at this path; the job should log a `purge_file.missing`
        // warning and exit cleanly rather than throw.
        (new PurgeFileJob(99, 'public', 'files/generic/missing.jpg'))->handle();

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'purge_file.missing'
                    && ($context['file_id'] ?? null) === 99
                    && ($context['disk'] ?? null) === 'public';
            });
    });
});
