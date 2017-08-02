<?php

namespace Due\Payments\Model\Method;

use Magento\Framework\DataObject;
use Magento\Payment\Model\Method\ConfigInterface;
use Magento\Payment\Model\Method\Online\GatewayInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Sales\Model\Order\Payment\Transaction;

use Magento\Sales\Api\Data\OrderPaymentExtensionInterfaceFactory;
use Magento\Sales\Model\Order\Payment;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Model\CreditCardTokenFactory;

class Cc extends \Magento\Payment\Model\Method\AbstractMethod implements GatewayInterface
{
    const METHOD_CODE = 'due_cc';
    const CC_DETAILS = 'cc_details';

    /**
     * Payment code
     *
     * @var string
     */
    protected $_code = self::METHOD_CODE;

    /**
     * @var string
     */
    //protected $_formBlockType = 'Due\Payments\Block\Form\Cc';
    protected $_infoBlockType = 'Due\Payments\Block\Info\Cc';

    /**
     * Payment Method feature
     *
     * @var bool
     */
    protected $_canAuthorize = true;
    protected $_canCapture = true;
    protected $_canCapturePartial = true;
    protected $_canCaptureOnce = true;
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;
    protected $_isInitializeNeeded = true;
    protected $_canVoid = true;
    protected $_canUseInternal = false;
    protected $_canFetchTransactionInfo = true;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var \Magento\Framework\UrlInterface
     */
    protected $urlBuilder;

    /**
     * @var \Magento\Framework\App\RequestInterface
     */
    protected $request;

    /**
     * @var \Magento\Payment\Model\Method\Logger
     */
    protected $logger;

    /**
     * @var \Due\Payments\Helper\Data
     */
    protected $helper;

    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $session;

    /**
     * @var \Magento\Customer\Model\Session
     */
    protected $customerSession;

    /**
     * @var CreditCardTokenFactory
     */
    private $paymentTokenFactory;

    /**
     * @var OrderPaymentExtensionInterfaceFactory
     */
    private $paymentExtensionFactory;

    /**
     * Constructor
     * @param \Magento\Framework\App\RequestInterface $request
     * @param \Magento\Framework\UrlInterface $urlBuilder
     * @param \Due\Payments\Helper\Data $helper
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Framework\Locale\ResolverInterface $resolver
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory
     * @param \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory
     * @param \Magento\Payment\Helper\Data $paymentData
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Payment\Model\Method\Logger $logger
     * @param \Magento\Checkout\Model\Session $session
     * @param \Magento\Customer\Model\Session $customerSession,
     * @param CreditCardTokenFactory $paymentTokenFactory
     * @param OrderPaymentExtensionInterfaceFactory $paymentExtensionFactory
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource|null $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb|null $resourceCollection
     * @param array $data
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        \Magento\Framework\App\RequestInterface $request,
        \Magento\Framework\UrlInterface $urlBuilder,
        \Due\Payments\Helper\Data $helper,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\Locale\ResolverInterface $resolver,
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        \Magento\Checkout\Model\Session $session,
        \Magento\Customer\Model\Session $customerSession,
        CreditCardTokenFactory $paymentTokenFactory,
        OrderPaymentExtensionInterfaceFactory $paymentExtensionFactory,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );

        $this->urlBuilder = $urlBuilder;
        $this->helper = $helper;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
        $this->request = $request;
        $this->session = $session;
        $this->customerSession = $customerSession;

        $this->paymentTokenFactory = $paymentTokenFactory;
        $this->paymentExtensionFactory = $paymentExtensionFactory;
    }

    /**
     * Assign data to info model instance
     *
     * @param DataObject|mixed $data
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function assignData(DataObject $data)
    {
        if (!$data instanceof DataObject) {
            $data = new DataObject($data);
        }

        $additionalData = $data->getData(PaymentInterface::KEY_ADDITIONAL_DATA);
        if (!is_object($additionalData)) {
            $additionalData = new DataObject($additionalData ?: []);
        }
        /** @var \Magento\Quote\Model\Quote\Payment $info */
        $info = $this->getInfoInstance();

        if ($vault = $additionalData->getVault()) {
            // Load vault
            $token = $this->helper->getPaymentTokenById($vault, $this->customerSession->getCustomerId());
            if (!$token) {
                throw new LocalizedException(__('Failed to get token'));
            }

            $due_token = $token->getGatewayToken();
            $details = json_decode($token->getTokenDetails(), true);
            $card = explode(':', $due_token);

            $additionalData->setCcDueToken($due_token)
                ->setCcType($details['cc_type'])
                ->setCcLast4($details['cc_last_4'])
                ->setCcExpMonth($details['cc_exp_month'])
                ->setCcExpYear($details['cc_exp_year'])
                ->setCcSaveCard(false);
        } else {
            $due_token =$additionalData->getCcDueToken();
            $card = explode(':', $due_token);
        }

