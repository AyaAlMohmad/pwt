<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FileType;
use App\Models\File;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<File>
 */
final class FileFactory extends Factory
{
    protected $model = File::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $uuid = (string) Str::uuid();

        return [
            'uuid' => $uuid,
            'user_id' => User::factory(),
            'disk' => 'public',
            'path' => "files/generic/{$uuid}.jpg",
            'filename_original' => 'sample.jpg',
            'mime' => 'image/jpeg',
            'size' => fake()->numberBetween(1024, 5 * 1024 * 1024),
            'type' => FileType::Generic->value,
            'checksum_sha256' => hash('sha256', (string) Str::random(64)),
            'is_public' => true,
        ];
    }

    public function imageJpeg(): self
    {
        return $this->state(function (array $attrs): array {
            $uuid = (string) ($attrs['uuid'] ?? Str::uuid());

            return [
                'uuid' => $uuid,
                'mime' => 'image/jpeg',
                'path' => "files/generic/{$uuid}.jpg",
                'filename_original' => 'photo.jpg',
            ];
        });
    }

    public function imagePng(): self
    {
        return $this->state(function (array $attrs): array {
            $uuid = (string) ($attrs['uuid'] ?? Str::uuid());

            return [
                'uuid' => $uuid,
                'mime' => 'image/png',
                'path' => "files/generic/{$uuid}.png",
                'filename_original' => 'screenshot.png',
            ];
        });
    }

    public function pdf(): self
    {
        return $this->state(function (array $attrs): array {
            $uuid = (string) ($attrs['uuid'] ?? Str::uuid());

            return [
                'uuid' => $uuid,
                'mime' => 'application/pdf',
                'path' => "files/support_attachment/{$uuid}.pdf",
                'filename_original' => 'invoice.pdf',
                'type' => FileType::SupportAttachment->value,
                'disk' => 'local',
                'is_public' => false,
            ];
        });
    }

    public function private(): self
    {
        return $this->state(function (array $attrs): array {
            $uuid = (string) ($attrs['uuid'] ?? Str::uuid());
            $type = $attrs['type'] ?? FileType::MaintenanceMedia->value;

            return [
                'uuid' => $uuid,
                'disk' => 'local',
                'is_public' => false,
                'type' => $type,
                'path' => "files/{$type}/{$uuid}.jpg",
            ];
        });
    }

    public function public(): self
    {
        return $this->state(function (array $attrs): array {
            $uuid = (string) ($attrs['uuid'] ?? Str::uuid());
            $type = $attrs['type'] ?? FileType::Generic->value;

            return [
                'uuid' => $uuid,
                'disk' => 'public',
                'is_public' => true,
                'type' => $type,
                'path' => "files/{$type}/{$uuid}.jpg",
            ];
        });
    }

    public function softDeleted(): self
    {
        return $this->state(fn (array $attrs): array => [
            'deleted_at' => now()->subMinutes(5),
        ]);
    }

    public function forType(FileType $type): self
    {
        return $this->state(function (array $attrs) use ($type): array {
            $uuid = (string) ($attrs['uuid'] ?? Str::uuid());
            $ext = match ($type) {
                FileType::ProfileAvatar, FileType::ProductImage, FileType::Generic,
                FileType::MaintenanceMedia, FileType::OrderAttachment => 'jpg',
                FileType::SupportAttachment => 'pdf',
            };

            return [
                'uuid' => $uuid,
                'type' => $type->value,
                'disk' => $type->isPublic() ? 'public' : 'local',
                'is_public' => $type->isPublic(),
                'mime' => $ext === 'pdf' ? 'application/pdf' : 'image/jpeg',
                'path' => "files/{$type->value}/{$uuid}.{$ext}",
                'filename_original' => "sample.{$ext}",
            ];
        });
    }
}
