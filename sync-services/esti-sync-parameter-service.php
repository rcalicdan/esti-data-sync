<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles validation and processing of sync parameters
 */
class Esti_Sync_Parameter_Service
{
    private const DEFAULT_ITEMS_TO_PROCESS = 2;

    /**
     * Validate and extract sync parameters from POST data
     * 
     * @return array|WP_Error Sync parameters array or WP_Error on validation failure
     */
    public function validateAndGetSyncParams(): array|WP_Error
    {
        $sync_mode = sanitize_text_field($_POST['sync_mode'] ?? 'count');
        $skip_duplicates = isset($_POST['skip_duplicates']) && $_POST['skip_duplicates'] === '1';

        if ($sync_mode === 'range') {
            return $this->validateRangeParams($skip_duplicates);
        } else {
            return $this->validateCountParams($skip_duplicates);
        }
    }

    /**
     * Validate range-based sync parameters
     */
    private function validateRangeParams(bool $skip_duplicates): array|WP_Error
    {
        $start_index = intval($_POST['start_index'] ?? 0);
        $end_index = intval($_POST['end_index'] ?? 0);

        if ($start_index > $end_index) {
            return new WP_Error('invalid_range', 'Start index must be less than or equal to end index.');
        }

        if ($start_index < 0 || $end_index < 0) {
            return new WP_Error('invalid_range', 'Indices must be non-negative.');
        }

        return [
            'sync_mode' => 'range',
            'start_index' => $start_index,
            'end_index' => $end_index,
            'skip_duplicates' => $skip_duplicates
        ];
    }

    /**
     * Validate count-based sync parameters
     */
    private function validateCountParams(bool $skip_duplicates): array
    {
        $items_to_process = max(0, intval($_POST['items_to_process'] ?? self::DEFAULT_ITEMS_TO_PROCESS));

        return [
            'sync_mode' => 'count',
            'items_to_process' => $items_to_process,
            'skip_duplicates' => $skip_duplicates
        ];
    }
}