<?php

namespace App\Http\Controllers;

use App\Models\Share;
use App\Models\Transaction;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Exception;

class PaymentController extends Controller
{
    private PaymentService $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /**
     * Initiate payment for a share
     *
     * POST /api/shares/{id}/pay
     */
    public function initiatePayment(Request $request, $shareId)
    {
        // Validate request
        $validator = Validator::make($request->all(), [
            'payment_method' => 'required|in:gcash,paymaya',
        ], [
            'payment_method.required' => 'Payment method is required',
            'payment_method.in' => 'Payment method must be either gcash or paymaya',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Find the share
            $share = Share::with(['bill', 'user'])->findOrFail($shareId);

            // Verify share belongs to authenticated user
            $user = Auth::user();
            if ($share->user_id !== $user->id) {
                return response()->json([
                    'message' => 'You do not have permission to pay this share',
                    'error_code' => 'FORBIDDEN',
                ], 403);
            }

            // Verify share is unpaid
            if ($share->status === 'paid') {
                return response()->json([
                    'message' => 'This share has already been paid',
                    'error_code' => 'ALREADY_PAID',
                ], 422);
            }

            // Check if there's already a pending transaction
            $existingTransaction = Transaction::where('share_id', $shareId)
                ->where('status', 'pending')
                ->first();

            if ($existingTransaction) {
                return response()->json([
                    'message' => 'A payment is already in progress for this share',
                    'error_code' => 'PAYMENT_IN_PROGRESS',
                ], 422);
            }

            DB::beginTransaction();

            try {
                // Create payment intent with PayMongo
                $paymentMethod = $request->input('payment_method');
                $paymentIntentData = $this->paymentService->createPaymentIntent($share, $paymentMethod);

                // Create transaction record
                $transaction = Transaction::create([
                    'share_id' => $share->id,
                    'user_id' => $user->id,
                    'amount' => $share->amount,
                    'payment_method' => $paymentMethod,
                    'status' => 'pending',
                ]);

                DB::commit();

                return response()->json([
                    'message' => 'Payment initiated successfully',
                    'transaction' => [
                        'id' => $transaction->id,
                        'amount' => $transaction->amount,
                        'payment_method' => $transaction->payment_method,
                        'status' => $transaction->status,
                    ],
                    'payment_intent' => $paymentIntentData['payment_intent'],
                    'checkout_url' => $paymentIntentData['checkout_url'],
                ], 200);
            } catch (Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Failed to initiate payment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
