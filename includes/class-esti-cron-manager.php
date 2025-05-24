<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages WP Cron jobs for automatic property synchronization
 */
class Esti_Cron_Manager
{
    private const CRON_HOOK = 'esti_auto_sync_properties';
    private const OPTION_CRON_SETTINGS = 'esti_cron_settings';
    private const DEFAULT_BATCH_SIZE = 10;
    private const LOG_OPTION_KEY = 'esti_cron_logs';
    private const MAX_LOGS = 50;

    private Esti_Data_Reader $dataReader;
    private Esti_Post_Manager $postManager;
    private Esti_Duplicate_Filter_Service $duplicateFilterService;

    public function __construct(
        Esti_Data_Reader $dataReader,
        Esti_Post_Manager $postManager
    ) {
        $this->dataReader = $dataReader;
        $this->postManager = $postManager;
        
        require_once ESTI_SYNC_PLUGIN_PATH . 'sync-services/esti-duplicate-filter-service.php';
        $this->duplicateFilterService = new Esti_Duplicate_Filter_Service();
    }

    /**
     * Initialize cron hooks
     */
    public function init(): void
    {
        add_action(self::CRON_HOOK, [$this, 'runAutoSync']);
        add_action('wp', [$this, 'scheduleCronIfNeeded']);
        
        // Clean up on plugin deactivation
        register_deactivation_hook(ESTI_SYNC_DATA_FILE, [$this, 'clearScheduledEvents']);
    }

    /**
     * Schedule cron job if settings indicate it should be active
     */
    public function scheduleCronIfNeeded(): void
    {
        $settings = $this->getCronSettings();
        
        if (!$settings['enabled']) {
            $this->clearScheduledEvents();
            return;
        }

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), $settings['frequency'], self::CRON_HOOK);
            $this->log('Cron job scheduled with frequency: ' . $settings['frequency']);
        }
    }

    /**
     * Run the automatic synchronization
     */
    public function runAutoSync(): void
    {
        $this->log('Auto sync started');
        
        $settings = $this->getCronSettings();
        
        if (!$settings['enabled']) {
            $this->log('Auto sync is disabled, skipping execution');
            return;
        }

        try {
            $batchSize = $settings['batch_size'] ?? self::DEFAULT_BATCH_SIZE;
            $dataItems = $this->dataReader->get_data($batchSize);
            
            if (empty($dataItems)) {
                $this->log('No data items found for sync');
                return;
            }

            $this->log('Retrieved ' . count($dataItems) . ' items for processing');

            if ($settings['skip_duplicates']) {
                $originalCount = count($dataItems);
                $dataItems = $this->duplicateFilterService->filterDuplicates($dataItems);
                $filteredCount = count($dataItems);
                $this->log("Filtered duplicates: {$originalCount} -> {$filteredCount}");
            }

            $results = $this->processItems($dataItems);
            $this->logResults($results);

        } catch (Exception $e) {
            $this->log('Error during auto sync: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Process sync items
     */
    private function processItems(array $dataItems): array
    {
        $results = [
            'success' => 0,
            'skipped' => 0,
            'error' => 0,
            'messages' => []
        ];

        foreach ($dataItems as $item) {
            if (!is_array($item) || !isset($item['id'])) {
                $results['error']++;
                $results['messages'][] = 'Invalid item structure';
                continue;
            }

            $syncResult = $this->postManager->sync_property($item);
            
            if (is_wp_error($syncResult)) {
                $results['error']++;
                $results['messages'][] = 'Error for item ' . $item['id'] . ': ' . $syncResult->get_error_message();
            } elseif ($syncResult === 'skipped') {
                $results['skipped']++;
            } elseif (is_int($syncResult) && $syncResult > 0) {
                $results['success']++;
                $results['messages'][] = 'Successfully synced item ' . $item['id'] . ' to post ' . $syncResult;
            }
        }

        return $results;
    }

    /**
     * Log sync results
     */
    private function logResults(array $results): void
    {
        $message = sprintf(
            'Auto sync completed - Success: %d, Skipped: %d, Errors: %d',
            $results['success'],
            $results['skipped'],
            $results['error']
        );
        
        $this->log($message);
        
        // Log first few detailed messages
        $detailMessages = array_slice($results['messages'], 0, 5);
        foreach ($detailMessages as $msg) {
            $this->log($msg, 'info');
        }
    }

    /**
     * Get cron settings with defaults
     */
    public function getCronSettings(): array
    {
        $defaults = [
            'enabled' => false,
            'frequency' => 'hourly',
            'batch_size' => self::DEFAULT_BATCH_SIZE,
            'skip_duplicates' => true,
        ];

        $settings = get_option(self::OPTION_CRON_SETTINGS, []);
        return wp_parse_args($settings, $defaults);
    }

    /**
     * Update cron settings
     */
    public function updateCronSettings(array $settings): bool
    {
        $currentSettings = $this->getCronSettings();
        $newSettings = wp_parse_args($settings, $currentSettings);
        
        $updated = update_option(self::OPTION_CRON_SETTINGS, $newSettings);
        
        if ($updated) {
            // Reschedule if frequency changed or enabled/disabled
            if ($currentSettings['frequency'] !== $newSettings['frequency'] || 
                $currentSettings['enabled'] !== $newSettings['enabled']) {
                $this->clearScheduledEvents();
                $this->scheduleCronIfNeeded();
            }
            
            $this->log('Cron settings updated');
        }
        
        return $updated;
    }

    /**
     * Get available cron frequencies
     */
    public function getAvailableFrequencies(): array
    {
        return [
            'every_minute' => __('Every Minute (Testing)', 'esti-data-sync'),
            'hourly' => __('Hourly', 'esti-data-sync'),
            'twicedaily' => __('Twice Daily', 'esti-data-sync'),
            'daily' => __('Daily', 'esti-data-sync'),
            'weekly' => __('Weekly', 'esti-data-sync'),
        ];
    }

    /**
     * Clear all scheduled events
     */
    public function clearScheduledEvents(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
            $this->log('Scheduled cron events cleared');
        }
    }

    /**
     * Get next scheduled run time
     */
    public function getNextScheduledRun(): ?int
    {
        return wp_next_scheduled(self::CRON_HOOK) ?: null;
    }

    /**
     * Get cron logs
     */
    public function getLogs(int $limit = 20): array
    {
        $logs = get_option(self::LOG_OPTION_KEY, []);
        return array_slice($logs, -$limit);
    }

    /**
     * Log a message with timestamp
     */
    private function log(string $message, string $level = 'info'): void
    {
        $logs = get_option(self::LOG_OPTION_KEY, []);
        
        $logEntry = [
            'timestamp' => current_time('mysql'),
            'level' => $level,
            'message' => $message
        ];
        
        $logs[] = $logEntry;
        
        // Keep only the latest logs
        if (count($logs) > self::MAX_LOGS) {
            $logs = array_slice($logs, -self::MAX_LOGS);
        }
        
        update_option(self::LOG_OPTION_KEY, $logs);
        
        // Also log to WordPress error log for debugging
        error_log("Esti Auto Sync [{$level}]: {$message}");
    }

    /**
     * Clear all logs
     */
    public function clearLogs(): void
    {
        delete_option(self::LOG_OPTION_KEY);
    }

    /**
     * Trigger manual sync (for testing)
     */
    public function triggerManualSync(): array
    {
        $this->log('Manual sync triggered from admin');
        
        ob_start();
        $this->runAutoSync();
        ob_end_clean();
        
        return [
            'success' => true,
            'message' => __('Manual sync completed. Check logs for details.', 'esti-data-sync')
        ];
    }
}