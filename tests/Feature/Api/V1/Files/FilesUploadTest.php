<?php

declare(strict_types=1);

use App\Models\File;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    // Each test starts with fresh fake disks so writes are isolated.
    Storage::fake('public');
    Storage::fake('local');

    // Drain the per-user files-upload limiter between tests so we never
    // bleed buckets across runs.
    RateLimiter::clear('files-upload');
});

/**
 * Helper — create a customer + Sanctum token.
 *
 * @return array{user: User, token: string}
 */
function makeFilesCustomer(): array
{
    $user = User::factory()->customer()->create();
    $token = $user->createToken('mobile', ['*'])->plainTextToken;

    return ['user' => $user, 'token' => $token];
}

/**
 * Drain the files-upload limiter for a specific user id so the per-user
 * bucket starts at zero on each describe block.
 */
function clearFilesUploadLimiter(int $userId): void
{
    RateLimiter::clear('files-upload:'.$userId);
}

describe('POST /api/v1/files — happy paths', function (): void {
    it('uploads a JPEG (maintenance_media) and returns the FileResource envelope', function (): void {
        ['user' => $user, 'token' => $token] = makeFilesCustomer();
        clearFilesUploadLimiter($user->id);

        $jpeg = UploadedFile::fake()->image('kitchen-leak.jpg', 800, 600);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->post('/api/v1/files', [
                'file' => $jpeg,
                'type' => 'maintenance_media',
            ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id', 'uuid', 'url', 'mime', 'size', 'type', 'is_public', 'created_at',
                ],
            ])
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.mime', 'image/jpeg')
            ->assertJsonPath('data.type', 'maintenance_media')
            ->assertJsonPath('data.is_public', false);

        // The DB row matches the uploader and is private.
        $row = File::query()->latest('id')->first();
        expect($row)->not->toBeNull()
            ->and($row->user_id)->toBe($user->id)
            ->and($row->type->value)->toBe('maintenance_media')
            ->and($row->is_public)->toBeFalse()
            ->and($row->disk)->toBe('local')
            ->and($row->checksum_sha256)->not->toBeNull()
            ->and(strlen($row->checksum_sha256))->toBe(64);

        // The artefact is physically present on the private disk.
        Storage::disk('local')->assertExists($row->path);
    });

    it('uploads a PDF (order_attachment) and stores it on the private disk', function (): void {
        ['user' => $user, 'token' => $token] = makeFilesCustomer();
        clearFilesUploadLimiter($user->id);

        $pdf = UploadedFile::fake()->create('invoice.pdf', 200, 'application/pdf');

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->post('/api/v1/files', [
                'file' => $pdf,
                'type' => 'order_attachment',
            ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.mime', 'application/pdf')
            ->assertJsonPath('data.type', 'order_attachment')
            ->assertJsonPath('data.is_public', false);

        $row = File::query()->latest('id')->first();
        expect($row->disk)->toBe('local')
            ->and($row->mime)->toBe('application/pdf');

        Storage::disk('local')->assertExists($row->path);
    });

    it('uploads a PNG profile_avatar to the public disk with is_public=true', function (): void {
        ['user' => $user, 'token' => $token] = makeFilesCustomer();
        clearFilesUploadLimiter($user->id);

        $png = UploadedFile::fake()->image('avatar.png', 256, 256);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->post('/api/v1/files', [
                'file' => $png,
                'type' => 'profile_avatar',
            ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'profile_avatar')
            ->assertJsonPath('data.is_public', true);

        $row = File::query()->latest('id')->first();
        expect($row->is_public)->toBeTrue()
            ->and($row->disk)->toBe('public');

        Storage::disk('public')->assertExists($row->path);
        Storage::disk('local')->assertMissing($row->path);
    });
});

describe('POST /api/v1/files — security / validation', function (): void {
    it('rejects a file whose bytes do not match an allowed mime even if the extension does (ERR_FILE_MIME_REJECTED)', function (): void {
        ['user' => $user, 'token' => $token] = makeFilesCustomer();
        clearFilesUploadLimiter($user->id);

        // Real-world attack: a PHP / text payload renamed to image.png
        // and posted with Content-Type: image/png. Laravel's `mimetypes`
        // rule sniffs the actual content via finfo (libmagic) and rejects
        // the mismatch — Symfony will report something like text/x-php
        // or text/plain, neither of which is on the allowlist.
        $payload = "<?php echo 'evil'; ?>\nplain-text-bytes-not-png";
        $path = tempnam(sys_get_temp_dir(), 'pwtmime');
        file_put_contents($path, $payload);

        $forged = new UploadedFile(
            $path,
            'evil.png',     // client filename pretends to be a PNG
            'image/png',    // Content-Type header lies about content type
            null,
            true,
        );

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->post('/api/v1/files', [
                'file' => $forged,
                'type' => 'profile_avatar',
            ], ['Accept' => 'application/json']);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'ERR_FILE_MIME_REJECTED');

        // No DB row should have been created.
        $this->assertDatabaseCount('files', 0);
    });

    it('rejects an unsupported mime (.txt) with ERR_FILE_MIME_REJECTED', function (): void {
        ['user' => $user, 'token' => $token] = makeFilesCustomer();
        clearFilesUploadLimiter($user->id);

        $txt = UploadedFile::fake()->createWithContent('notes.txt', 'plain text');

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->post('/api/v1/files', [
                'file' => $txt,
                'type' => 'generic',
            ], ['Accept' => 'application/json']);

        $response->assertStatus(422);
        // The validator can fire either mimes/mimetypes first; both are
        // mapped to ERR_FILE_MIME_REJECTED in failedValidation().
        expect($response->json('code'))->toBe('ERR_FILE_MIME_REJECTED');

        $this->assertDatabaseCount('files', 0);
    });

    it('rejects a 6 MB file with 413 ERR_FILE_TOO_LARGE', function (): void {
        ['user' => $user, 'token' => $token] = makeFilesCustomer();
        clearFilesUploadLimiter($user->id);

        // 6000 KB = ~5.86 MB → triggers the validator's max:5120 rule,
        // which the form request maps to 413 ERR_FILE_TOO_LARGE.
        $tooBig = UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf');

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->post('/api/v1/files', [
                'file' => $tooBig,
                'type' => 'order_attachment',
            ], ['Accept' => 'application/json']);

        $response->assertStatus(413)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'ERR_FILE_TOO_LARGE');

        $this->assertDatabaseCount('files', 0);
    });

    it('returns 422 ERR_VALIDATION when the file field is missing', function (): void {
        ['user' => $user, 'token' => $token] = makeFilesCustomer();
        clearFilesUploadLimiter($user->id);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->post('/api/v1/files', [
                'type' => 'generic',
            ], ['Accept' => 'application/json']);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure(['errors' => ['file']]);
    });

    it('returns 422 ERR_VALIDATION when the type field is missing', function (): void {
        ['user' => $user, 'token' => $token] = makeFilesCustomer();
        clearFilesUploadLimiter($user->id);

        $jpeg = UploadedFile::fake()->image('x.jpg');

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->post('/api/v1/files', [
                'file' => $jpeg,
            ], ['Accept' => 'application/json']);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure(['errors' => ['type']]);
    });

    it('returns 422 ERR_VALIDATION when type is not one of the enum values', function (): void {
        ['user' => $user, 'token' => $token] = makeFilesCustomer();
        clearFilesUploadLimiter($user->id);

        $jpeg = UploadedFile::fake()->image('x.jpg');

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->post('/api/v1/files', [
                'file' => $jpeg,
                'type' => 'hacker',
            ], ['Accept' => 'application/json']);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure(['errors' => ['type']]);
    });
});

