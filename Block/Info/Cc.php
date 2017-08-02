<?php

namespace Due\Payments\Block\Info;

use Magento\Framework\View\Element\Template;
use Magento\Sales\Api\TransactionRepositoryInterface;

class Cc extends \Magento\Payment\Block\Info\Cc
{
    /**
     * @var string
     */
    protected $_template = 'Due_Payments::info/cc.phtml';

    /**
     * @var TransactionRepositoryInterface
     */
    protected $transactionRepository;

    /**
     * Constructor
     *
     * @param TransactionRepositoryInterface $transactionRepository
     * @param Template\Context $context
     * @param \Magento\Payment\Model\Config $paymentConfig
     * @param array $data
     */
    public function __construct(
        TransactionRepositoryInterface $transactionRepository,
        Template\Context $context,
        \Magento\Payment\Model\Config $paymentConfig,
        array $data = []
    ) {
    
        parent::__construct($context, $paymentConfig, $data);
        $this->transactionRepository = $transactionRepository;
    }

    /**
     * Render as PDF
     * @return string
     */
    public function toPdf()
    {
        $this->setTemplate('Due_Payments::info/pdf/cc.phtml');
        return $this->toHtml();
    }
}
