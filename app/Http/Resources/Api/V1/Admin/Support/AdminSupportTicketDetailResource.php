<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Support;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\SupportTicketStatusHistory;
use Illuminate\Http\Request;

/**
 * Admin DETAIL shape of a {@see SupportTicket}.
 *
 * Adds (vs list shape):
 *  - `resolution_summary`, `cancellation_reason` (admin-visible always).
 *  - `cancelled_by{id,name,role}` when present.
 *  - `related_order` (Order summary) / `related_machine` (Machine + product summary).
 *  - Full message thread INCLUDING `is_internal_note=true` rows.
 *  - `status_history` with full actor identities.
 *  - `deleted_at` so audit views know when the ticket was trashed.
 *
 * @property-read SupportTicket $resource
 */
final class AdminSupportTicketDetailResource extends AdminSupportTicketResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SupportTicket $t */
        $t = $this->resource;
        $base = parent::toArray($request);

        $base['resolution_summary'] = $t->resolution_summary;
        $base['cancellation_reason'] = $t->cancellation_reason;
        $base['cancelled_by'] = $this->resolveCancelledBy($t);
        $base['related_order'] = $this->resolveRelatedOrder($t);
        $base['related_machine'] = $this->resolveRelatedMachine($t);

        $base['messages'] = $t->relationLoaded('messages')
            ? $t->messages
                ->map(static fn (SupportTicketMessage $m) => (new AdminSupportTicketMessageResource($m))->resolve($request))
                ->values()
                ->all()
            : [];

        $base['status_history'] = $t->relationLoaded('statusHistory')
            ? $t->statusHistory
                ->map(static fn (SupportTicketStatusHistory $h) => (new AdminSupportTicketStatusHistoryResource($h))->resolve($request))
                ->values()
                ->all()
            : [];

        $base['deleted_at'] = $t->deleted_at?->toIso8601String();
        $base['updated_at'] = $t->updated_at->toIso8601String();

        return $base;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveCancelledBy(SupportTicket $t): ?array
    {
        if (! $t->relationLoaded('cancelledBy') || $t->cancelledBy === null) {
            return null;
        }

        return $this->resolveActorSummary($t->cancelledBy);
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
            'created_at' => $order->created_at->toIso8601String(),
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
                'id' => $machine->product->id,
                'code' => $machine->product->code,
                'name' => $machine->product->getTranslation('name', $locale),
            ];
        }

        return [
            'id' => $machine->id,
            'public_id' => $machine->public_id,
            'serial_number' => $machine->serial_number,
            'installed_at' => $machine->installed_at->toDateString(),
            'is_active' => (bool) $machine->is_active,
            'product' => $product,
        ];
    }
}
