<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Content;

use App\Models\Banner;

/**
 * Admin DETAIL shape for a {@see Banner}. Currently identical to the list
 * shape but retained as a distinct resource so future detail-only fields
 * (e.g. activity-log summary, placement-conflict warnings) can land here
 * without churning the list shape.
 *
 * @property-read Banner $resource
 */
final class AdminBannerDetailResource extends AdminBannerResource
{
    // Inherits toArray() from AdminBannerResource — same shape for now.
}
