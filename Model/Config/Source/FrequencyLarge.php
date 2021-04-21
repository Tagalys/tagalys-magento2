<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Tagalys\Sync\Model\Config\Source;

class FrequencyLarge implements \Magento\Framework\Data\OptionSourceInterface
{
    /**
     * @var array
     */
    protected static $_options;

    const CRON_DAILY = '*';
    const CRON_EVERY_SUNDAY = '0';
    const CRON_EVERY_MONDAY = '1';
    const CRON_EVERY_TUESDAY = '2';
    const CRON_EVERY_WEDNESDAY = '3';
    const CRON_EVERY_THURSDAY = '4';
    const CRON_EVERY_FRIDAY = '5';
    const CRON_EVERY_SATURDAY = '6';

    /**
     * @return array
     */
    public function toOptionArray()
    {
        if (!self::$_options) {
            self::$_options = [
                ['label' => __('Daily'), 'value' => self::CRON_DAILY],
                ['label' => __('Every Sunday'), 'value' => self::CRON_EVERY_SUNDAY],
                ['label' => __('Every Monday'), 'value' => self::CRON_EVERY_MONDAY],
                ['label' => __('Every Tuesday'), 'value' => self::CRON_EVERY_TUESDAY],
                ['label' => __('Every Wednesday'), 'value' => self::CRON_EVERY_WEDNESDAY],
                ['label' => __('Every Thursday'), 'value' => self::CRON_EVERY_THURSDAY],
                ['label' => __('Every Friday'), 'value' => self::CRON_EVERY_FRIDAY],
                ['label' => __('Every Saturday'), 'value' => self::CRON_EVERY_SATURDAY],
            ];
        }
        return self::$_options;
    }
}
