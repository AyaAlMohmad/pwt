<?php

declare(strict_types=1);

use App\Enums\FileType;
use App\Jobs\PurgeFileJob;
use App\Models\File;
use App\Models\User;
use App\Services\Files\FileStorageService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('public');
    Storage::fake('local');
    $this->service = new FileStorageService;
});

describe('FileStorageService::store()', function (): void {
    it('writes profile_avatar uploads to the public disk', function (): void {
        $user = User::factory()->customer()->create();
        $jpeg = UploadedFile::fake()->image('avatar.jpg', 100, 100);

        $file = $this->service->store($jpeg, $user, FileType::ProfileAvatar);

        expect($file)->toBeInstanceOf(File::class)
            ->and($file->disk)->toBe('public')
            ->and($file->is_public)->toBeTrue()
            ->and($file->user_id)->toBe($user->id)
            ->and($file->type->value)->toBe('profile_avatar');

        Storage::disk('public')->assertExists($file->path);
        Storage::disk('local')->assertMissing($file->path);
    });

    it('writes maintenance_media uploads to the private (local) disk', function (): void {
        $user = User::factory()->customer()->create();
        $png = UploadedFile::fake()->image('issue.png');

        $file = $this->service->store($png, $user, FileType::MaintenanceMedia);

        expect($file->disk)->toBe('local')
            ->and($file->is_public)->toBeFalse();

        Storage::disk('local')->assertExists($file->path);
        Storage::disk('public')->assertMissing($file->path);
    });

    it('computes the correct SHA-256 of the uploaded content', function (): void {
        $user = User::factory()->customer()->create();

        // Use a fixed-content payload so the checksum is predictable.
        $content = 'deterministic-fixture-bytes-2026';
        $expected = hash('sha256', $content);

        // Save the deterministic bytes to disk and wrap them in an
        // UploadedFile that getRealPath() can read.
        $tmp = tempnam(sys_get_temp_dir(), 'pwtsvc');
        file_put_contents($tmp, $content);

        $upload = new UploadedFile($tmp, 'fixture.pdf', 'application/pdf', null, true);

        $file = $this->service->store($upload, $user, FileType::OrderAttachment);

        expect($file->checksum_sha256)->toBe($expected);
    });
});

describe('FileStorageService::softDelete()', function (): void {
    it('soft-deletes the row and dispatches PurgeFileJob with the right payload', function (): void {
        Queue::fake();

        $user = User::factory()->customer()->create();
        $file = File::factory()->for($user)->public()->create();

        $this->service->softDelete($file);

        // Row is soft-deleted in DB.
        expect(File::withTrashed()->find($file->id)->deleted_at)->not->toBeNull();
        expect(File::find($file->id))->toBeNull(); // hidden by global scope

        // Job was queued with the trio (id, disk, path).
        Queue::assertPushed(PurgeFileJob::class, function (PurgeFileJob $job) use ($file): bool {
            return $job->fileId === $file->id
                && $job->disk === $file->disk
                && $job->path === $file->path;
        });
    });
});

describe('FileStorageService::streamDownload()', function (): void {
    it('returns a Symfony response for an on-disk file with the right Content-Disposition', function (): void {
        $user = User::factory()->customer()->create();
        $file = File::factory()->for($user)->private()->create();

        // Put real bytes on the fake disk so the stream has content.
        Storage::disk($file->disk)->put($file->path, 'service-test-bytes');

        $response = $this->service->streamDownload($file);

        // Laravel's `Storage::download()` returns one of two Symfony
        // response classes depending on the underlying driver. Both
        // implement `HeaderBag`-equipped responses.
        $isStream = $response instanceof StreamedResponse;
        $isBinary = $response instanceof BinaryFileResponse;
        expect($isStream || $isBinary)->toBeTrue();

        // The Content-Disposition surfaces an attachment header — clients
        // see this as a "save file as" rather than inline render.
        $disposition = (string) $response->headers->get('Content-Disposition');
        expect($disposition)->toContain('attachment');
    });
});
