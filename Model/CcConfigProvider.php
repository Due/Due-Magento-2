<?php

namespace Due\Payments\Model;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Payment\Helper\Data as PaymentHelper;
use Due\Payments\Model\Method\Cc;

class CcConfigProvider implements ConfigProviderInterface
{

    /**
     * @var \Magento\Framework\App\State
     */
    protected $_appState;

    /**
     * @var \Magento\Customer\Model\Session
     */
    protected $_customerSession;

    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $_checkoutSession;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $_storeManager;

    /**
     * @var PaymentHelper
     */
    protected $_paymentHelper;

    /**
     * @var \Magento\Framework\Locale\ResolverInterface
     */
    protected $_localeResolver;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface $config
     */
    protected $_config;

    /**
     * @var \Magento\Payment\Model\Method\AbstractMethod[]
     */
    protected $methods = [];

    /**
     * @var \Due\Payments\Helper\Data
     */
    protected $helper;

    /**
     * @param \Magento\Framework\Model\Context                   $context
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Magento\Store\Model\StoreManagerInterface         $storeManager
     * @param PaymentHelper                                      $paymentHelper
     * @param \Magento\Framework\Locale\ResolverInterface        $localeResolver
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $config
     * @param \Due\Payments\Helper\Data $helper
     */
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        PaymentHelper $paymentHelper,
        \Magento\Framework\Locale\ResolverInterface $localeResolver,
        \Magento\Framework\App\Config\ScopeConfigInterface $config,
        \Due\Payments\Helper\Data $helper
    ) {
        $this->_appState = $context->getAppState();
        $this->_customerSession = $customerSession;
        $this->_checkoutSession = $checkoutSession;
        $this->_storeManager = $storeManager;
        $this->_paymentHelper = $paymentHelper;
        $this->_localeResolver = $localeResolver;
        $this->_config = $config;
        $this->helper = $helper;
    }


    public function getConfig()
    {
        $store_id = $this->_storeManager->getStore()->getId();

        $saved_cards = [];
        if ($this->_customerSession->isLoggedIn()) {
            $saved_cards = $this->helper->getFormattedPaymentTokens($this->_customerSession->getCustomerId());
        }

        $config = [
            'payment' => [
                \Due\Payments\Model\Method\Cc::METHOD_CODE => [],
            ]
        ];

        /** @var \Due\Payments\Model\Method\Cc $method */
        $method = $this->_paymentHelper->getMethodInstance(Cc::METHOD_CODE);
        if ($method->isAvailable()) {
            $is_sandbox = (bool)$method->getConfigData('sandbox', $store_id);
            $app_id = $method->getConfigData($is_sandbox ? 'app_id_sandbox' : 'app_id', $store_id);
            $config['payment'][Cc::METHOD_CODE]['env'] = $is_sandbox ? 'stage' : 'prod';
            $config['payment'][Cc::METHOD_CODE]['app_id'] = $app_id;
            $config['payment'][Cc::METHOD_CODE]['vault_active'] = (bool)$method->getConfigData('vault_active', $store_id);
            $config['payment'][Cc::METHOD_CODE]['saved_cards'] = $saved_cards;
        }

        return $config;
    }
}