        $info->setAdditionalInformation('cc_due_token', $additionalData->getCcDueToken())
            ->setAdditionalInformation('card_id', $card[0])
            ->setAdditionalInformation('card_hash', $card[1])
            ->setAdditionalInformation('risk_token', $card[2])
            ->setAdditionalInformation('save_card', $additionalData->getCcSaveCard())
            ->setAdditionalInformation(self::CC_DETAILS, [
                'cc_type' => $additionalData->getCcType(),
                'cc_last_4' => $additionalData->getCcLast4(),
                'cc_exp_month' => $additionalData->getCcExpMonth(),
                'cc_exp_year' => $additionalData->getCcExpYear(),
            ])
            ->setCcType($additionalData->getCcType())
            ->setCcLast4($additionalData->getCcLast4())
            ->setCcExpMonth($additionalData->getCcExpMonth())
            ->setCcExpYear($additionalData->getCcExpYear());

        return $this;
    }

    /**
     * Validate payment method information object
     *
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function validate()
    {
        parent::validate();

        /** @var \Magento\Quote\Model\Quote\Payment $info */
        $info = $this->getInfoInstance();

        /** @var \Magento\Quote\Model\Quote $quote */
        $quote = $info->getQuote();

        if (!$quote) {
            return $this;
        }

        return $this;
    }

    /**
     * Method that will be executed instead of authorize or capture
     * if flag isInitializeNeeded set to true
     *
     * @param string $paymentAction
     * @param object $stateObject
     *
     * @return $this
     * @throws LocalizedException
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @api
     */
    public function initialize($paymentAction, $stateObject)
    {
        /** @var \Magento\Quote\Model\Quote\Payment $info */
        $info = $this->getInfoInstance();

        /** @var \Magento\Sales\Model\Order $order */
        $order = $info->getOrder();

        // Init Due
        if ($this->getConfigData('sandbox') == '1') {
            \Due\Due::setEnvName('stage');
            \Due\Due::setApiKey($this->getConfigData('api_key_sandbox'));
            \Due\Due::setAppId($this->getConfigData('app_id_sandbox'));
        } else {
            \Due\Due::setEnvName('prod');
            \Due\Due::setApiKey($this->getConfigData('api_key'));
            \Due\Due::setAppId($this->getConfigData('app_id'));
        }

        // Do transaction
        try {
            $transaction = \Due\Charge::card([
                'amount' => $order->getGrandTotal(),
                'currency' => $order->getOrderCurrency()->getCurrencyCode(),
                'card_id' => $info->getAdditionalInformation('card_id'),
                'card_hash' => $info->getAdditionalInformation('card_hash'),
                'unique_id' => $order->getIncrementId(),
                'customer_ip' => $this->helper->getRemoteAddr()
            ]);
        } catch (\Exception $e) {
            throw new LocalizedException(__($e->getMessage()));
        }

        if ($transaction && $transaction->success) {
            $transaction_id = $transaction->id;
            $message = sprintf('Payment success. Transaction Id: %s', $transaction_id);
            $order->setCustomerNote($message);

            // Save CC in Vault
            if ($info->getAdditionalInformation('save_card')) {
                $token = $info->getAdditionalInformation('cc_due_token');
                $this->createPaymentToken($order->getPayment(), $token);
                $info->unsAdditionalInformation('cc_due_token');
            }

            // Save Order
            $order->save();

            // Register Transaction
            $order->getPayment()
                ->setTransactionId($transaction_id)
                ->setLastTransId($transaction_id)
                ->save()
                ->addTransaction(Transaction::TYPE_PAYMENT, null, false)
                ->setAdditionalInformation(Transaction::RAW_DETAILS, (array)$transaction)
                ->save();

            // Create Invoice for Sale Transaction
            $invoice = $this->helper->makeInvoice($order, [], false, $message);
            $invoice->setTransactionId($transaction_id);
            $invoice->save();

            /** @var \Magento\Sales\Model\Order\Status $status */
            $status = $this->helper->getAssignedStatus(\Magento\Sales\Model\Order::STATE_PROCESSING);

            // Set state object
            $stateObject->setState($status->getState());
            $stateObject->setStatus($status->getStatus());
            $stateObject->setIsNotified(true);
        } elseif ($transaction && $transaction->error_message) {
            // Payment failed
            $message = sprintf('Payment failed. Details: %s', $transaction->error_message);

            // Set state object
            /** @var \Magento\Sales\Model\Order\Status $status */
            $status = $this->helper->getAssignedStatus(\Magento\Sales\Model\Order::STATE_CANCELED);
            $stateObject->setState($status->getState());
            $stateObject->setStatus($status->getStatus());
            $stateObject->setIsNotified(true);

            throw new LocalizedException(__($message));
        } else {
            $message = 'Failed to perform payment';

            // Set state object
            /** @var \Magento\Sales\Model\Order\Status $status */
            $status = $this->helper->getAssignedStatus(\Magento\Sales\Model\Order::STATE_CANCELED);
            $stateObject->setState($status->getState());
            $stateObject->setStatus($status->getStatus());
            $stateObject->setIsNotified(true);

            throw new LocalizedException(__($message));
        }

        return $this;
    }

    /**
     * Refund specified amount for payment
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param float $amount
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     * @api
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        //parent::refund($payment, $amount);

        if ($amount <= 0) {
            throw new LocalizedException(__('Invalid amount for refund.'));
        }

        if (!$payment->getLastTransId()) {
            throw new LocalizedException(__('Invalid transaction ID.'));
        }

        // Load transaction Data
        $transactionId = $payment->getParentTransactionId();

        // Init Due
        if ($this->getConfigData('sandbox') == '1') {
            \Due\Due::setEnvName('stage');
            \Due\Due::setApiKey($this->getConfigData('api_key_sandbox'));
            \Due\Due::setAppId($this->getConfigData('app_id_sandbox'));
        } else {
            \Due\Due::setEnvName('prod');
            \Due\Due::setApiKey($this->getConfigData('api_key'));
            \Due\Due::setAppId($this->getConfigData('app_id'));
        }

        // Do refund
        try {
            $transaction = \Due\Refund::doCardRefund([
                'customer_ip' => $this->helper->getRemoteAddr(),
                'amount' => $amount,
                'transaction_id' => $transactionId,
                'meta' => [
                    'order_number' => $payment->getOrder()->getIncrementId(),
                    'refund_reason' => 'Refund from Magento admin'
                ]
            ]);
        } catch (\Exception $e) {
            throw new LocalizedException(__($e->getMessage()));
        }

        if ($transaction && $transaction->status === 'refunded') {
            // Add Credit Transaction
            $payment->setAnetTransType(\Magento\Sales\Model\Order\Payment\Transaction::TYPE_REFUND);
            $payment->setAmount($amount);

            $payment->setStatus(self::STATUS_APPROVED)
                ->setTransactionId($transaction->id)
                ->setIsTransactionClosed(1);

            return $this;
        } elseif ($transaction && $transaction->error_message) {
            // Payment failed
            $message = sprintf('Refund failed. Details: %s', $transaction->error_message);
            throw new LocalizedException(__($message));
        } else {
            // Payment failed
            $message = 'Refund failed';
            throw new LocalizedException(__($message));
        }
    }

    /**
     * Post request to gateway and return response
     *
     * @param DataObject $request
     * @param ConfigInterface $config
     *
     * @return DataObject
     *
     * @throws \Exception
     */
    public function postRequest(DataObject $request, ConfigInterface $config)
    {
        // Implement postRequest() method.
        return $request;
    }

    /**
     * @param Payment $payment
     * @param string $token
     * @throws LocalizedException
     * @return void
     */
    protected function createPaymentToken(Payment $payment, $token)
    {
        /** @var PaymentTokenInterface $paymentToken */
        $paymentToken = $this->paymentTokenFactory->create();
        $paymentToken->setPaymentMethodCode(self::METHOD_CODE)
            ->setGatewayToken($token)
            ->setTokenDetails(json_encode($payment->getAdditionalInformation(self::CC_DETAILS)))
            ->setExpiresAt($this->getExpirationDate($payment))
            ->setCustomerId($this->customerSession->getCustomerId())
            ->setIsActive(true)
            ->setIsVisible(true);

        $this->getPaymentExtensionAttributes($payment)->setVaultPaymentToken($paymentToken);
    }

    /**
     * @param Payment $payment
     * @return string
     */
    private function getExpirationDate(Payment $payment)
    {
        $expDate = new \DateTime(
            $payment->getCcExpYear()
            . '-'
            . $payment->getCcExpMonth()
            . '-'
            . '01'
            . ' '
            . '00:00:00',
            new \DateTimeZone('UTC')
        );
        $expDate->add(new \DateInterval('P1M'));
        return $expDate->format('Y-m-d 00:00:00');
    }

    /**
     * @param Payment $payment
     * @return \Magento\Sales\Api\Data\OrderPaymentExtensionInterface
     */
    private function getPaymentExtensionAttributes(Payment $payment)
    {
        $extensionAttributes = $payment->getExtensionAttributes();
        if ($extensionAttributes === null) {
            $extensionAttributes = $this->paymentExtensionFactory->create();
            $payment->setExtensionAttributes($extensionAttributes);
        }

        return $extensionAttributes;
    }
}
