<?php

declare(strict_types=1);

use App\Models\File;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('public');
    Storage::fake('local');
});

describe('File model accessors', function (): void {
    it('publicUrl() returns a usable URL for files on the public disk', function (): void {
        $user = User::factory()->customer()->create();
        $file = File::factory()->for($user)->public()->create();

        $url = $file->publicUrl();

        expect($url)->toBeString()
            ->and($url)->toContain($file->path);
    });

    it('publicUrl() returns null for private files', function (): void {
        $user = User::factory()->customer()->create();
        $file = File::factory()->for($user)->private()->create();

        expect($file->publicUrl())->toBeNull();
    });

    it('signedDownloadUrl() returns a URL containing the file uuid and a signature query param', function (): void {
        $user = User::factory()->customer()->create();
        $file = File::factory()->for($user)->private()->create();

        $signed = $file->signedDownloadUrl();

        expect($signed)->toBeString()
            ->and($signed)->toContain($file->uuid)
            ->and($signed)->toContain('signature=');
    });
});

describe('File model serialization', function (): void {
    it('omits path and checksum_sha256 from toArray() output', function (): void {
        $user = User::factory()->customer()->create();
        $file = File::factory()->for($user)->public()->create();

        $array = $file->toArray();

        expect($array)->not->toHaveKey('path')
            ->and($array)->not->toHaveKey('checksum_sha256');
    });
});

describe('File model activity log', function (): void {
    it('emits an activity_log entry on create', function (): void {
        $beforeCount = Activity::count();

        $user = User::factory()->customer()->create();
        File::factory()->for($user)->public()->create();

        expect(Activity::count())->toBeGreaterThan($beforeCount);

        // The most-recent activity belongs to the files log channel.
        $latest = Activity::latest('id')->first();
        expect($latest->log_name)->toBe('files');
    });

    it('emits an activity_log entry on (soft) delete', function (): void {
        $user = User::factory()->customer()->create();
        $file = File::factory()->for($user)->public()->create();

        $beforeCount = Activity::query()->where('log_name', 'files')->count();

        $file->delete(); // soft delete (SoftDeletes trait)

        $afterCount = Activity::query()->where('log_name', 'files')->count();
        expect($afterCount)->toBeGreaterThan($beforeCount);
    });
});
