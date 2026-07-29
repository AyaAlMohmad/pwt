<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Support;

use App\Models\SupportTicket;
use Illuminate\Http\Request;

/**
 * DETAIL shape of a {@see SupportTicket}.
 *
 * Extends the list shape with:
 *   - full message thread (internal notes excluded at the resource AND
 *     service layers — defence-in-depth per support.md § 6.2)
 *   - status_history timeline
 *   - related_order / related_machine summaries
 *   - resolution_summary (only when status is resolved or closed)
 *
 * @property-read SupportTicket $resource
 */
final class SupportTicketDetailResource extends SupportTicketResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SupportTicket $t */
        $t = $this->resource;

        $base = parent::toArray($request);

        // Defence-in-depth: filter `is_internal_note=true` rows out at
        // the resource layer too, even though the service already
        // filtered them at the query level. See support.md § 6.2.
        $base['messages'] = $t->relationLoaded('messages')
            ? SupportTicketMessageResource::collection(
                $t->messages->filter(static fn ($m): bool => $m->is_internal_note === false)->values(),
            )->resolve($request)
            : [];

        $base['status_history'] = $t->relationLoaded('statusHistory')
            ? SupportTicketStatusHistoryResource::collection($t->statusHistory)->resolve($request)
            : [];

        $base['related_order'] = $this->resolveRelatedOrder($t);
        $base['related_machine'] = $this->resolveRelatedMachine($t);

        // Only surface resolution_summary when admin has resolved/closed.
        $statusValue = $t->status->value;
        if (in_array($statusValue, ['resolved', 'closed'], true)) {
            $base['resolution_summary'] = $t->resolution_summary;
        }

        $base['updated_at'] = $t->updated_at->toIso8601String();

        return $base;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveRelatedOrder(SupportTicket $t): ?array
    {
        if (! $t->relationLoaded('relatedOrder') || $t->relatedOrder === null) {
            return null;
        }

        $order = $t->relatedOrder;

        return [
            'id' => $order->id,
            'public_id' => $order->public_id,
            'status' => $order->status->value,
            'total_amount' => (string) $order->total_amount,
            'currency' => $order->currency,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveRelatedMachine(SupportTicket $t): ?array
    {
        if (! $t->relationLoaded('relatedMachine') || $t->relatedMachine === null) {
            return null;
        }

        $machine = $t->relatedMachine;
        $product = null;
        if ($machine->relationLoaded('product') && $machine->product !== null) {
            $locale = app()->getLocale();
            $product = [
                'code' => $machine->product->code,
                'name' => $machine->product->getTranslation('name', $locale),
            ];
        }

        return [
            'id' => $machine->id,
            'public_id' => $machine->public_id,
            'serial_number' => $machine->serial_number,
            'installed_at' => $machine->installed_at->toDateString(),
            'product' => $product,
        ];
    }
}