describe('POST /api/v1/files — auth + rate limiting', function (): void {
    it('returns 401 ERR_UNAUTHENTICATED when no bearer token is supplied', function (): void {
        $jpeg = UploadedFile::fake()->image('x.jpg');

        $response = $this->post('/api/v1/files', [
            'file' => $jpeg,
            'type' => 'generic',
        ], ['Accept' => 'application/json']);

        $response->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');

        $this->assertDatabaseCount('files', 0);
    });

    it('returns 429 ERR_RATE_LIMITED after exceeding 30 uploads per hour for the same user', function (): void {
        ['user' => $user, 'token' => $token] = makeFilesCustomer();
        clearFilesUploadLimiter($user->id);

        // Fire 30 successful uploads — the 31st should be rejected.
        for ($i = 0; $i < 30; $i++) {
            $jpeg = UploadedFile::fake()->image("hit-{$i}.jpg");

            $this->withHeader('Authorization', "Bearer $token")
                ->post('/api/v1/files', [
                    'file' => $jpeg,
                    'type' => 'generic',
                ], ['Accept' => 'application/json'])
                ->assertCreated();
        }

        $thirtyFirst = UploadedFile::fake()->image('overflow.jpg');

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->post('/api/v1/files', [
                'file' => $thirtyFirst,
                'type' => 'generic',
            ], ['Accept' => 'application/json']);

        $response->assertStatus(429);

        // Clean up so the next test gets a fresh bucket.
        RateLimiter::clear('files-upload:'.$user->id);
    });
});

/*
|--------------------------------------------------------------------------
| INFRA TRIPWIRE — post_max_size simulation
|--------------------------------------------------------------------------
| The `UploadFileRequest::prepareForValidation()` tripwire fires only when
| PHP has dropped the body before the request reaches Laravel — i.e.
| `$_FILES` is empty AND `CONTENT_LENGTH` exceeds the cap. PHP-FPM /
| Apache strip those scenarios before Laravel's TestCase ever sees them,
| so a true end-to-end reproduction would require an external HTTP client
| against a running PHP server with a 5 MB cap configured. We document
| the gap rather than ship a flaky fake.
*/
it('infra tripwire — empty $_FILES + large Content-Length returns 413 ERR_FILE_TOO_LARGE')
    ->skip('Requires a running PHP server with post_max_size = 5 MB; not reproducible inside the in-process test client.');
