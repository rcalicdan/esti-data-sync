<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles sync results processing and formatting
 */
class Esti_Sync_Results_Service
{
    private const RESULT_SUCCESS = 'success';
    private const RESULT_SKIPPED = 'skipped';
    private const RESULT_ERROR = 'error';
    private const RESULT_MESSAGES = 'messages';
    private const SYNC_STATUS_SKIPPED = 'skipped';

    /**
     * Initialize the results array with default values
     * 
     * @return array The initialized results array
     */
    public function initializeResults(): array
    {
        return [
            self::RESULT_SUCCESS => 0,
            self::RESULT_SKIPPED => 0,
            self::RESULT_ERROR => 0,
            self::RESULT_MESSAGES => []
        ];
    }

    /**
     * Add debug messages to results
     */
    public function addDebugMessages(array $results, array $dataItems, array $syncParams = []): array
    {
        $results[self::RESULT_MESSAGES][] = sprintf(
            __('Debug: Received %d data items for processing.', 'esti-data-sync'),
            count($dataItems)
        );

        if (!empty($syncParams)) {
            $results = $this->addSyncParameterMessages($results, $syncParams);
            $results = $this->addDuplicateFilterStats($results, $syncParams);
        }

        return $results;
    }

    /**
     * Add sync parameter debug messages
     */
    private function addSyncParameterMessages(array $results, array $syncParams): array
    {
        $results[self::RESULT_MESSAGES][] = sprintf(
            __('Debug: Sync mode: %s', 'esti-data-sync'),
            $syncParams['sync_mode'] ?? 'unknown'
        );

        if ($syncParams['sync_mode'] === 'range') {
            $results[self::RESULT_MESSAGES][] = sprintf(
                __('Debug: Range requested: %d to %d', 'esti-data-sync'),
                $syncParams['start_index'] ?? 0,
                $syncParams['end_index'] ?? 0
            );
        } else {
            $results[self::RESULT_MESSAGES][] = sprintf(
                __('Debug: Items to process: %d', 'esti-data-sync'),
                $syncParams['items_to_process'] ?? 0
            );
        }

        $results[self::RESULT_MESSAGES][] = sprintf(
            __('Debug: Skip duplicates: %s', 'esti-data-sync'),
            ($syncParams['skip_duplicates'] ?? false) ? 'Yes' : 'No'
        );

        return $results;
    }

    /**
     * Add duplicate filtering statistics to debug messages
     */
    private function addDuplicateFilterStats(array $results, array $syncParams): array
    {
        if (isset($syncParams['duplicate_filter_stats'])) {
            $stats = $syncParams['duplicate_filter_stats'];
            $results[self::RESULT_MESSAGES][] = sprintf(
                __('Duplicate Filter: %d items retrieved, %d after filtering, %d filtered out as duplicates', 'esti-data-sync'),
                $stats['original_count'],
                $stats['after_filter_count'],
                $stats['filtered_out_count']
            );
            
            if ($stats['filtered_out_count'] > 0) {
                $results[self::RESULT_MESSAGES][] = sprintf(
                    __('Note: %d duplicate items were removed before processing (based on portalTitle)', 'esti-data-sync'),
                    $stats['filtered_out_count']
                );
            }
        }

        return $results;
    }

    /**
     * Update the results array based on the sync result
     * 
     * @param array $results Current results array
     * @param mixed $syncResult Result from sync operation
     * @param string|int $itemId ID of the current item
     * @return array Updated results array
     */
    public function updateResults(array $results, $syncResult, $itemId): array
    {
        $skipped_status_value = defined('SyncStatus::class') ? SyncStatus::SKIPPED->value : self::SYNC_STATUS_SKIPPED;

        $resultType = match (true) {
            is_wp_error($syncResult) => 'error',
            $syncResult === $skipped_status_value => 'skipped',
            is_int($syncResult) && $syncResult > 0 => 'success',
            default => 'unknown'
        };

        return match ($resultType) {
            'error' => $this->handleErrorResult($results, $syncResult, $itemId),
            'skipped' => $this->handleSkippedResult($results, $itemId),
            'success' => $this->handleSuccessResult($results, $syncResult, $itemId),
            'unknown' => $this->handleUnknownResult($results, $itemId)
        };
    }

    private function handleErrorResult(array $results, $syncResult, $itemId): array
    {
        return [
            ...$results,
            self::RESULT_ERROR => $results[self::RESULT_ERROR] + 1,
            self::RESULT_MESSAGES => [
                ...$results[self::RESULT_MESSAGES],
                sprintf(
                    __('Error syncing item ID %1$s: %2$s', 'esti-data-sync'),
                    esc_html($itemId),
                    esc_html($syncResult->get_error_message())
                )
            ]
        ];
    }

    private function handleSkippedResult(array $results, $itemId): array
    {
        return [
            ...$results,
            self::RESULT_SKIPPED => $results[self::RESULT_SKIPPED] + 1,
            self::RESULT_MESSAGES => [
                ...$results[self::RESULT_MESSAGES],
                sprintf(
                    __('Item ID %s skipped during sync processing.', 'esti-data-sync'),
                    esc_html($itemId)
                )
            ]
        ];
    }

    private function handleSuccessResult(array $results, $syncResult, $itemId): array
    {
        return [
            ...$results,
            self::RESULT_SUCCESS => $results[self::RESULT_SUCCESS] + 1,
            self::RESULT_MESSAGES => [
                ...$results[self::RESULT_MESSAGES],
                sprintf(
                    __('Successfully synced item ID %1$s to post ID %2$s.', 'esti-data-sync'),
                    esc_html($itemId),
                    esc_html($syncResult)
                )
            ]
        ];
    }

    private function handleUnknownResult(array $results, $itemId): array
    {
        return [
            ...$results,
            self::RESULT_ERROR => $results[self::RESULT_ERROR] + 1,
            self::RESULT_MESSAGES => [
                ...$results[self::RESULT_MESSAGES],
                sprintf(
                    __('Unknown error or unexpected result for item ID %s.', 'esti-data-sync'),
                    esc_html($itemId)
                )
            ]
        ];
    }

    /**
     * Handle empty data items scenario
     */
    public function handleEmptyDataItems(array $results): array
    {
        $results[self::RESULT_MESSAGES][] = __('No data items found to process or error reading data source.', 'esti-data-sync');

        $file_exists = file_exists(ESTI_SYNC_DATA_FILE);
        $results[self::RESULT_MESSAGES][] = sprintf(
            __('Debug: JSON file exists: %s', 'esti-data-sync'),
            $file_exists ? 'Yes' : 'No'
        );

        if ($file_exists) {
            $results[self::RESULT_MESSAGES][] = sprintf(
                __('Debug: JSON file path: %s', 'esti-data-sync'),
                ESTI_SYNC_DATA_FILE
            );
        }

        return $results;
    }
}