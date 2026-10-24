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
use App\Events\Payments\PaymentCaptured;
use App\Events\Rentals\MachineRentalPaymentCaptured;
use App\Events\Rentals\MachineRentalPaymentDue;
use App\Events\Rentals\MachineRentalPaymentFailed;
use App\Events\Rfq\RfqCreated;
use App\Events\Support\SupportTicketAssigned;
use App\Models\Machine;
use App\Events\Support\SupportTicketCancelled;
use App\Events\Support\SupportTicketCreated;
use App\Events\Support\SupportTicketReplied;
use App\Jobs\SendNotificationJob;
use App\Mail\GenericOpsNotificationMail;
use App\Models\ContactLead;
use App\Models\NotificationTemplate;
use App\Models\Order;
use App\Models\Payment;
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

        // Order completed no longer sends a separate ops email; the
        // detailed payment email is triggered by PaymentCaptured so ops
        // gets the same content at the right time without duplication.
        if ($event instanceof OrderCompleted) {
            return;
        }

        // Payment successfully captured: ops-only email to info@pwtmegatech.com.
        if ($event instanceof PaymentCaptured) {
            $this->notifyOpsForPaymentCaptured($event);
        }

        // Standard customer-side fan-out via the dispatcher.
        $this->dispatcher->dispatchFor($event);

        // Rental events — also notify admins via in-app + email.
        if ($event instanceof MachineRentalPaymentDue) {
            $this->notifyAdmins('rental.payment_due_admin', $event);
        }
        if ($event instanceof MachineRentalPaymentCaptured) {
            $this->notifyAdmins('rental.payment_captured_admin', $event);
        }
        if ($event instanceof MachineRentalPaymentFailed) {
            $this->notifyAdmins('rental.payment_failed_admin', $event);
        }

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
            $body = '<h2 style="font-family: sans-serif; margin-bottom: 16px;">New RFQ Submission</h2>'
                .'<p style="font-family: sans-serif; margin: 4px 0;"><strong>RFQ ID:</strong> #'.e((string) $rfq->id).'</p>'
                .'<p style="font-family: sans-serif; margin: 4px 0;"><strong>Company:</strong> '.e($rfq->company_name).'</p>'
                .'<h3 style="font-family: sans-serif; margin: 16px 0 8px;">Request Details</h3>'
                .'<table border="1" cellpadding="0" cellspacing="0" style="border-collapse: collapse; font-family: sans-serif; width: 100%; max-width: 640px;">'
                .'<thead><tr style="background-color: #f5f5f5;"><th align="left" style="padding: 8px; border: 1px solid #cccccc; width: 30%;">Field</th><th align="left" style="padding: 8px; border: 1px solid #cccccc;">Value</th></tr></thead>'
                .'<tbody>'
                .'<tr><td style="padding: 8px; border: 1px solid #cccccc;"><strong>Company Name</strong></td><td style="padding: 8px; border: 1px solid #cccccc;">'.e($rfq->company_name).'</td></tr>'
                .'<tr><td style="padding: 8px; border: 1px solid #cccccc;"><strong>Email</strong></td><td style="padding: 8px; border: 1px solid #cccccc;">'.e($rfq->email).'</td></tr>'
                .'<tr><td style="padding: 8px; border: 1px solid #cccccc;"><strong>Phone</strong></td><td style="padding: 8px; border: 1px solid #cccccc;">'.e((string) ($rfq->phone ?? '—')).'</td></tr>'
                .'<tr><td style="padding: 8px; border: 1px solid #cccccc;"><strong>Notes</strong></td><td style="padding: 8px; border: 1px solid #cccccc;">'.nl2br(e((string) ($rfq->notes ?? '—'))).'</td></tr>'
                .'<tr><td style="padding: 8px; border: 1px solid #cccccc;"><strong>Submitted At</strong></td><td style="padding: 8px; border: 1px solid #cccccc;">'.e($rfq->created_at?->format('d M Y, g:i A') ?? '—').'</td></tr>'
                .'</tbody></table>'
                .'<h3 style="font-family: sans-serif; margin: 16px 0 8px;">Action Required</h3>'
                .'<p style="font-family: sans-serif; margin: 4px 0;">Please review and follow up with the customer.</p>';

            Log::info('OPS_RFQ_EMAIL_START', [
                'to' => $targetEmail,
                'rfq_id' => $event->rfqId,
                'title' => $title,
            ]);

            Mail::to($targetEmail)->queue(
                new GenericOpsNotificationMail(
                    title: $title,
                    body: $body,
                    isHtml: true,
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
            }

            /** @var ContactLead|null $lead */
            $lead = ContactLead::query()->find($event->contactLeadId);
            if ($lead === null) {
                Log::warning('notifications.ops.contact_lead_missing', [
                    'contact_lead_id' => $event->contactLeadId,
                ]);

                return;
            }

            // A missing / deactivated template must never swallow a real
            // lead — fall back to a plain rendering of the submission.
            if ($template === null) {
                $this->mailContactLeadFallback($opsEmail, $lead);

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
     * Template-less rendering of a contact lead for the ops inbox.
     *
     * Used when the `contact.created_ops` email template row is missing
     * or deactivated so the submission still reaches ops.
     */
    private function mailContactLeadFallback(string $opsEmail, ContactLead $lead): void
    {
        $body = 'New contact-form submission (#'.$lead->id.')'."\n\n"
            .'Name: '.$lead->name."\n"
            .'Email: '.$lead->email."\n"
            .'Phone: '.($lead->phone ?? '—')."\n"
            .'Source: '.$lead->source->value."\n"
            .'Subject: '.$lead->subject."\n\n"
            .$lead->message;

        Mail::to($opsEmail)->queue(
            new GenericOpsNotificationMail(
                title: 'New Contact Submission — '.$lead->subject,
                body: $body,
            ),
        );

        Log::info('OPS_CONTACT_EMAIL_QUEUED_FALLBACK', [
            'to' => $opsEmail,
            'contact_lead_id' => $lead->id,
        ]);
    }

    /**
     * Ops-only mail when a payment is successfully captured.
     *
     * Sends the full order + payment details to info@pwtmegatech.com on
     * every successful payment. Failures are logged + swallowed (best-effort).
     */
    private function notifyOpsForPaymentCaptured(PaymentCaptured $event): void
    {
        $targetEmail = 'info@pwtmegatech.com';

        try {
            /** @var Order|null $order */
            $order = Order::query()
                ->with(['user', 'payments', 'items.product.category'])
                ->find($event->orderId);

            if ($order === null) {
                Log::warning('notifications.ops.order_missing_for_payment', [
                    'order_id' => $event->orderId,
                    'payment_id' => $event->paymentId,
                ]);

                return;
            }

            /** @var Payment|null $payment */
            $payment = Payment::query()->find($event->paymentId)
                ?? ($order->latestPayment() ?? $order->payments->first());

            $customerName = (string) ($order->customer_name ?? $order->user?->name ?? '—');
            $customerEmail = (string) ($order->customer_email ?? $order->user?->email ?? '—');
            $customerPhone = (string) ($order->customer_phone ?? $order->user?->phone ?? '—');

            $amount = $payment !== null
                ? sprintf('%s %s', $payment->currency, number_format((float) $payment->amount, 2))
                : '—';
            $status = $payment !== null ? ucfirst((string) $payment->status->value) : '—';
            $paymentMethod = $payment !== null
                ? ucwords(str_replace('_', ' ', (string) $payment->payment_method->value))
                : '—';
            $transactionId = $payment !== null ? (string) ($payment->gateway_transaction_id ?? '—') : '—';
            $capturedAt = $payment?->captured_at?->format('d M Y, g:i A') ?? '—';

            $orderItems = $order->items->isEmpty()
                ? '<p>—</p>'
                : '<table border="1" cellpadding="0" cellspacing="0" style="border-collapse: collapse; font-family: sans-serif; width: 100%; max-width: 640px;">'
                    .'<thead><tr style="background-color: #f5f5f5;"><th align="left" style="padding: 8px; border: 1px solid #cccccc;">Category</th><th align="left" style="padding: 8px; border: 1px solid #cccccc;">Product</th><th align="center" style="padding: 8px; border: 1px solid #cccccc;">Qty</th></tr></thead>'
                    .'<tbody>'
                    .$order->items->map(function ($item): string {
                        return sprintf(
                            '<tr><td style="padding: 8px; border: 1px solid #cccccc;">%s</td><td style="padding: 8px; border: 1px solid #cccccc;">%s</td><td align="center" style="padding: 8px; border: 1px solid #cccccc;">%d</td></tr>',
                            e((string) ($item->product?->category?->name ?? '—')),
                            e((string) $item->product_name_snapshot),
                            $item->quantity,
                        );
                    })->implode('')
                    .'</tbody></table>';

            $title = 'Payment Successfully Captured — '.$order->displayName();
            $body = '<h2 style="font-family: sans-serif; margin-bottom: 16px;">Payment Successfully Captured</h2>'
                .'<h3 style="font-family: sans-serif; margin: 16px 0 8px;">Order Details</h3>'
                .'<p style="font-family: sans-serif; margin: 4px 0;"><strong>Order ID:</strong> #'.e((string) $order->id).'</p>'
                .'<p style="font-family: sans-serif; margin: 4px 0;"><strong>Public Order ID:</strong> '.e($order->public_id).'</p>'
                .'<h3 style="font-family: sans-serif; margin: 16px 0 8px;">Order Items</h3>'
                .$orderItems
                .'<h3 style="font-family: sans-serif; margin: 16px 0 8px;">Customer Details</h3>'
                .'<p style="font-family: sans-serif; margin: 4px 0;"><strong>Name:</strong> '.e($customerName).'</p>'
                .'<p style="font-family: sans-serif; margin: 4px 0;"><strong>Email:</strong> '.e($customerEmail).'</p>'
                .'<p style="font-family: sans-serif; margin: 4px 0;"><strong>Phone:</strong> '.e($customerPhone).'</p>'
                .'<h3 style="font-family: sans-serif; margin: 16px 0 8px;">Payment Details</h3>'
                .'<p style="font-family: sans-serif; margin: 4px 0;"><strong>Amount:</strong> '.e($amount).'</p>'
                .'<p style="font-family: sans-serif; margin: 4px 0;"><strong>Status:</strong> '.e($status).'</p>'
                .'<p style="font-family: sans-serif; margin: 4px 0;"><strong>Payment Method:</strong> '.e($paymentMethod).'</p>'
                .'<p style="font-family: sans-serif; margin: 4px 0;"><strong>Transaction ID:</strong> '.e($transactionId).'</p>'
                .'<p style="font-family: sans-serif; margin: 4px 0;"><strong>Captured At:</strong> '.e($capturedAt).'</p>'
                .'<h3 style="font-family: sans-serif; margin: 16px 0 8px;">Action Required</h3>'
                .'<p style="font-family: sans-serif; margin: 4px 0;">Please proceed with invoice issuance and order processing.</p>';

            Log::info('OPS_PAYMENT_EMAIL_START', [
                'to' => $targetEmail,
                'payment_id' => $event->paymentId,
                'order_id' => $event->orderId,
                'title' => $title,
            ]);

            Mail::to($targetEmail)->queue(
                new GenericOpsNotificationMail(
                    title: $title,
                    body: $body,
                    isHtml: true,
                ),
            );

            Log::info('OPS_PAYMENT_EMAIL_QUEUED_OK', [
                'to' => $targetEmail,
                'payment_id' => $event->paymentId,
                'order_id' => $event->orderId,
            ]);
        } catch (\Throwable $e) {
            Log::error('OPS_PAYMENT_EMAIL_FAILED', [
                'payment_id' => $event->paymentId,
                'order_id' => $event->orderId,
                'to' => $targetEmail,
                'error' => $e->getMessage(),
                'class' => get_class($e),
            ]);
        }
    }

    /**
     * Dispatch an in-app + email notification to every admin user.
     *
     * Admin roles: super_admin, operations, sales, support.
     * Failures are logged and swallowed so admin noise never blocks
     * the customer-side path.
     */
    private function notifyAdmins(string $templateKey, BaseDomainEvent $event): void
    {
        try {
            $payload = $event->payload();

            /** @var list<User> $admins */
            $admins = User::query()
                ->whereHas('roles', static function ($q): void {
                    $q->whereIn('name', ['super_admin', 'operations', 'sales', 'support']);
                })
                ->where('is_active', true)
                ->get();

            if ($admins->isEmpty()) {
                return;
            }

            foreach ($admins as $admin) {
                $this->dispatcher->dispatch(
                    type: $templateKey,
                    recipient: $admin,
                    data: $payload,
                );
            }
        } catch (\Throwable $e) {
            Log::warning('notifications.admin.dispatch_failed', [
                'template' => $templateKey,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
