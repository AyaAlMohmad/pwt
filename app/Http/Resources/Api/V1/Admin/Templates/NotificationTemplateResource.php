<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Templates;

use App\Models\NotificationTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin list shape for a {@see NotificationTemplate} row.
 *
 * Unlike customer-facing translatable resources, the admin editor needs
 * BOTH locales simultaneously — calls `getTranslations()` to emit the
 * raw JSON map. Same pattern as `AdminBannerResource` /
 * `AdminPromotionResource` (docs/architecture/admin-content.md § 4.1).
 *
 * @property-read NotificationTemplate $resource
 */
class NotificationTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        unset($request);
        /** @var NotificationTemplate $template */
        $template = $this->resource;

        return [
            'id' => (int) $template->id,
            'key' => (string) $template->key,
            'channel' => $template->channel->value,
            'subject' => $template->getTranslations('subject'),
            'body' => $template->getTranslations('body'),
            'placeholders' => $template->placeholders ?? [],
            'is_active' => (bool) $template->is_active,
            'created_at' => $template->created_at->toIso8601String(),
            'updated_at' => $template->updated_at->toIso8601String(),
        ];
    }
}
