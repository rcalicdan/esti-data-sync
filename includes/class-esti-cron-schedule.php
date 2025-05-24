<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages custom cron schedules for Esti sync
 */
class Esti_Cron_Schedules
{
    private const TIME_INTERVAL = 7200; // 2 hours
    private const WEEKLY_INTERVAL = 604800; // 7 days

    /**
     * Initialize the cron schedules
     */
    public function __construct()
    {
        add_filter('cron_schedules', [$this, 'addCustomSchedules']);
    }

    /**
     * Add custom cron schedules
     */
    public function addCustomSchedules(array $schedules): array
    {
        // Add every minute for testing (remove in production)
        $schedules['every_minute'] = [
            'interval' => self::TIME_INTERVAL,
            'display'  => __('Every Minute', 'esti-data-sync')
        ];

        // Add weekly schedule if not exists
        if (!isset($schedules['weekly'])) {
            $schedules['weekly'] = [
                'interval' => self::WEEKLY_INTERVAL, 
                'display'  => __('Weekly', 'esti-data-sync')
            ];
        }

        return $schedules;
    }
}