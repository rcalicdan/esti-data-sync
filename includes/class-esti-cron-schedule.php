<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages custom cron schedules for Esti sync
 */
class Esti_Cron_Schedules
{
    private const MINUTE_INTERVAL = 60;        // 1 minute
    private const FIVE_MINUTE_INTERVAL = 300;  // 5 minutes
    private const FIFTEEN_MINUTE_INTERVAL = 900; // 15 minutes
    private const THIRTY_MINUTE_INTERVAL = 1800; // 30 minutes
    private const HOURLY_INTERVAL = 3600;      // 1 hour
    private const TWO_HOUR_INTERVAL = 7200;    // 2 hours
    private const SIX_HOUR_INTERVAL = 21600;   // 6 hours
    private const TWELVE_HOUR_INTERVAL = 43200; // 12 hours
    private const WEEKLY_INTERVAL = 604800;    // 7 days

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
        // Add every minute for testing (use cautiously in production)
        $schedules['every_minute'] = [
            'interval' => self::MINUTE_INTERVAL,
            'display'  => __('Every Minute (Testing Only)', 'esti-data-sync')
        ];

        // Add 5 minutes
        $schedules['every_5_minutes'] = [
            'interval' => self::FIVE_MINUTE_INTERVAL,
            'display'  => __('Every 5 Minutes', 'esti-data-sync')
        ];

        // Add 15 minutes
        $schedules['every_15_minutes'] = [
            'interval' => self::FIFTEEN_MINUTE_INTERVAL,
            'display'  => __('Every 15 Minutes', 'esti-data-sync')
        ];

        // Add 30 minutes
        $schedules['every_30_minutes'] = [
            'interval' => self::THIRTY_MINUTE_INTERVAL,
            'display'  => __('Every 30 Minutes', 'esti-data-sync')
        ];

        // Add 2 hours
        $schedules['every_2_hours'] = [
            'interval' => self::TWO_HOUR_INTERVAL,
            'display'  => __('Every 2 Hours', 'esti-data-sync')
        ];

        // Add 6 hours
        $schedules['every_6_hours'] = [
            'interval' => self::SIX_HOUR_INTERVAL,
            'display'  => __('Every 6 Hours', 'esti-data-sync')
        ];

        // Add 12 hours
        $schedules['every_12_hours'] = [
            'interval' => self::TWELVE_HOUR_INTERVAL,
            'display'  => __('Every 12 Hours', 'esti-data-sync')
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

    /**
     * Get available frequency options for admin interface
     */
    public static function getAvailableFrequencies(): array
    {
        return [
            'every_5_minutes'  => __('Every 5 Minutes', 'esti-data-sync'),
            'every_15_minutes' => __('Every 15 Minutes', 'esti-data-sync'),
            'every_30_minutes' => __('Every 30 Minutes', 'esti-data-sync'),
            'hourly'           => __('Hourly', 'esti-data-sync'),
            'every_2_hours'    => __('Every 2 Hours', 'esti-data-sync'),
            'every_6_hours'    => __('Every 6 Hours', 'esti-data-sync'),
            'every_12_hours'   => __('Every 12 Hours', 'esti-data-sync'),
            'daily'            => __('Daily', 'esti-data-sync'),
            'weekly'           => __('Weekly', 'esti-data-sync'),
        ];
    }

    /**
     * Get recommended frequency for production use
     */
    public static function getRecommendedFrequency(): string
    {
        return 'every_2_hours';
    }

    /**
     * Check if a frequency is suitable for production
     */
    public static function isProductionSafe(string $frequency): bool
    {
        $unsafe_frequencies = ['every_minute', 'every_5_minutes'];
        return !in_array($frequency, $unsafe_frequencies, true);
    }
}