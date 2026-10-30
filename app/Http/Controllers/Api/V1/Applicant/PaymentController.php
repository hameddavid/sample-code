<?php

namespace App\Http\Controllers\Api\V1\Applicant;

use App\Http\Controllers\Controller;
use App\Services\PaymentService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(protected PaymentService $paymentService) {}

    public function initiatePayment(Request $request)
    {
        $applicant = $request->user();
        $destination = strtoupper($request->destination);
        $amount = $request->amount;
        $type = strtoupper($request->input('type', 'OFFICIAL'));
        $gateway = strtoupper($request->input('gateway', 'INTERSWITCH'));

        if (!$this->paymentService->confirmAmount($amount, $type)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid amount for the selected application type.',
            ], 422);
        }

        if ($gateway === 'INTERSWITCH') {
            return $this->initiateInterswitchPayment($applicant, $destination, $amount);
        }

        return $this->initiateRemitaPayment($applicant, $destination, $amount);
    }

    protected function initiateInterswitchPayment($applicant, string $destination, string $amount)
    {
        $pending = $this->paymentService->checkPendingRRR(
            $applicant->matric_number, $destination, 'INTERSWITCH', $applicant->id
        );

        if ($pending['has_pending']) {
            $config = $this->paymentService->getInterswitchConfig($destination, $pending['order_id']);
            $paymentData = $this->paymentService->generateInterswitchPaymentData($config, $amount, $applicant);
            $paymentData['is_pending'] = true;
            $paymentData['is_paid'] = $pending['is_paid'] ?? false;
            return response()->json(['status' => 'success', 'data' => $paymentData]);
        }

        $config = $this->paymentService->getGatewayConfig($destination, 'INTERSWITCH');
        $paymentData = $this->paymentService->generateInterswitchPaymentData($config, $amount, $applicant);

        $this->paymentService->logTransaction([
            'userid' => $applicant->id,
            'matno' => $applicant->matric_number,
            'email' => $applicant->email,
            'names' => "{$applicant->surname} {$applicant->firstname}",
            'gateway' => 'INTERSWITCH',
            'destination' => $destination,
            'rrr' => $config['orderID'],
            'orderID' => $config['orderID'],
            'amount' => $amount,
            'statuscode' => '025',
            'statusMsg' => 'pending',
        ]);

        return response()->json(['status' => 'success', 'data' => $paymentData]);
    }

    protected function initiateRemitaPayment($applicant, string $destination, string $amount)
    {
        $pending = $this->paymentService->checkPendingRRR(
            $applicant->matric_number, $destination, 'REMITA', $applicant->id
        );

        $merchantId = config('remita.merchant_id');
        $apiKey = config('remita.api_key');

        if ($pending['has_pending']) {
            $hash = hash('sha512', $merchantId . $pending['rrr'] . $apiKey);
            return response()->json([
                'status' => 'success',
                'data' => [
                    'rrr' => $pending['rrr'],
                    'merchant_id' => $merchantId,
                    'hash' => $hash,
                    'payment_url' => $this->paymentService->getRemitaPaymentUrl($pending['rrr']),
                    'is_pending' => true,
                    'is_paid' => $pending['is_paid'] ?? false,
                ],
            ]);
        }

        $config = $this->paymentService->getGatewayConfig($destination, 'REMITA');
        $rrrResult = $this->paymentService->generateRRR($config, $amount, $applicant);

        $this->paymentService->logTransaction([
            'userid' => $applicant->id,
            'matno' => $applicant->matric_number,
            'email' => $applicant->email,
            'names' => "{$applicant->surname} {$applicant->firstname}",
            'gateway' => 'REMITA',
            'destination' => $destination,
            'rrr' => $rrrResult['rrr'],
            'orderID' => $config['orderID'],
            'amount' => $amount,
            'statuscode' => '025',
            'statusMsg' => 'pending',
        ]);

        $hash = hash('sha512', $merchantId . $rrrResult['rrr'] . $apiKey);
        return response()->json([
            'status' => 'success',
            'data' => [
                'rrr' => $rrrResult['rrr'],
                'merchant_id' => $merchantId,
                'hash' => $hash,
                'payment_url' => $this->paymentService->getRemitaPaymentUrl($rrrResult['rrr']),
            ],
        ]);
    }

    public function verifyPayment(Request $request)
    {
        $gateway = strtoupper($request->input('gateway', 'INTERSWITCH'));

        if ($gateway === 'INTERSWITCH') {
            $request->validate([
                'txn_ref' => 'required|string',
                'amount' => 'required|numeric',
            ]);
            $result = $this->paymentService->verifyInterswitchTransaction($request->txn_ref, $request->amount);

            return response()->json([
                'status' => $result['success'] ? 'success' : 'pending',
                'message' => $result['success'] ? 'Payment verified successfully.' : 'Payment is still pending.',
                'data' => $result['data'],
            ]);
        }

        $request->validate(['rrr' => 'required|string']);
        $result = $this->paymentService->reQueryTransaction($request->rrr);

        return response()->json([
            'status' => ($result['pending'] ?? false) ? 'pending' : 'success',
            'message' => ($result['pending'] ?? false) ? 'Payment is still pending.' : 'Payment verified successfully.',
        ]);
    }

    public function interswitchCallback(Request $request)
    {
        $signature = $request->header('X-Interswitch-Signature');

        if ($signature) {
            if (!$this->paymentService->verifyInterswitchWebhookSignature($request->getContent(), $signature)) {
                \Log::warning('Interswitch webhook: invalid signature');
                return response()->json(['status' => 'error', 'message' => 'Invalid signature'], 401);
            }

            $result = $this->paymentService->processInterswitchWebhook($request->all());
            return response()->json($result);
        }

        $txnRef = $request->input('txnref') ?? $request->input('txn_ref');
        $amount = $request->input('amount');

        if (!$txnRef) {
            return response()->json(['status' => 'error', 'message' => 'No transaction reference provided'], 400);
        }

        try {
            $result = $this->paymentService->verifyInterswitchTransaction($txnRef, $amount ?? '0');
            return response()->json([
                'status' => $result['success'] ? 'success' : 'failed',
                'message' => $result['success'] ? 'Payment verified' : 'Payment verification failed',
            ]);
        } catch (\Exception $e) {
            \Log::error('Interswitch callback failed', ['txnRef' => $txnRef, 'error' => $e->getMessage()]);
            return response()->json(['status' => 'error', 'message' => 'Verification failed'], 500);
        }
    }

    public function remitaNotification(Request $request)
    {
        $rrr = $request->input('rrr') ?? $request->input('RRR');

        if (!$rrr) {
            return response()->json(['status' => 'error', 'message' => 'No RRR provided'], 400);
        }

        try {
            $this->paymentService->reQueryTransaction($rrr);
        } catch (\Exception $e) {
            \Log::error('Remita notification failed', ['rrr' => $rrr, 'error' => $e->getMessage()]);
        }

        return response()->json(['status' => 'success']);
    }
}
