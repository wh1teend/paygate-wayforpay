<?php


namespace WH1\PaygateWayForPay\Payment;

use XF;
use XF\Entity\PaymentProfile;
use XF\Entity\PurchaseRequest;
use XF\Http\Request;
use XF\Mvc\Controller;
use XF\Payment\AbstractProvider;
use XF\Payment\CallbackState;
use XF\Purchasable\Purchase;

class WayForPay extends AbstractProvider
{
	public function getTitle(): string
	{
		return 'WayForPay';
	}

	public function getApiEndpoint(): string
	{
		return 'https://secure.wayforpay.com/pay?behavior=offline';
	}

	public function verifyConfig(array &$options, &$errors = []): bool
	{
		if (empty($options['merchant_account']) || empty($options['secret_key']))
		{
			$errors[] = XF::phrase('wh1_pg_wayforpay_you_must_provide_all_data');
		}

		if (!$errors)
		{
			return true;
		}

		return false;
	}

	protected function getPaymentParams(PurchaseRequest $purchaseRequest, Purchase $purchase): array
	{
		$paymentProfileOptions = $purchase->paymentProfile->options;

		$paymentData = [
			'merchantAccount'    => $paymentProfileOptions['merchant_account'],
			'merchantDomainName' => parse_url(XF::options()->boardUrl, PHP_URL_HOST),

			'serviceUrl'         => $this->getCallbackUrl(),

			'orderReference' => $purchaseRequest->request_key,
			'orderDate'      => XF::$time,
			'amount'         => $purchase->cost,
			'currency'       => $purchase->currency,

			'productName'     => [
				$purchase->title
			],
			'productPrice'    => [
				$purchase->cost
			],
			'productCount'    => [
				1
			],
			'clientAccountId' => $purchase->extraData['email'] ?? XF::visitor()->email,
			'clientEmail'     => $purchase->extraData['email'] ?? XF::visitor()->email
		];

		$paymentData['merchantSignature'] = $this->getSignature($paymentData, $paymentProfileOptions['secret_key']);

		return $paymentData;
	}

	public function initiatePayment(Controller $controller, PurchaseRequest $purchaseRequest, Purchase $purchase): XF\Mvc\Reply\AbstractReply
	{
		$payment = $this->getPaymentParams($purchaseRequest, $purchase);

		$response = XF::app()->http()->client()->post($this->getApiEndpoint(), [
			'headers'     => [
				'Content-Type' => 'application/x-www-form-urlencoded'
			],
			'form_params' => $payment,
			'exceptions'  => false
		]);

		if ($response)
		{
			$responseData = json_decode($response->getBody()->getContents(), true);
			if (!empty($responseData['reason']))
			{
				return $controller->error($responseData['reason']);
			}

			if (!empty($responseData['url']))
			{
				return $controller->redirect($responseData['url']);
			}


		}

		return $controller->error(XF::phrase('something_went_wrong_please_try_again'));
	}

	public function setupCallback(Request $request): CallbackState
	{
		$state = new CallbackState();

		$jsonArray = json_decode($request->getInputRaw(), true) ?? [];

		$state->input = $request->getInputFilterer()->filterArray(['bill' => $jsonArray], [
			'bill' => 'array'
		]);

		$state->transactionId = $state->input['bill']['orderReference'] ?? null;
		$state->subscriberId = $state->input['bill']['email'] ?? null;
		$state->requestKey = $state->input['bill']['orderReference'] ?? null;
		$state->status = $state->input['bill']['transactionStatus'] ?? null;

		$state->costAmount = $state->input['bill']['amount'] ?? null;
		$state->costCurrency = $state->input['bill']['currency'] ?? null;

		$state->signature = $state->input['bill']['merchantSignature'] ?? null;

		$state->ip = $request->getIp();

		$state->rawInput = $request->getInputRaw();

		$state->httpCode = 200;

		return $state;
	}

	public function validateCallback(CallbackState $state): bool
	{
		if ($state->status != 'Approved')
		{
			$state->logType = '';
			$state->logMessage = '';

			return false;
		}

		if (!$state->transactionId || !$state->requestKey)
		{
			$state->logType = 'info';
			$state->logMessage = 'No Transaction ID or Request Key. No action to take.';

			return false;
		}

		return parent::validateCallback($state);
	}

