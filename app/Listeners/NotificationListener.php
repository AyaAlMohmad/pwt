<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\Auth\OtpLoginIssued;
use App\Events\Auth\OtpRegisterIssued;
use App\Events\Auth\PasswordResetEmail;
use App\Events\Auth\PasswordResetPhone;
use App\Events\BaseDomainEvent;
use App\Events\Contact\ContactLeadCreated;
use App\Events\Orders\OrderCompleted;
use App\Events\Rfq\RfqCreated;
use App\Events\Support\SupportTicketAssigned;
use App\Events\Support\SupportTicketCancelled;
use App\Events\Support\SupportTicketCreated;
use App\Events\Support\SupportTicketReplied;
use App\Jobs\SendNotificationJob;
use App\Mail\GenericOpsNotificationMail;
use App\Models\ContactLead;
use App\Models\NotificationTemplate;
use App\Models\Order;
use App\Models\RfqRequest;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\TemplateRenderer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Single fan-out listener for every {@see BaseDomainEvent}. The
 * dispatcher handles channel resolution, template lookup, and dedupe;
 * this listener is intentionally trivial so the wiring is grep-able.
 *
 * The four auth.* events are NO-OPS per orchestrator decision 2 —
 * Phase 3 already sends the SMS / email directly.
 *
 * Listener is synchronous (NOT ShouldQueue) so the persisted
 * Notification row inserts happen inside the parent
 * DB::afterCommit closure, while the channel hand-off uses the queued
 * {@see SendNotificationJob}.
 */
