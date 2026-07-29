<?php

declare(strict_types=1);

use App\Jobs\PurgeFileJob;
use App\Models\File;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('public');
    Storage::fake('local');
});

/**
 * @return array{user: User, token: string}
 */
function makeFilesCustomerForDelete(): array
{
    $user = User::factory()->customer()->create();
    $token = $user->createToken('mobile', ['*'])->plainTextToken;

    return ['user' => $user, 'token' => $token];
}

describe('DELETE /api/v1/files/{id}', function (): void {
    it('lets the owner soft-delete their own file and dispatches PurgeFileJob', function (): void {
        Queue::fake();

        ['user' => $user, 'token' => $token] = makeFilesCustomerForDelete();
        $file = File::factory()->for($user)->public()->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->deleteJson("/api/v1/files/{$file->id}");

        $response->assertOk()
            ->assertJsonPath('success', true);

        // Row is soft-deleted (deleted_at populated, but not gone from DB).
        $this->assertSoftDeleted('files', ['id' => $file->id]);
        expect(File::withTrashed()->find($file->id)->deleted_at)->not->toBeNull();

        // PurgeFileJob was dispatched with the file's identifiers.
        Queue::assertPushed(PurgeFileJob::class, function (PurgeFileJob $job) use ($file): bool {
            return $job->fileId === $file->id
                && $job->disk === $file->disk
                && $job->path === $file->path;
        });
    });

    it('forbids another customer from deleting a file with 403 ERR_FORBIDDEN', function (): void {
        ['user' => $owner] = makeFilesCustomerForDelete();
        ['token' => $strangerToken] = makeFilesCustomerForDelete();

        $file = File::factory()->for($owner)->public()->create();

        $response = $this->withHeader('Authorization', "Bearer $strangerToken")
            ->deleteJson("/api/v1/files/{$file->id}");

        $response->assertStatus(403)
            ->assertJsonPath('success', false);

        // Row is NOT soft-deleted.
        $this->assertDatabaseHas('files', ['id' => $file->id, 'deleted_at' => null]);
    });

    it('returns 401 ERR_UNAUTHENTICATED when no bearer token is supplied', function (): void {
        ['user' => $owner] = makeFilesCustomerForDelete();
        $file = File::factory()->for($owner)->public()->create();

        $response = $this->deleteJson("/api/v1/files/{$file->id}");

        $response->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');

        $this->assertDatabaseHas('files', ['id' => $file->id, 'deleted_at' => null]);
    });

    it('returns 404 when the file id does not exist', function (): void {
        ['token' => $token] = makeFilesCustomerForDelete();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->deleteJson('/api/v1/files/999999');

        $response->assertStatus(404);
    });

    it('returns 404 when the file is already soft-deleted (hidden by global scope)', function (): void {
        ['user' => $owner, 'token' => $token] = makeFilesCustomerForDelete();
        $file = File::factory()->for($owner)->public()->softDeleted()->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->deleteJson("/api/v1/files/{$file->id}");

        $response->assertStatus(404);
    });

    it('lets a super_admin delete a file owned by another user (policy admin bypass)', function (): void {
        Queue::fake();

        ['user' => $owner] = makeFilesCustomerForDelete();
        $file = File::factory()->for($owner)->public()->create();

        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('admin-cli', ['*'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $adminToken")
            ->deleteJson("/api/v1/files/{$file->id}");

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('files', ['id' => $file->id]);
        Queue::assertPushed(PurgeFileJob::class);
    });
});