	public function validateTransaction(CallbackState $state): bool
	{
		if ($state->transactionId && $state->requestKey)
		{
			$paymentRepo = \XF::repository('XF:Payment');
			if ($paymentRepo->findLogsByTransactionIdForProvider($state->transactionId, $this->providerId)->total())
			{
				$state->logType = '';
				$state->logMessage = '';
				return false;
			}
			return true;
		}

		$state->logType = 'error';
		$state->logMessage = 'No transaction ID or signature. No action to take.';

		return false;
	}

	public function validatePurchasableData(CallbackState $state): bool
	{
		$paymentProfile = $state->getPaymentProfile();

		$options = $paymentProfile->options;
		if (!empty($options['secret_key']))
		{
			if ($this->checkSignature($state->input['bill'], $options['secret_key'], $state->signature))
			{
				return true;
			}

			$state->logType = 'error';
			$state->logMessage = "Invalid signature.";

			return false;
		}

		$state->logType = 'error';
		$state->logMessage = 'Invalid secret_key.';

		return false;
	}

	public function validateCost(CallbackState $state): bool
	{
		$purchaseRequest = $state->getPurchaseRequest();

		if (round($state->costAmount, 2) == round($purchaseRequest->cost_amount, 2)
			&& $state->costCurrency == $purchaseRequest->cost_currency)
		{
			return true;
		}

		$state->logType = 'error';
		$state->logMessage = 'Invalid cost amount or cost currency';

		return false;
	}

	public function getPaymentResult(CallbackState $state): void
	{
		if ($state->status == 'Approved')
		{
			$state->paymentResult = CallbackState::PAYMENT_RECEIVED;
		}
	}

	public function completeTransaction(CallbackState $state): void
	{
		parent::completeTransaction($state);

		$paymentProfile = $state->getPaymentProfile();
		$profileOptions = $paymentProfile->options;

		$message = [
			'orderReference' => $state->requestKey,
			'status'         => 'accept',
			'time'           => XF::$time
		];

		$message['signature'] = hash_hmac('md5', implode(';', $message), $profileOptions['secret_key']);

		$state->logMessage = json_encode($message);
	}

	public function prepareLogData(CallbackState $state): void
	{
		$state->logDetails = array_merge($state->input, [
			'ip'           => $state->ip,
			'request_time' => XF::$time,
			'raw_input'    => $state->rawInput
		]);
	}

	protected $supportedCurrencies = [
		'RUB', 'USD', 'EUR', 'UAH'
	];

	public function supportsRecurring(PaymentProfile $paymentProfile, $unit, $amount, &$result = self::ERR_NO_RECURRING): bool
	{
		$result = self::ERR_NO_RECURRING;

		return false;
	}

	protected function getSupportedRecurrenceRanges(): array
	{
		return [];
	}

	public function verifyCurrency(PaymentProfile $paymentProfile, $currencyCode): bool
	{
		return in_array($currencyCode, $this->supportedCurrencies);
	}

	private function getSignature(array $paymentData, string $secretKey): string
	{
		$arrayString = 
		[
			$paymentData['merchantAccount'],
			$paymentData['merchantDomainName'],
			$paymentData['orderReference'],
			$paymentData['orderDate'],
			$paymentData['amount'],
			$paymentData['currency'],

			implode(';', $paymentData['productName']),
			implode(';', $paymentData['productCount']),
			implode(';', $paymentData['productPrice'])
		];

		return hash_hmac('md5', implode(';', $arrayString), $secretKey);
	}

	private function checkSignature(array $callbackData, string $secretKey, string $receivedSignature): bool
	{
		$newSignature = hash_hmac('md5', implode(';', 
		[
			$callbackData['merchantAccount'],
			$callbackData['orderReference'],
			$callbackData['amount'],
			$callbackData['currency'],
			$callbackData['authCode'],
			$callbackData['cardPan'],
			$callbackData['transactionStatus'],
			$callbackData['reasonCode']
		]), $secretKey);

		return hash_equals($receivedSignature, $newSignature);
	}
}
