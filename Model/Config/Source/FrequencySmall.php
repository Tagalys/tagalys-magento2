<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Tagalys\Sync\Model\Config\Source;

class FrequencySmall implements \Magento\Framework\Data\OptionSourceInterface
{
    /**
     * @var array
     */
    protected static $_options;

    const CRON_EVERY_5_MIN = '*/5';
    const CRON_EVERY_10_MIN = '*/10';
    const CRON_EVERY_15_MIN = '*/15';
    const CRON_EVERY_30_MIN = '*/30';

    /**
     * @return array
     */
    public function toOptionArray()
    {
        if (!self::$_options) {
            self::$_options = [
                ['label' => __('Every 5 minutes (recommended)'), 'value' => self::CRON_EVERY_5_MIN],
                ['label' => __('Every 10 minutes'), 'value' => self::CRON_EVERY_10_MIN],
                ['label' => __('Every 15 minutes'), 'value' => self::CRON_EVERY_15_MIN],
                ['label' => __('Every 30 minutes'), 'value' => self::CRON_EVERY_30_MIN],
            ];
        }
        return self::$_options;
    }
}
