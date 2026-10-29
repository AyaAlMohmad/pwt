<?php

declare(strict_types=1);

namespace App\Services\Rentals;

use App\Enums\MachineRentalPaymentStatus;
use App\Enums\MachineRentalScheduleStatus;
use App\Enums\ProductTerm;
use App\Events\Rentals\MachineRentalPaymentCaptured;
use App\Events\Rentals\MachineRentalPaymentFailed;
use App\Models\Machine;
use App\Models\MachineRentalPayment;
use App\Models\MachineRentalSchedule;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentCard;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\PaymentIntent;
use Stripe\Stripe;

/**
 * Orchestration layer for machine rental recurring payments.
 *
 * Responsibilities:
 *   - Create a rental schedule when a rent machine is installed.
 *   - Send 5-day reminder notifications.
 *   - Process automatic Stripe off-session charges on the due date.
 *   - Advance the schedule on successful payment.
 */
final class MachineRentalPaymentService
{
    /**
     * Create (or return existing) rental schedule for a machine that
     * was just installed from a rent order.
     *
     * Idempotent on machine_id — second calls return the existing row.
     */
    public function createScheduleFromMachine(Machine $machine, OrderItem $item): ?MachineRentalSchedule
    {
        if ($machine->term !== ProductTerm::Rent) {
            return null;
        }

        $existing = MachineRentalSchedule::query()
            ->where('machine_id', $machine->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $order = $item->order;
        $paymentCardId = $this->resolvePaymentCardId($order);

        $frequency = $item->rent_period ?? 'monthly';
        $installedAt = $machine->installed_at instanceof Carbon
            ? $machine->installed_at->copy()->startOfDay()
            : Carbon::today();

        $nextDue = $this->advanceDate($installedAt, $frequency);

        return DB::transaction(function () use ($machine, $item, $paymentCardId, $frequency, $nextDue): MachineRentalSchedule {
            $schedule = MachineRentalSchedule::query()->create([
                'machine_id' => $machine->id,
                'user_id' => $machine->user_id,
                'payment_card_id' => $paymentCardId,
                'amount' => $item->unit_price,
                'currency' => $item->currency,
                'frequency' => $frequency,
                'next_payment_due_at' => $nextDue->toDateString(),
                'last_payment_at' => null,
                'reminder_sent_at' => null,
                'status' => MachineRentalScheduleStatus::Active->value,
            ]);

            Log::info('rental.schedule_created', [
                'schedule_id' => $schedule->id,
                'machine_id' => $machine->id,
                'user_id' => $machine->user_id,
                'amount' => $item->unit_price,
                'currency' => $item->currency,
                'frequency' => $frequency,
                'next_payment_due_at' => $schedule->next_payment_due_at->toDateString(),
            ]);

            return $schedule;
        });
    }

    /**
     * Find active schedules whose next payment is exactly $days away
     * and send a reminder notification + email to the tenant.
     */
    public function sendReminders(int $daysBefore = 5): int
    {
        $sent = 0;
        $threshold = now()->addDays($daysBefore)->toDateString();

        MachineRentalSchedule::query()
            ->with(['machine', 'user', 'paymentCard'])
            ->active()
            ->whereDate('next_payment_due_at', '<=', $threshold)
            ->whereDate('next_payment_due_at', '>=', now()->toDateString())
            ->where(function ($q): void {
                $q->whereNull('reminder_sent_at')
                    ->orWhereDate('reminder_sent_at', '<', now()->toDateString());
            })
            ->chunk(100, function ($schedules) use (&$sent): void {
                foreach ($schedules as $schedule) {
                    $this->dispatchReminder($schedule);
                    $schedule->update(['reminder_sent_at' => now()]);
                    $sent++;
                }
            });

        return $sent;
    }

    /**
     * Process all rental payments due today via Stripe off-session charge.
     */
    public function processDuePayments(): int
    {
        $processed = 0;

        MachineRentalSchedule::query()
            ->with(['machine', 'user', 'paymentCard'])
            ->active()
            ->whereDate('next_payment_due_at', now()->toDateString())
            ->chunk(100, function ($schedules) use (&$processed): void {
                foreach ($schedules as $schedule) {
                    $this->processSchedulePayment($schedule);
                    $processed++;
                }
            });

        return $processed;
    }

    /**
     * Charge a single schedule's due payment via Stripe.
     */
    public function processSchedulePayment(MachineRentalSchedule $schedule): void
    {
        $paymentCard = $schedule->paymentCard;

        if ($paymentCard === null || $paymentCard->stripe_payment_method_id === null || $paymentCard->stripe_payment_method_id === '') {
            Log::warning('rental.payment_skipped_no_card', [
                'schedule_id' => $schedule->id,
                'machine_id' => $schedule->machine_id,
            ]);

            $this->recordScheduleFailure($schedule, 'No saved payment card on file.');

            return;
        }

        $user = $schedule->user;
        if ($user === null || $user->stripe_customer_id === null || $user->stripe_customer_id === '') {
            Log::warning('rental.payment_skipped_no_customer', [
                'schedule_id' => $schedule->id,
                'machine_id' => $schedule->machine_id,
            ]);

            $this->recordScheduleFailure($schedule, 'Customer has no Stripe customer ID.');

            return;
        }

        $payment = MachineRentalPayment::query()->create([
            'machine_rental_schedule_id' => $schedule->id,
            'machine_id' => $schedule->machine_id,
            'user_id' => $schedule->user_id,
            'payment_card_id' => $paymentCard->id,
            'amount' => $schedule->amount,
            'currency' => $schedule->currency,
            'status' => MachineRentalPaymentStatus::Pending->value,
            'due_date' => $schedule->next_payment_due_at->toDateString(),
            'paid_at' => null,
            'stripe_payment_intent_id' => null,
            'failure_reason' => null,
        ]);

        try {
            $this->configureStripe();

            $amountCents = $this->toCents($schedule->amount, $schedule->currency);
            $currency = strtolower($schedule->currency);

            /** @var PaymentIntent $intent */
            $intent = PaymentIntent::create([
                'amount' => $amountCents,
                'currency' => $currency,
                'customer' => $user->stripe_customer_id,
                'payment_method' => $paymentCard->stripe_payment_method_id,
                'off_session' => true,
                'confirm' => true,
                'metadata' => [
                    'machine_rental_schedule_id' => $schedule->id,
                    'machine_rental_payment_id' => $payment->id,
                    'machine_id' => $schedule->machine_id,
                    'user_id' => $schedule->user_id,
                ],
            ]);

            if ($intent->status === 'succeeded') {
                $this->recordSuccess($schedule, $payment, $intent->id);
            } elseif (in_array($intent->status, ['requires_action', 'requires_source', 'requires_payment_method'], true)) {
                $this->recordFailure($schedule, $payment, 'Payment requires additional authentication. Please update your card.');
            } else {
                $this->recordFailure($schedule, $payment, 'Payment intent status: '.$intent->status);
            }
        } catch (\Stripe\Exception\CardException $e) {
            $this->recordFailure($schedule, $payment, $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('rental.payment_exception', [
                'schedule_id' => $schedule->id,
                'payment_id' => $payment->id,
                'exception' => $e->getMessage(),
            ]);
            $this->recordFailure($schedule, $payment, 'Unexpected error: '.$e->getMessage());
        }
    }

    /**
     * Customer-initiated retry for a failed (or skipped) rental payment.
     *
     * Accepts an optional different saved card. Returns a structured
     * result so the controller can map to HTTP 200 / 202 / 400.
     *
     * @return array<string, mixed>
     */
    public function retryPayment(
        MachineRentalSchedule $schedule,
        ?PaymentCard $overrideCard = null,
    ): array {
        $paymentCard = $overrideCard ?? $schedule->paymentCard;

        if ($paymentCard === null || $paymentCard->stripe_payment_method_id === null || $paymentCard->stripe_payment_method_id === '') {
            return [
                'status' => 'failed',
                'reason' => 'No saved payment card on file.',
                'customer_message' => $this->customerReasonFor('No saved payment card on file.'),
            ];
        }

        $user = $schedule->user;
        if ($user === null || $user->stripe_customer_id === null || $user->stripe_customer_id === '') {
            return [
                'status' => 'failed',
                'reason' => 'Customer has no Stripe customer ID.',
                'customer_message' => $this->customerReasonFor('Customer has no Stripe customer ID.'),
            ];
        }

        $payment = MachineRentalPayment::query()->create([
            'machine_rental_schedule_id' => $schedule->id,
            'machine_id' => $schedule->machine_id,
            'user_id' => $schedule->user_id,
            'payment_card_id' => $paymentCard->id,
            'amount' => $schedule->amount,
            'currency' => $schedule->currency,
            'status' => MachineRentalPaymentStatus::Pending->value,
            'due_date' => $schedule->next_payment_due_at->toDateString(),
            'paid_at' => null,
            'stripe_payment_intent_id' => null,
            'failure_reason' => null,
        ]);

        try {
            $this->configureStripe();

            $amountCents = $this->toCents($schedule->amount, $schedule->currency);
            $currency = strtolower($schedule->currency);

            /** @var PaymentIntent $intent */
            $intent = PaymentIntent::create([
                'amount' => $amountCents,
                'currency' => $currency,
                'customer' => $user->stripe_customer_id,
                'payment_method' => $paymentCard->stripe_payment_method_id,
                'off_session' => false, // on-session retry — customer is present
                'confirm' => true,
                'return_url' => rtrim(config('app.urlapp', ''), '/').'/rentals/payment-return',
                'metadata' => [
                    'machine_rental_schedule_id' => $schedule->id,
                    'machine_rental_payment_id' => $payment->id,
                    'machine_id' => $schedule->machine_id,
                    'user_id' => $schedule->user_id,
                    'source' => 'customer_retry',
                ],
            ]);

            if ($intent->status === 'succeeded') {
                $this->recordSuccess($schedule, $payment, $intent->id);

                return [
                    'status' => 'succeeded',
                    'payment_id' => $payment->id,
                    'amount' => $schedule->amount,
                    'currency' => $schedule->currency,
                    'next_due_date' => $schedule->fresh()->next_payment_due_at->toDateString(),
                ];
            }

            if ($intent->status === 'requires_action') {
                return [
                    'status' => 'requires_action',
                    'client_secret' => $intent->client_secret,
                    'payment_intent_id' => $intent->id,
                ];
            }

            $this->recordFailure($schedule, $payment, 'Payment intent status: ' . $intent->status);

            return [
                'status' => 'failed',
                'reason' => 'Payment intent status: ' . $intent->status,
                'customer_message' => $this->customerReasonFor('Payment intent status: ' . $intent->status),
            ];
        } catch (\Stripe\Exception\CardException $e) {
            $this->recordFailure($schedule, $payment, $e->getMessage());

            return [
                'status' => 'failed',
                'reason' => $e->getMessage(),
                'customer_message' => $this->customerReasonFor($e->getMessage()),
            ];
        } catch (\Throwable $e) {
            Log::error('rental.retry_payment_exception', [
                'schedule_id' => $schedule->id,
                'payment_id' => $payment->id,
                'exception' => $e->getMessage(),
            ]);
            $this->recordFailure($schedule, $payment, 'Unexpected error: ' . $e->getMessage());

            return [
                'status' => 'failed',
                'reason' => 'Unexpected error: ' . $e->getMessage(),
                'customer_message' => $this->customerReasonFor('Unexpected error: ' . $e->getMessage()),
            ];
        }
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function dispatchReminder(MachineRentalSchedule $schedule): void
    {
        $user = $schedule->user;
        $machine = $schedule->machine;
        $machineName = $machine instanceof \App\Models\Machine ? $machine->displayName() : (string) $schedule->machine_id;

        event(new \App\Events\Rentals\MachineRentalPaymentDue(
            scheduleId: $schedule->id,
            machineId: $schedule->machine_id,
            userId: $schedule->user_id,
            dueDate: $schedule->next_payment_due_at->toDateString(),
            customerName: $user?->name ?? '',
            customerEmail: $user?->email ?? '',
            machineName: $machineName,
        ));
    }

    private function recordSuccess(
        MachineRentalSchedule $schedule,
        MachineRentalPayment $payment,
        string $stripePaymentIntentId,
    ): void {
        DB::transaction(function () use ($schedule, $payment, $stripePaymentIntentId): void {
            $now = now();

            $payment->update([
                'status' => MachineRentalPaymentStatus::Paid->value,
                'paid_at' => $now,
                'stripe_payment_intent_id' => $stripePaymentIntentId,
            ]);

            $nextDue = $this->advanceDate(
                $schedule->next_payment_due_at->copy(),
                $schedule->frequency,
            );

            $schedule->update([
                'last_payment_at' => $schedule->next_payment_due_at->toDateString(),
                'next_payment_due_at' => $nextDue->toDateString(),
                'reminder_sent_at' => null,
            ]);
        });

        $context = $this->resolveCustomerContext($schedule);

        event(new MachineRentalPaymentCaptured(
            paymentId: $payment->id,
            scheduleId: $schedule->id,
            machineId: $schedule->machine_id,
            userId: $schedule->user_id,
            amount: $schedule->amount,
            currency: $schedule->currency,
            customerName: $context['customerName'],
            customerEmail: $context['customerEmail'],
            machineName: $context['machineName'],
        ));

        Log::info('rental.payment_succeeded', [
            'schedule_id' => $schedule->id,
            'payment_id' => $payment->id,
            'stripe_payment_intent_id' => $stripePaymentIntentId,
        ]);
    }

    private function recordScheduleFailure(
        MachineRentalSchedule $schedule,
        string $reason,
    ): void {
        $payment = MachineRentalPayment::query()->create([
            'machine_rental_schedule_id' => $schedule->id,
            'machine_id' => $schedule->machine_id,
            'user_id' => $schedule->user_id,
            'payment_card_id' => $schedule->payment_card_id,
            'amount' => $schedule->amount,
            'currency' => $schedule->currency,
            'status' => MachineRentalPaymentStatus::Failed->value,
            'due_date' => $schedule->next_payment_due_at->toDateString(),
            'failure_reason' => $reason,
        ]);

        $context = $this->resolveCustomerContext($schedule);

        event(new MachineRentalPaymentFailed(
            paymentId: $payment->id,
            scheduleId: $schedule->id,
            machineId: $schedule->machine_id,
            userId: $schedule->user_id,
            amount: $schedule->amount,
            currency: $schedule->currency,
            reason: $reason,
            customerReason: $this->customerReasonFor($reason),
            customerName: $context['customerName'],
            customerEmail: $context['customerEmail'],
            machineName: $context['machineName'],
        ));

        Log::warning('rental.payment_failed', [
            'schedule_id' => $schedule->id,
            'payment_id' => $payment->id,
            'reason' => $reason,
        ]);
    }

    private function recordFailure(
        MachineRentalSchedule $schedule,
        MachineRentalPayment $payment,
        string $reason,
    ): void {
        $payment->update([
            'status' => MachineRentalPaymentStatus::Failed->value,
            'failure_reason' => $reason,
        ]);

        $context = $this->resolveCustomerContext($schedule);

        event(new MachineRentalPaymentFailed(
            paymentId: $payment->id,
            scheduleId: $schedule->id,
            machineId: $schedule->machine_id,
            userId: $schedule->user_id,
            amount: $schedule->amount,
            currency: $schedule->currency,
            reason: $reason,
            customerReason: $this->customerReasonFor($reason),
            customerName: $context['customerName'],
            customerEmail: $context['customerEmail'],
            machineName: $context['machineName'],
        ));

        Log::warning('rental.payment_failed', [
            'schedule_id' => $schedule->id,
            'payment_id' => $payment->id,
            'reason' => $reason,
        ]);
    }

    private function advanceDate(Carbon $from, string $frequency): Carbon
    {
        return match ($frequency) {
            'yearly' => $from->copy()->addYear(),
            default => $from->copy()->addMonth(),
        };
    }

    private function resolvePaymentCardId(?Order $order): ?int
    {
        if ($order === null) {
            return null;
        }

        $latestPayment = $order->latestPayment();
        if ($latestPayment !== null && $latestPayment->payment_card_id !== null) {
            return $latestPayment->payment_card_id;
        }

        $defaultCard = PaymentCard::query()
            ->where('user_id', $order->user_id)
            ->where('is_default', true)
            ->whereNull('deleted_at')
            ->first();

        return $defaultCard?->id;
    }

    private function configureStripe(): void
    {
        $secretKey = (string) Setting::get(
            'payment.stripe.secret_key',
            config('payment.stripe.secret_key', '')
        );

        if ($secretKey === '') {
            throw new \RuntimeException('Stripe secret key is not configured.');
        }

        Stripe::setApiKey($secretKey);
    }

    private function toCents(string|float $amount, string $currency): int
    {
        $zeroDecimal = ['bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf'];
        $value = (float) $amount;

        if (in_array(strtolower($currency), $zeroDecimal, true)) {
            return (int) round($value);
        }

        return (int) round($value * 100);
    }

    /**
     * Resolve customer name, email and machine display name for event payloads.
     *
     * @return array{customerName: string, customerEmail: string, machineName: string}
     */
    private function resolveCustomerContext(MachineRentalSchedule $schedule): array
    {
        $user = $schedule->user;
        $machine = $schedule->machine;

        return [
            'customerName' => $user?->name ?? '',
            'customerEmail' => $user?->email ?? '',
            'machineName' => $machine instanceof \App\Models\Machine
                ? $machine->displayName()
                : (string) $schedule->machine_id,
        ];
    }

    /**
     * Map an internal technical failure reason to a safe customer-facing
     * short explanation. Aligns with the 7 failure cases in Phase 2 spec.
     */
    private function customerReasonFor(string $reason): string
    {
        if (str_contains($reason, 'No saved payment card')) {
            return 'no payment card is saved on your account';
        }

        if (str_contains($reason, 'Stripe customer ID')) {
            return 'an account processing issue occurred';
        }

        if (str_contains($reason, 'requires additional authentication')) {
            return 'your bank requires additional verification';
        }

        if (str_contains($reason, 'requires_action')) {
            return 'additional verification is required by your bank';
        }

        if (str_contains($reason, 'requires_payment_method')) {
            return 'your saved card could not be charged';
        }

        if (str_contains($reason, 'Stripe\Exception\CardException') || str_contains($reason, 'card was declined') || str_contains($reason, 'insufficient funds') || str_contains($reason, 'expired') || str_contains($reason, 'CVC')) {
            return 'your saved card could not be processed';
        }

        return 'a temporary technical issue occurred';
    }
}
