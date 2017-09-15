<?php

namespace Due\Payments\Helper;

use Due\Payments\Model\Method\Cc;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Model\PaymentTokenManagement;

/**
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class Data extends AbstractHelper
{
    protected static $_cardTypes = array(
        'VI' => 'VISA',
        'MC' => 'MasterCard',
        'DI' => 'Discover',
        'JCB' => 'JCB',
        'DN' => 'DinersClub',
        'AE' => 'American Express'
    );

    /**
     * @var \Magento\Framework\Encryption\EncryptorInterface
     */
    protected $_encryptor;

    /**
     * @var \Magento\Payment\Model\Config
     */
    protected $_config;

    /**
     * @var \Magento\Framework\Module\ModuleListInterface
     */
    protected $_moduleList;

    /**
     * @var \Magento\Sales\Model\Order\Config
     */
    protected $_orderConfig;

    /**
     * @var \PayEx\Px
     */
    protected $_px;

    /**
     * @var \Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory
     */
    protected $orderStatusCollectionFactory;

    /**
     * @var \Magento\Sales\Model\Service\InvoiceService
     */
    protected $invoiceService;

    /**
     * @var \Magento\Sales\Model\Order\Email\Sender\InvoiceSender
     */
    protected $invoiceSender;

    /**
     * @var \Magento\Tax\Helper\Data
     */
    protected $taxHelper;

    /**
     * @var \Magento\Framework\App\ProductMetadata
     */
    protected $productMetadata;

    /**
     * @var PaymentTokenManagement
     */
    private $tokenManagement;

    /**
     * Data constructor.
     * @param \Magento\Framework\App\Helper\Context $context
     * @param \Magento\Framework\Encryption\EncryptorInterface $encryptor
     * @param \Magento\Payment\Model\Config $config
     * @param \Magento\Framework\Module\ModuleListInterface $moduleList
     * @param \Magento\Sales\Model\Order\Config $orderConfig
     * @param \Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory $orderStatusCollectionFactory
     * @param \Magento\Sales\Model\Service\InvoiceService $invoiceService
     * @param \Magento\Sales\Model\Order\Email\Sender\InvoiceSender $invoiceSender
     * @param \Magento\Tax\Helper\Data $taxHelper
     * @param \Magento\Framework\App\ProductMetadata $productMetadata
     * @param PaymentTokenManagement $tokenManagement
     */
    public function __construct(
        \Magento\Framework\App\Helper\Context $context,
        \Magento\Framework\Encryption\EncryptorInterface $encryptor,
        \Magento\Payment\Model\Config $config,
        \Magento\Framework\Module\ModuleListInterface $moduleList,
        \Magento\Sales\Model\Order\Config $orderConfig,
        \Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory $orderStatusCollectionFactory,
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Sales\Model\Order\Email\Sender\InvoiceSender $invoiceSender,
        \Magento\Tax\Helper\Data $taxHelper,
        \Magento\Framework\App\ProductMetadata $productMetadata,
        PaymentTokenManagement $tokenManagement
    )
    {
        parent::__construct($context);
        $this->_encryptor = $encryptor;
        $this->_config = $config;
        $this->_moduleList = $moduleList;
        $this->_orderConfig = $orderConfig;

        $this->orderStatusCollectionFactory = $orderStatusCollectionFactory;
        $this->invoiceService = $invoiceService;
        $this->invoiceSender = $invoiceSender;

        $this->taxHelper = $taxHelper;
        $this->productMetadata = $productMetadata;

        $this->tokenManagement = $tokenManagement;
    }

    /**
     * Retrieve information from payment configuration
     * @param $field
     * @param $paymentMethodCode
     * @param $storeId
     * @param bool|false $flag
     * @return bool|mixed
     */
    public function getConfigData($field, $paymentMethodCode, $storeId, $flag = false)
    {
        $path = 'payment/' . $paymentMethodCode . '/' . $field;

        if (!$flag) {
            return $this->scopeConfig->getValue($path, \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
        } else {
            return $this->scopeConfig->isSetFlag($path, \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
        }
    }

    /**
     * Get Store
     * @param int|string|null|bool|\Magento\Store\Api\Data\StoreInterface $id [optional]
     * @return \Magento\Store\Api\Data\StoreInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getStore($id = null)
    {
        /** @var \Magento\Framework\ObjectManagerInterface $om */
        $om = \Magento\Framework\App\ObjectManager::getInstance();

        /** @var \Magento\Store\Model\StoreManagerInterface $manager */
        $manager = $om->get('Magento\Store\Model\StoreManagerInterface');
        return $manager->getStore($id);
    }

    /**
     * Get Visitor IP address
     * @return string
     */
    public function getRemoteAddr()
    {
        /** @var \Magento\Framework\ObjectManagerInterface $om */
        $om = \Magento\Framework\App\ObjectManager::getInstance();

        /** @var \Magento\Framework\HTTP\PhpEnvironment\RemoteAddress $ra */
        $ra = $om->get('Magento\Framework\HTTP\PhpEnvironment\RemoteAddress');

        return $ra->getRemoteAddress();
    }

    /**
     * Get Assigned Status
     * @param $status
     * @return \Magento\Framework\DataObject
     */
    public function getAssignedStatus($status) {
        $collection = $this->orderStatusCollectionFactory->create()->joinStates();
        $status = $collection->addAttributeToFilter('main_table.status', $status)->getFirstItem();
        return $status;
    }

    /**
     * Create Invoice
     * @param \Magento\Sales\Model\Order $order
     * @param array $qtys
     * @param bool $online
     * @param string $comment
     * @return \Magento\Sales\Model\Order\Invoice
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function makeInvoice(\Magento\Sales\Model\Order $order, array $qtys = [], $online = false, $comment = '')
    {
        /** @var \Magento\Framework\ObjectManagerInterface $om */
        $om = \Magento\Framework\App\ObjectManager::getInstance();

        /** @var \Magento\Sales\Model\Order\Invoice $invoice */
        $invoice = $this->invoiceService->prepareInvoice($order, $qtys);
        $invoice->setRequestedCaptureCase($online ? \Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE : \Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE);

        // Add Comment
        if (!empty($comment)) {
            $invoice->addComment(
                $comment,
                true,
                true
            );

            $invoice->setCustomerNote($comment);
            $invoice->setCustomerNoteNotify(true);
        }

        $invoice->register();
        $invoice->getOrder()->setIsInProcess(true);

        /** @var \Magento\Framework\DB\Transaction $transactionSave */
        $transactionSave = $om->create(
            'Magento\Framework\DB\Transaction'
        )
            ->addObject($invoice)
            ->addObject($invoice->getOrder());
        $transactionSave->save();

        // send invoice emails
        try {
            $this->invoiceSender->send($invoice);
        } catch (\Exception $e) {
            $om->get('Psr\Log\LoggerInterface')->critical($e);
        }

        $invoice->setIsPaid(true);

        // Assign Last Transaction Id with Invoice
        $transactionId = $invoice->getOrder()->getPayment()->getLastTransId();
        if ($transactionId) {
            $invoice->setTransactionId($transactionId);
            $invoice->save();
        }

        return $invoice;
    }

    /**
     * Get Payment Tokens
     * @param $customer_id
     *
     * @return PaymentTokenInterface[]
     */
    public function getPaymentTokens($customer_id)
    {
        $tokens = $this->tokenManagement->getVisibleAvailableTokens($customer_id);
        foreach ($tokens as $id => $token) {
            if ($token->getPaymentMethodCode() !== Cc::METHOD_CODE) {
                unset($tokens[$id]);
            }
        }

        return $tokens;
    }

    /**
     * Get Payment Token by Id
     * @param $id
     * @param $customer_id
     *
     * @return bool|PaymentTokenInterface
     */
    public function getPaymentTokenById($id, $customer_id)
    {
        $tokens = $this->tokenManagement->getVisibleAvailableTokens($customer_id);
        foreach ($tokens as $id => $token) {
            if ($token->getPaymentMethodCode() !== Cc::METHOD_CODE) {
                continue;
            }

            if ($token->getEntityId() == $id) {
                return $token;
            }
        }

        return false;
    }

    /**
     * Get Formatted Payment Tokens
     * @param $customer_id
     *
     * @return array
     */
    public function getFormattedPaymentTokens($customer_id)
    {
        $cards = [];
        $tokens = $this->getPaymentTokens($customer_id);
        foreach ($tokens as $token) {
            $cards[] = [
                'id' => $token->getEntityId(),
                //'token' => $token->getGatewayToken(),
                'title' => $this->renderTokenHtml($token)
            ];
        }

        return $cards;
    }

    /**
     * @param PaymentTokenInterface $token
     *
     * @return string
     */
    protected function renderTokenHtml(PaymentTokenInterface $token)
    {
        $details = json_decode($token->getTokenDetails(), true);
        $type = isset(self::$_cardTypes[$details['cc_type']]) ? self::$_cardTypes[$details['cc_type']] : $details['cc_type'];
        $last_4 = $details['cc_last_4'];
        $exp_month = $details['cc_exp_month'];
        $exp_year = $details['cc_exp_year'];

        return (string)__('%1 ending in %2 %3/%4', $type, $last_4, $exp_month, $exp_year);
    }

}