final class NotificationListener
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly TemplateRenderer $renderer,
    ) {}

    public function handle(BaseDomainEvent $event): void
    {
        if (
            $event instanceof OtpRegisterIssued
            || $event instanceof OtpLoginIssued
            || $event instanceof PasswordResetEmail
            || $event instanceof PasswordResetPhone
        ) {
            // Phase 3 already handles the actual send; the listener
            // intentionally skips these per orchestrator decision 2.
            return;
        }

        // Phase 13 — Contact form events have NO user_id (guest
        // submissions) so they bypass the user-bound dispatcher entirely
        // and go straight to the ops-inbox mail. See
        // docs/architecture/contact.md § 9.3.
        if ($event instanceof ContactLeadCreated) {
            $this->notifyOpsForContactLead($event);

            return;
        }

        // Phase 22 — RFQ events have NO user_id (guest submissions).
        if ($event instanceof RfqCreated) {
            $this->notifyOpsForRfq($event);

            return;
        }

        // Phase 19 — admin internal-note replies are activity-log-only.
        // The event fires for audit consistency but produces zero
        // notifications. See docs/architecture/admin-support.md § 10.6.
        if ($event instanceof SupportTicketReplied && $event->isInternal) {
            return;
        }

        // Phase 22 — Order completed: ops-only email with payment +
        // customer + order details. No customer-facing notification.
        if ($event instanceof OrderCompleted) {
            $this->notifyOpsForOrderCompleted($event);

            return;
        }

        // Standard customer-side fan-out via the dispatcher.
        $this->dispatcher->dispatchFor($event);

        // Phase 12 — support events ALSO notify the ops inbox via a
        // direct mail send (the persisted-Notification pipeline is
        // user-id bound; ops is an address not a user). See
        // docs/architecture/support.md § 12.1 / § 12.3.
        if ($event instanceof SupportTicketCreated) {
            $this->notifyOps('support.created_ops', $event);
        }
        if ($event instanceof SupportTicketCancelled) {
            $this->notifyOps('support.cancelled_ops', $event);
        }

        // Phase 19 — newly-assigned admin gets an `_ops` heads-up. The
        // ops inbox here is the single shared `OPS_INBOX_EMAIL`; future
        // Phase 22 may route per-assignee. See admin-support.md § 10.5.
        if ($event instanceof SupportTicketAssigned) {
            $this->notifyOps('support.assigned_ops', $event);
        }
    }

    /**
     * Direct mail to the configured ops inbox using a `_ops` template.
     *
     * Failures are logged and swallowed — ops notification is best-effort
     * and must not block the persisted customer-side notification path.
     */
    private function notifyOps(string $templateKey, BaseDomainEvent $event): void
    {
        $opsEmail = (string) config('notifications.ops_inbox_email', '');
        if ($opsEmail === '') {
            return;
        }

        try {
            $template = NotificationTemplate::query()
                ->where('key', $templateKey)
                ->where('channel', 'email')
                ->where('is_active', true)
                ->first();

            if ($template === null) {
                Log::warning('notifications.dispatch.template_missing', [
                    'type' => $templateKey,
                    'channel' => 'email',
                ]);

                return;
            }

            $payload = $event->payload();
            $userId = isset($payload['user_id']) && is_int($payload['user_id'])
                ? $payload['user_id']
                : null;

            $user = $userId !== null
                ? User::query()->find($userId)
                : null;

            $context = [
                'user' => [
                    'id' => $user?->id,
                    'name' => $user !== null ? (string) $user->name : '',
                    'email' => $user?->email,
                ],
                'data' => $payload,
            ];

            $rendered = $this->renderer->renderBilingual($template, $context);

            Mail::to($opsEmail)->queue(
                new GenericOpsNotificationMail(
                    title: (string) ($rendered['title']['en'] ?? ''),
                    body: (string) ($rendered['body']['en'] ?? ''),
                ),
            );
        } catch (\Throwable $e) {
            Log::warning('notifications.ops.dispatch_failed', [
                'template' => $templateKey,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Phase 22 — ops-only mail for a guest RFQ submission.
     *
     * Sends directly to info@pwtmegatech.com with RFQ details.
     * Failures are logged + swallowed (best-effort).
     */
    private function notifyOpsForRfq(RfqCreated $event): void
    {
        $targetEmail = 'info@pwtmegatech.com';

        try {
            /** @var RfqRequest|null $rfq */
            $rfq = RfqRequest::query()->find($event->rfqId);
            if ($rfq === null) {
                Log::warning('notifications.ops.rfq_missing', [
                    'rfq_id' => $event->rfqId,
                ]);

                return;
            }

            $title = 'New RFQ Submission — '.$rfq->company_name;
            $body = sprintf(
                "Company: %s | Email: %s | Phone: %s | Notes: %s",
                $rfq->company_name,
                $rfq->email,
                (string) ($rfq->phone ?? '—'),
                (string) ($rfq->notes ?? '—'),
            );

            Log::info('OPS_RFQ_EMAIL_START', [
                'to' => $targetEmail,
                'rfq_id' => $event->rfqId,
                'title' => $title,
            ]);

            Mail::to($targetEmail)->queue(
                new GenericOpsNotificationMail(
                    title: $title,
                    body: $body,
                ),
            );

            Log::info('OPS_RFQ_EMAIL_QUEUED_OK', [
                'to' => $targetEmail,
                'rfq_id' => $event->rfqId,
            ]);
        } catch (\Throwable $e) {
            Log::error('OPS_RFQ_EMAIL_FAILED', [
                'rfq_id' => $event->rfqId,
                'to' => $targetEmail,
                'error' => $e->getMessage(),
                'class' => get_class($e),
            ]);
        }
    }

    /**
     * Phase 13 — ops-only mail for a guest contact-form submission.
     *
     * Mirrors {@see self::notifyOps()} but re-queries the ContactLead
     * (no User model in play) and exposes a `lead.*` placeholder
     * namespace to the template renderer.
     *
     * Failures are logged + swallowed (best-effort).
     */
    private function notifyOpsForContactLead(ContactLeadCreated $event): void
    {
        $opsEmail = (string) config('notifications.ops_inbox_email', '');
        if ($opsEmail === '') {
            return;
        }

        try {
            $template = NotificationTemplate::query()
                ->where('key', 'contact.created_ops')
                ->where('channel', 'email')
                ->where('is_active', true)
                ->first();

            if ($template === null) {
                Log::warning('notifications.dispatch.template_missing', [
                    'type' => 'contact.created_ops',
                    'channel' => 'email',
                ]);

                return;
            }

            /** @var ContactLead|null $lead */
            $lead = ContactLead::query()->find($event->contactLeadId);
            if ($lead === null) {
                Log::warning('notifications.ops.contact_lead_missing', [
                    'contact_lead_id' => $event->contactLeadId,
                ]);

                return;
            }

            $context = [
                'lead' => [
                    'id' => $lead->id,
                    'name' => $lead->name,
                    'email' => $lead->email,
                    'phone' => (string) ($lead->phone ?? ''),
                    'subject' => $lead->subject,
                    'message' => $lead->message,
                    'source' => $lead->source->value,
                ],
            ];

            $rendered = $this->renderer->renderBilingual($template, $context);

            Log::info('OPS_CONTACT_EMAIL_START', [
                'to' => $opsEmail,
                'contact_lead_id' => $event->contactLeadId,
                'title' => (string) ($rendered['title']['en'] ?? ''),
            ]);

            Mail::to($opsEmail)->queue(
                new GenericOpsNotificationMail(
                    title: (string) ($rendered['title']['en'] ?? ''),
                    body: (string) ($rendered['body']['en'] ?? ''),
                ),
            );

            Log::info('OPS_CONTACT_EMAIL_QUEUED_OK', [
                'to' => $opsEmail,
                'contact_lead_id' => $event->contactLeadId,
            ]);
        } catch (\Throwable $e) {
            Log::error('OPS_CONTACT_EMAIL_FAILED', [
                'contact_lead_id' => $event->contactLeadId,
                'to' => $opsEmail,
                'error' => $e->getMessage(),
                'class' => get_class($e),
            ]);
        }
    }

    /**
     * Phase 22 — ops-only mail when an order transitions to Completed.
     *
     * Sends payment + customer + order details to info@pwtmegatech.com.
     * Failures are logged + swallowed (best-effort).
     */
    private function notifyOpsForOrderCompleted(OrderCompleted $event): void
    {
        $targetEmail = 'info@pwtmegatech.com';

        try {
            /** @var Order|null $order */
            $order = Order::query()
                ->with(['user', 'payments'])
                ->find($event->orderId);

            if ($order === null) {
                Log::warning('notifications.ops.order_missing', [
                    'order_id' => $event->orderId,
                ]);

                return;
            }

            $user = $order->user;
            $payment = $order->payments->first();

            $customerInfo = $user !== null
                ? sprintf(
                    "Customer: %s | Email: %s | Phone: %s",
                    $user->name,
                    $user->email,
                    (string) ($user->phone ?? '—'),
                )
                : 'Customer: —';

            $paymentInfo = $payment !== null
                ? sprintf(
                    "Transaction ID: %s | Merchant Ref: %s | Status: %s | Amount: %s %s | Method: %s | Captured At: %s",
                    (string) ($payment->gateway_transaction_id ?? '—'),
                    (string) ($payment->gateway_reference ?? $payment->public_id),
                    $payment->status->value,
                    $payment->amount,
                    $payment->currency,
                    $payment->payment_method->value,
                    $payment->captured_at !== null
                        ? $payment->captured_at->toIso8601String()
                        : '—',
                )
                : 'Payment: —';

            $title = 'Order Completed — #'.$order->public_id;
            $body = sprintf(
                "Order ID: %s | Public ID: %s\n%s\n%s",
                $order->id,
                $order->public_id,
                $customerInfo,
                $paymentInfo,
            );

            Log::info('OPS_ORDER_EMAIL_START', [
                'to' => $targetEmail,
                'order_id' => $event->orderId,
                'title' => $title,
            ]);

            Mail::to($targetEmail)->queue(
                new GenericOpsNotificationMail(
                    title: $title,
                    body: $body,
                ),
            );

            Log::info('OPS_ORDER_EMAIL_QUEUED_OK', [
                'to' => $targetEmail,
                'order_id' => $event->orderId,
            ]);
        } catch (\Throwable $e) {
            Log::error('OPS_ORDER_EMAIL_FAILED', [
                'order_id' => $event->orderId,
                'to' => $targetEmail,
                'error' => $e->getMessage(),
                'class' => get_class($e),
            ]);
        }
    }
}
