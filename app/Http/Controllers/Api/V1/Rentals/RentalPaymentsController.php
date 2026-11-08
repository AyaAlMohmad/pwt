<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Rentals;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Rentals\RetryRentalPaymentRequest;
use App\Models\MachineRentalSchedule;
use App\Models\PaymentCard;
use App\Models\User;
use App\Services\Rentals\MachineRentalPaymentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

/**
 * Customer-facing rental-payment retry controller.
 *
 * Allows a tenant to retry a failed (or skipped) rental payment
 * manually — either with the same saved card or with a different
 * saved card. The schedule is scoped to the authenticated user.
 */
final class RentalPaymentsController extends Controller
{
    public function __construct(
        private readonly MachineRentalPaymentService $rentalService,
    ) {}

    #[OA\Post(
        path: '/api/v1/rentals/{machine}/retry-payment',
        operationId: 'rentalRetryPayment',
        description: 'Retry a failed or skipped rental payment for a rented machine. The customer may optionally supply a different saved payment_card_id; when omitted the schedule default card is used. If the card requires 3-D Secure / SCA, a client_secret is returned for the frontend to confirm on-session.',
        summary: 'Retry rental payment',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'payment_card_id', type: 'integer', nullable: true, description: 'Optional saved card ID belonging to the customer'),
                ],
            ),
        ),
        tags: ['Rentals'],
        parameters: [
            new OA\Parameter(name: 'machine', in: 'path', required: true, schema: new OA\Schema(type: 'integer'), description: 'Machine ID (the rented machine)'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Payment succeeded', content: new OA\JsonContent(ref: '#/components/schemas/RentalPaymentRetryResponse')),
            new OA\Response(response: 202, description: 'Payment requires SCA confirmation', content: new OA\JsonContent(ref: '#/components/schemas/RentalPaymentScaResponse')),
            new OA\Response(response: 400, description: 'Payment failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Not owner of this schedule', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Schedule not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'Rental installment already paid', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limited', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function retry(
        RetryRentalPaymentRequest $request,
        int $machine,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        /** @var MachineRentalSchedule|null $scheduleModel */
        $scheduleModel = MachineRentalSchedule::query()
            ->where('machine_id', $machine)
            ->where('user_id', $user->id)
            ->active()
            ->first();

        if ($scheduleModel === null) {
            return response()->json([
                'success' => false,
                'message' => __('rentals.schedule_not_found'),
                'code' => 'ERR_NOT_FOUND',
            ], 404);
        }

        $cardId = $request->validated('payment_card_id');
        $paymentCard = null;

        if ($cardId !== null) {
            /** @var PaymentCard|null $paymentCard */
            $paymentCard = PaymentCard::query()
                ->where('id', $cardId)
                ->where('user_id', $user->id)
                ->whereNull('deleted_at')
                ->first();

            if ($paymentCard === null) {
                return response()->json([
                    'success' => false,
                    'message' => __('rentals.invalid_payment_card'),
                    'code' => 'ERR_VALIDATION',
                ], 422);
            }
        }

        $result = $this->rentalService->retryPayment($scheduleModel, $paymentCard);

        if ($result['status'] === 'already_paid') {
            return response()->json([
                'success' => false,
                'message' => app()->getLocale() === 'ar' ? 'تم دفع هذه الدفعة مسبقًا.' : 'This payment has already been paid.',
                'code' => 'ERR_ALREADY_PAID',
                'data' => ['next_due_date' => $result['next_due_date']],
            ], 409);
        }

        if ($result['status'] === 'succeeded') {
            return response()->json([
                'success' => true,
                'message' => __('rentals.payment_retry_succeeded'),
                'data' => [
                    'payment_id' => $result['payment_id'],
                    'amount' => $result['amount'],
                    'currency' => $result['currency'],
                    'next_due_date' => $result['next_due_date'],
                ],
            ], 200);
        }

        if ($result['status'] === 'requires_action') {
            return response()->json([
                'success' => true,
                'message' => __('rentals.payment_requires_action'),
                'data' => [
                    'client_secret' => $result['client_secret'],
                    'payment_intent_id' => $result['payment_intent_id'],
                ],
            ], 202);
        }

        // failed
        return response()->json([
            'success' => false,
            'message' => $result['customer_message'],
            'data' => [
                'reason' => $result['reason'],
            ],
            'code' => 'ERR_PAYMENT_FAILED',
        ], 400);
    }
}
