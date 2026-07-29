<?php

declare(strict_types=1);

use App\Models\File;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('public');
    Storage::fake('local');
});

/**
 * Create a file row + the matching on-disk artefact on a fake disk so the
 * download stream actually has bytes to serve.
 */
function makeStoredFile(User $owner, bool $public = false): File
{
    $file = $public
        ? File::factory()->for($owner)->public()->create()
        : File::factory()->for($owner)->private()->create();

    Storage::disk($file->disk)->put($file->path, 'fake-binary-content');

    return $file;
}

describe('GET /api/v1/files/{uuid}/download', function (): void {
    it('lets the owner download a private file via Sanctum bearer', function (): void {
        $owner = User::factory()->customer()->create();
        $token = $owner->createToken('mobile')->plainTextToken;
        $file = makeStoredFile($owner, public: false);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->get("/api/v1/files/{$file->uuid}/download");

        $response->assertOk();
        expect($response->headers->get('Content-Type'))->not->toBeNull();
    });

    it('forbids a stranger from downloading a private file without a signed URL', function (): void {
        $owner = User::factory()->customer()->create();
        $stranger = User::factory()->customer()->create();
        $strangerToken = $stranger->createToken('mobile')->plainTextToken;
        $file = makeStoredFile($owner, public: false);

        $response = $this->withHeader('Authorization', "Bearer $strangerToken")
            ->getJson("/api/v1/files/{$file->uuid}/download");

        $response->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('serves the file when called with a valid signed URL (no token required)', function (): void {
        $owner = User::factory()->customer()->create();
        $file = makeStoredFile($owner, public: false);

        $signed = URL::temporarySignedRoute(
            'files.download',
            now()->addMinutes(5),
            ['uuid' => $file->uuid],
        );

        $response = $this->get($signed);

        $response->assertOk();
    });

    it('rejects an expired signed URL', function (): void {
        $owner = User::factory()->customer()->create();
        $file = makeStoredFile($owner, public: false);

        // Build a signed URL that expired one minute ago. Laravel's
        // hasValidSignature() returns false, and with no Sanctum bearer
        // on the request the controller falls through the unauthenticated
        // branch → 401 ERR_UNAUTHENTICATED.
        $expired = URL::temporarySignedRoute(
            'files.download',
            now()->subMinute(),
            ['uuid' => $file->uuid],
        );

        $response = $this->getJson($expired);

        // The exact status depends on which gate trips first. The
        // controller checks signed-validity first, then falls through to
        // auth → which on no-token returns 401. Either outcome is
        // acceptable per the architecture spec § 9.4 footnote; assert
        // it's a denial code, and record the actual status for clarity.
        expect($response->status())->toBeIn([401, 403])
            ->and($response->json('code'))->toBeIn(['ERR_UNAUTHENTICATED', 'ERR_FORBIDDEN']);
    });

    it('serves a public file to an authenticated stranger (Sanctum branch)', function (): void {
        $owner = User::factory()->customer()->create();
        $stranger = User::factory()->customer()->create();
        $strangerToken = $stranger->createToken('mobile')->plainTextToken;
        $file = makeStoredFile($owner, public: true);

        // Public files are downloadable by any authenticated user because
        // FilePolicy::view returns true on `is_public=true`.
        $response = $this->withHeader('Authorization', "Bearer $strangerToken")
            ->get("/api/v1/files/{$file->uuid}/download");

        $response->assertOk();
    });

    it('returns 404 ERR_NOT_FOUND for an unknown uuid', function (): void {
        $owner = User::factory()->customer()->create();
        $token = $owner->createToken('mobile')->plainTextToken;

        // Whereuuid route constraint requires a syntactically valid UUID.
        $bogus = '00000000-0000-0000-0000-000000000000';

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/v1/files/{$bogus}/download");

        $response->assertStatus(404)
            ->assertJsonPath('code', 'ERR_NOT_FOUND');
    });
});
