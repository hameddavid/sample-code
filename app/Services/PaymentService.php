<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Payment4Degree;
use App\Models\Applicant;
use App\Models\AppSetting;
use App\Enums\TranscriptDestination;
use App\Enums\TranscriptType;
use App\Models\PaymentItem;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function checkPendingRRR(string $matno, string $destination, string $gateway, string $userId, string $mode = 'transcript'): array
    {
        $table = $mode === 'degree' ? 'degree_verification_payment_transaction' : 'payment_transaction';
        $userCol = $mode === 'degree' ? 'institution_email' : 'user_id';

        $baseQuery = DB::table($table)->where([
            $userCol => $userId,
            'destination' => $destination,
            'gateway' => $gateway,
        ]);

        if ($mode === 'transcript') {
            $baseQuery = $baseQuery->where('matric_number', $matno);
        }

        $pending = (clone $baseQuery)->where(['status_code' => '025', 'status_msg' => 'pending'])->first();

        if ($pending) {
            return [
                'has_pending' => true,
                'rrr' => $pending->rrr,
                'order_id' => $pending->trans_ref,
            ];
        }

        $unused = (clone $baseQuery)->where(['status_code' => '00', 'status_msg' => 'success'])->whereNull('app_id')->first();

        if ($unused) {
            return [
                'has_pending' => true,
                'is_paid' => true,
                'rrr' => $unused->rrr,
                'order_id' => $unused->trans_ref,
            ];
        }

        return [
            'has_pending' => false,
            'new_order_id' => $this->generateTransactionId(),
        ];
    }

    public function logTransaction(array $data, string $mode = 'transcript'): Payment|Payment4Degree
    {
        $destination = strtoupper($data['destination']);

        $model = $mode === 'degree' ? new Payment4Degree() : new Payment();
        $model->matric_number = $data['matno'] ?? '';
        $model->email = $data['email'] ?? '';
        $model->names = $data['names'] ?? '';
        $model->amount = $data['amount'];
        $model->rrr = $data['rrr'];
        $model->trans_ref = $data['orderID'];
        $model->destination = $destination;
        $model->gateway = strtoupper($data['gateway']);
        $model->status_code = '025';
        $model->status_msg = 'pending';
        $model->time_stamp = date('dmyHis');

        if ($mode === 'transcript') {
            $model->user_id = $data['userid'];
        } else {
            $model->institution_email = $data['institution_email'] ?? '';
            $model->institution_name = $data['institution_name'] ?? '';
        }

        $model->save();
        return $model;
    }

    public function getGatewayConfig(string $destination, string $gateway = 'INTERSWITCH', string $mode = 'transcript'): array
    {
        $orderID = $this->generateTransactionId();

        if (strtoupper($gateway) === 'INTERSWITCH') {
            return $this->getInterswitchConfig($destination, $orderID, $mode);
        }

        $merchantId = AppSetting::get('remita_merchant_id', config('remita.merchant_id'));
        $apiKey = AppSetting::get('remita_api_key', config('remita.api_key'));

        $destEnum = TranscriptDestination::tryFrom($destination);
        $serviceTypeId = $destEnum ? $destEnum->serviceTypeId() : AppSetting::get('remita_st_soft', config('remita.service_types.soft'));

        return [
            'gateway' => 'REMITA',
            'serviceTypeID' => $serviceTypeId,
            'merchantId' => $merchantId,
            'apiKey' => $apiKey,
            'orderID' => $orderID,
        ];
    }

    private function iswConfig(string $key, string $configFallback): string
    {
        return AppSetting::get("interswitch_{$key}", config("interswitch.{$configFallback}"));
    }

    public function getInterswitchConfig(string $destination, string $orderID, string $mode = 'transcript'): array
    {
        $merchantCode = $this->iswConfig('merchant_code', 'merchant_code');
        $payItemId = $this->iswConfig('pay_item_id', 'pay_item_id');
        $redirectUrl = $this->iswConfig('redirect_url', 'redirect_url');

        return [
            'gateway' => 'INTERSWITCH',
            'merchantCode' => $merchantCode,
            'payItemId' => $payItemId,
            'currencyCode' => config('interswitch.currency_code', '566'),
            'paymentUrl' => config('interswitch.payment_url'),
            'redirectUrl' => $redirectUrl,
            'orderID' => $orderID,
        ];
    }

    public function generateInterswitchHash(string $transRef, string $amount): string
    {
        $merchantCode = $this->iswConfig('merchant_code', 'merchant_code');
        $payItemId = $this->iswConfig('pay_item_id', 'pay_item_id');
        $redirectUrl = $this->iswConfig('redirect_url', 'redirect_url');
        $macKey = $this->iswConfig('mac_key', 'mac_key');

        return hash('sha512', $transRef . $merchantCode . $payItemId . $redirectUrl . $amount . $macKey);
    }

    public function generateInterswitchPaymentData(array $config, string $amount, Applicant $applicant): array
    {
        $transRef = $config['orderID'];
        $amountInKobo = (string) ((int) $amount * 100);

        $macKey = $this->iswConfig('mac_key', 'mac_key');
        $hash = hash('sha512', $transRef . $config['merchantCode'] . $config['payItemId'] . $config['redirectUrl'] . $amountInKobo . $macKey);

        return [
            'merchant_code' => $config['merchantCode'],
            'pay_item_id' => $config['payItemId'],
            'txn_ref' => $transRef,
            'amount' => $amountInKobo,
            'currency' => $config['currencyCode'],
            'site_redirect_url' => $config['redirectUrl'],
            'cust_name' => "{$applicant->surname} {$applicant->firstname}",
            'cust_email' => $applicant->email,
            'cust_id' => $applicant->matric_number,
            'pay_item_name' => 'Transcript Request Payment',
            'hash' => $hash,
            'payment_url' => $config['paymentUrl'],
            'mode' => $this->iswConfig('mode', 'mode'),
        ];
    }

    public function verifyInterswitchTransaction(string $transRef, string $amount): array
    {
        $merchantCode = config('interswitch.merchant_code');
        $amountInKobo = (string) ((int) $amount * 100);
        $hash = $this->generateInterswitchHash($transRef, $amountInKobo);

        $client = new Client();
        $response = $client->request('GET', config('interswitch.query_url'), [
            'query' => [
                'merchantcode' => $merchantCode,
                'transactionreference' => $transRef,
                'amount' => $amountInKobo,
            ],
            'headers' => [
                'Hash' => $hash,
            ],
        ]);

        $data = json_decode($response->getBody(), true);
        $responseCode = $data['ResponseCode'] ?? '';

        if ($responseCode === '00') {
            $this->updatePaymentByTransRef($transRef, $data['PaymentReference'] ?? $transRef);
            return ['success' => true, 'data' => $data];
        }

        return ['success' => false, 'pending' => true, 'data' => $data];
    }

    public function updatePaymentByTransRef(string $transRef, string $transactionId, string $mode = 'transcript'): array
    {
        $model = $mode === 'degree' ? Payment4Degree::class : Payment::class;
        $payment = $model::where('trans_ref', $transRef)->first();

        if (!$payment) {
            throw new \RuntimeException('No matching transaction reference found.');
        }

        if ($payment->status_code === '00' && $payment->status_msg === 'success') {
            return ['already_updated' => true];
        }

        if ($payment->status_code === '025') {
            $payment->p_gateway_transaction_id = $transactionId;
            $payment->status_code = '00';
            $payment->status_msg = 'success';
            $payment->save();
            return ['success' => true];
        }

        return ['already_updated' => true];
    }

    public function generateRRR(array $config, string $amount, Applicant $applicant): array
    {
        $merchantId = $config['merchantId'];
        $apiKey = $config['apiKey'];
        $serviceTypeId = $config['serviceTypeID'];
        $orderID = $config['orderID'];

        $apiHash = hash('sha512', $merchantId . $serviceTypeId . $orderID . $amount . $apiKey);

        $client = new Client();
        $response = $client->request('POST', config('remita.init_url'), [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => "remitaConsumerKey={$merchantId},remitaConsumerToken={$apiHash}",
            ],
            'json' => [
                'serviceTypeId' => $serviceTypeId,
                'amount' => $amount,
                'orderId' => $orderID,
                'payerName' => "{$applicant->surname} {$applicant->firstname}",
                'payerEmail' => $applicant->email,
                'payerPhone' => $applicant->mobile ?? '',
                'description' => 'Transcript Request Payment',
            ],
        ]);

        $body = (string) $response->getBody();
        $json = $body;
        if (str_starts_with($body, 'jsonp')) {
            $json = substr($body, strpos($body, '(') + 1, -1);
        }

        $data = json_decode($json, true);

        if (empty($data['RRR'])) {
            throw new \RuntimeException($data['statusMessage'] ?? 'Failed to generate RRR from Remita.');
        }

        return [
            'rrr' => $data['RRR'],
            'statusCode' => $data['statuscode'] ?? '',
            'statusMessage' => $data['statusMessage'] ?? '',
        ];
    }

    public function getRemitaPaymentUrl(string $rrr): string
    {
        $baseUrl = config('remita.base_url', 'https://remitademo.net/remita/ecomm');
        return "{$baseUrl}/finalize.reg?rrr={$rrr}";
    }

    public function updatePayment(string $rrr, string $transactionId, string $mode = 'transcript'): array
    {
        $model = $mode === 'degree' ? Payment4Degree::class : Payment::class;
        $payment = $model::where('rrr', $rrr)->first();

        if (!$payment) {
            throw new \RuntimeException('No matching RRR record found.');
        }

        if ($payment->status_code === '00' && $payment->status_msg === 'success') {
            return ['already_updated' => true];
        }

        if ($payment->status_code === '025') {
            $payment->p_gateway_transaction_id = $transactionId;
            $payment->status_code = '00';
            $payment->status_msg = 'success';
            $payment->save();
            return ['success' => true];
        }

        return ['already_updated' => true];
    }

    public function reQueryTransaction(string $rrr, string $mode = 'transcript'): array
    {
        $merchantId = AppSetting::get('remita_merchant_id', config('remita.merchant_id'));
        $apiKey = AppSetting::get('remita_api_key', config('remita.api_key'));
        $apiHash = hash('sha512', $rrr . $apiKey . $merchantId);

        $client = new Client();
        $response = $client->request('GET', config('remita.base_url') . "/{$merchantId}/{$rrr}/{$apiHash}/status.reg");
        $data = json_decode($response->getBody());

        if (trim($data->message ?? '') === 'Approved') {
            return $this->updatePayment($rrr, 'REQUERY', $mode);
        }

        return ['pending' => true, 'data' => $data];
    }

    public function processRemitaBankPayment(string $rawContent, string $mode = 'transcript'): array
    {
        $json = $rawContent;
        if (str_starts_with($json, 'jsonp')) {
            $json = substr($json, strpos($json, '(') + 1, -1);
        }

        $data = json_decode($json, true);
        if (!$data) {
            throw new \RuntimeException('Invalid Remita bank payment payload.');
        }

        $rrrValue = $data['rrr'] ?? $data['RRR'] ?? '';

        if (!$rrrValue) {
            throw new \RuntimeException('No RRR found in Remita bank payment payload.');
        }

        return $this->reQueryTransaction($rrrValue, $mode);
    }

    public function generateTransactionId(): string
    {
        return '14-' . date('YmdHis') . strtoupper(\Illuminate\Support\Str::random(8));
    }

    public function confirmAmount(string $amount, string $type): bool
    {
        $typeEnum = TranscriptType::tryFrom($type);
        if (!$typeEnum) return false;
        return (int) $amount === $typeEnum->amount();
    }

    public function getDestinationsAndAmounts(): array
    {
        $destinations = array_map(fn($dest) => [
            'id' => $dest->value,
            'name' => $dest->label(),
        ], array_values(TranscriptDestination::transcriptDestinations()));

        $pricing = array_map(fn($type) => [
            'type' => $type->value,
            'label' => $type->label(),
            'amount' => (string) $type->amount(),
        ], TranscriptType::cases());

        return [
            'destinations' => $destinations,
            'pricing' => $pricing,
        ];
    }

    public function verifyInterswitchWebhookSignature(string $payload, string $signature): bool
    {
        $secret = config('interswitch.webhook_secret');
        if (!$secret || !$signature) {
            return false;
        }
        $expected = hash_hmac('sha512', $payload, $secret);
        return hash_equals($expected, $signature);
    }

    public function processInterswitchWebhook(array $data): array
    {
        $txnRef = $data['txnref'] ?? $data['transactionReference'] ?? $data['trans_ref'] ?? null;
        if (!$txnRef) {
            return ['status' => 'error', 'message' => 'No transaction reference in webhook'];
        }

        $payment = Payment::where('trans_ref', $txnRef)->first()
            ?? Payment4Degree::where('trans_ref', $txnRef)->first();

        if (!$payment) {
            return ['status' => 'error', 'message' => 'Transaction not found'];
        }

        if ($payment->status_code === '00') {
            return ['status' => 'success', 'message' => 'Already processed'];
        }

        $mode = $payment instanceof Payment4Degree ? 'degree' : 'transcript';
        $amount = $payment->amount;

        try {
            $result = $this->verifyInterswitchTransaction($txnRef, $amount);
            return $result['success']
                ? ['status' => 'success', 'message' => 'Payment verified']
                : ['status' => 'pending', 'message' => 'Payment not confirmed'];
        } catch (\Exception $e) {
            \Log::error('Interswitch webhook verification failed', ['txnRef' => $txnRef, 'error' => $e->getMessage()]);
            return ['status' => 'error', 'message' => 'Verification failed'];
        }
    }
}
