<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Files\FileStorageService;

/**
 * Classification of a stored file.
 *
 * The type drives visibility (public vs private disk) and how downstream
 * modules (Maintenance, Support, Profile, Orders, Catalog) attach files
 * to their domain rows.
 *
 * Public types are served via a direct `Storage::url(...)` link; private
 * types are served via a signed URL pointing at the download endpoint.
 *
 * @see FileStorageService
 */
enum FileType: string
{
    case ProfileAvatar = 'profile_avatar';
    case MaintenanceMedia = 'maintenance_media';
    case ProductImage = 'product_image';
    case SupportAttachment = 'support_attachment';
    case OrderAttachment = 'order_attachment';
    case Generic = 'generic';

    /**
     * Is this type served from the `public` disk (anonymous, direct URL)?
     */
    public function isPublic(): bool
    {
        return match ($this) {
            self::ProfileAvatar, self::ProductImage, self::Generic => true,
            self::MaintenanceMedia, self::SupportAttachment, self::OrderAttachment => false,
        };
    }

    /**
     * The disk this type lives on (`public` or `private`).
     *
     * `private` maps to Laravel's default `local` disk (storage/app/private),
     * which is NOT served through `php artisan serve` directly — only via
     * the controlled `streamDownload` path.
     */
    public function disk(): string
    {
        return $this->isPublic() ? 'public' : 'local';
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
