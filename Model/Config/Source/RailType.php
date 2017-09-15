<?php

namespace Due\Payments\Model\Config\Source;

use Magento\Framework\Option\ArrayInterface;

class RailType implements ArrayInterface
{
	/**
	 * {@inheritdoc}
	 */
	public function toOptionArray()
	{
		return [
			['value' => 'us', 'label' => __('United States')],
			['value' => 'us_int', 'label' => __('US + International')]
		];
	}
}
