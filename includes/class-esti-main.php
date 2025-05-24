<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main plugin class responsible for initialization and sync handling
 */
class Esti_Main
{
    private const RESULT_SUCCESS = 'success';
    private const RESULT_SKIPPED = 'skipped';
    private const RESULT_ERROR = 'error';
    private const RESULT_MESSAGES = 'messages';
    private const DEFAULT_ITEMS_TO_PROCESS = 2;
    private const TRANSIENT_RESULTS_KEY = 'esti_sync_results';
    private const TRANSIENT_EXPIRATION = 60;

    private const SYNC_STATUS_SKIPPED = 'skipped';

    private Esti_Data_Reader $dataReader;
    private Esti_Data_Mapper $dataMapper;
    private Esti_Post_Manager $postManager;
    private Esti_Image_Handler $imageHandler;
    private Esti_WordPress_Service $wordPressService;
    private Esti_Admin_Page $adminPage;
    private array $property_dictionary_data = [];

    /**
     * Initialize the plugin
     * 
     * @return void
     */
    public function init(): void
    {
        $this->load_property_dictionary();
        $this->loadDependencies();
        $this->instantiateObjects();
        $this->registerHooks();
    }

    /**
     * Loads and decodes the property data dictionary from a local JSON file.
     */
    private function load_property_dictionary(): void
    {
        $dictionary_file_path = ESTI_SYNC_PLUGIN_PATH . 'data/dictionary.json';

        if (!file_exists($dictionary_file_path)) {
            $this->property_dictionary_data = [];
            return;
        }

        $dictionary_json_string = file_get_contents($dictionary_file_path);

        if ($dictionary_json_string === false) {
            $this->property_dictionary_data = [];
            return;
        }

        if (empty($dictionary_json_string)) {
            $this->property_dictionary_data = [];
            return;
        }

        $decoded_json = json_decode($dictionary_json_string, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->property_dictionary_data = [];
            return;
        }

        if (isset($decoded_json['success']) && $decoded_json['success'] === true && isset($decoded_json['data']) && is_array($decoded_json['data'])) {
            $this->property_dictionary_data = $decoded_json['data'];
        } else {
            $this->property_dictionary_data = [];
        }
    }


    /**
     * Load required WordPress dependencies
     * 
     * @return void
     */
    private function loadDependencies(): void
    {
        require_once ESTI_SYNC_PLUGIN_PATH . 'enums/DictionaryKey.php';
        require_once ESTI_SYNC_PLUGIN_PATH . 'enums/HouzezMetaKey.php';
        require_once ESTI_SYNC_PLUGIN_PATH . 'enums/HouzezTaxonomy.php';
        require_once ESTI_SYNC_PLUGIN_PATH . 'enums/HouzezWpEntity.php';
        require_once ESTI_SYNC_PLUGIN_PATH . 'enums/JsonFeedCode.php';
        require_once ESTI_SYNC_PLUGIN_PATH . 'enums/PostManagerMetaKeys.php';
        require_once ESTI_SYNC_PLUGIN_PATH . 'enums/SyncStatus.php';
        require_once ESTI_SYNC_PLUGIN_PATH . 'services/esti_wordpress_service.php';

        if (!function_exists('media_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }
    }

    /**
     * Create necessary object instances
     * 
     * @return void
     */
    private function instantiateObjects(): void
    {
        $this->dataReader = new Esti_Data_Reader(ESTI_SYNC_DATA_FILE);
        $this->wordPressService = new Esti_WordPress_Service();
        $this->imageHandler = new Esti_Image_Handler($this->wordPressService);

        if (empty($this->property_dictionary_data)) {
            $this->dataMapper = new Esti_Data_Mapper($this->property_dictionary_data);
        } else {
            $this->dataMapper = new Esti_Data_Mapper($this->property_dictionary_data);
        }

        $this->postManager = new Esti_Post_Manager($this->dataMapper, $this->wordPressService, $this->imageHandler);
        $this->adminPage = new Esti_Admin_Page();
    }

    /**
     * Register WordPress action hooks
     * 
     * @return void
     */
    private function registerHooks(): void
    {
        add_action('admin_post_esti_perform_sync', [$this, 'handleSyncAction']);
    }

    /**
     * Process the sync action from admin page form submission
     * 
     * @return void
     */
    public function handleSyncAction(): void
    {
        error_log('Esti Sync: POST data received - ' . print_r($_POST, true));
        $this->verifyPermissions();
        $this->verifyNonce();

        // Validate and get sync parameters
        $syncParams = $this->validateAndGetSyncParams();
        if (is_wp_error($syncParams)) {
            $this->redirectWithError($syncParams->get_error_code());
            return;
        }

        // Debug: Log sync parameters
        error_log('Esti Sync: Sync parameters - ' . print_r($syncParams, true));

        // Get data items based on sync mode
        $dataItems = $this->getDataItems($syncParams);
        error_log('Esti Sync: Retrieved ' . count($dataItems) . ' items before filtering');

        // Filter out duplicates if requested
        if ($syncParams['skip_duplicates']) {
            $originalCount = count($dataItems);
            $dataItems = $this->filterDuplicates($dataItems);
            $filteredCount = count($dataItems);
            error_log("Esti Sync: Filtered duplicates - Original: $originalCount, After filtering: $filteredCount");
        }

        error_log('Esti Sync: Final item count for processing: ' . count($dataItems));

        $results = $this->processSyncItems($dataItems, $syncParams);

        set_transient(self::TRANSIENT_RESULTS_KEY, $results, self::TRANSIENT_EXPIRATION);

        wp_redirect(admin_url('admin.php?page=esti-data-sync&synced=true'));
        exit;
    }

    /**
     * Validate and extract sync parameters from POST data
     * 
     * @return array|WP_Error Sync parameters array or WP_Error on validation failure
     */
    private function validateAndGetSyncParams()
    {
        $sync_mode = sanitize_text_field($_POST['sync_mode'] ?? 'count');
        $skip_duplicates = isset($_POST['skip_duplicates']) && $_POST['skip_duplicates'] === '1';

        if ($sync_mode === 'range') {
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
        } else {
            $items_to_process = max(0, intval($_POST['items_to_process'] ?? self::DEFAULT_ITEMS_TO_PROCESS));

            return [
                'sync_mode' => 'count',
                'items_to_process' => $items_to_process,
                'skip_duplicates' => $skip_duplicates
            ];
        }
    }

    /**
     * Get data items based on sync parameters
     * 
     * @param array $syncParams Validated sync parameters
     * @return array Array of data items
     */
    private function getDataItems(array $syncParams): array
    {
        if ($syncParams['sync_mode'] === 'range') {
            return $this->dataReader->get_data_by_range(
                $syncParams['start_index'],
                $syncParams['end_index']
            );
        } else {
            $limit = $syncParams['items_to_process'] > 0 ? $syncParams['items_to_process'] : null;
            return $this->dataReader->get_data($limit);
        }
    }

    /**
     * Filter out items that already exist based on portalTitle
     * 
     * @param array $dataItems Array of data items to filter
     * @return array Filtered array with duplicates removed
     */
    private function filterDuplicates(array $dataItems): array
    {
        $filtered_items = [];
        $skipped_count = 0;
        $no_title_count = 0;

        foreach ($dataItems as $index => $item) {
            if (!isset($item['portalTitle']) || empty($item['portalTitle'])) {
                // If no portalTitle, include the item (let the sync process handle it)
                $filtered_items[] = $item;
                $no_title_count++;
                error_log("Esti Sync: Item at index $index has no portalTitle, including it");
                continue;
            }

            $portal_title = $item['portalTitle'];

            // Check if a post with this title already exists
            if (!$this->postExistsByTitle($portal_title)) {
                $filtered_items[] = $item;
                error_log("Esti Sync: Item '$portal_title' does not exist, including it");
            } else {
                $skipped_count++;
                error_log("Esti Sync: Item '$portal_title' already exists, skipping it");
            }
        }

        error_log("Esti Sync: Duplicate filtering summary - Original: " . count($dataItems) . ", Final: " . count($filtered_items) . ", Skipped: $skipped_count, No title: $no_title_count");

        return $filtered_items;
    }

    /**
     * Check if a property post exists with the given title
     * 
     * @param string $title Title to search for
     * @return bool True if post exists, false otherwise
     */
    private function postExistsByTitle(string $title): bool
    {
        // First try exact title match
        $args = [
            'post_type' => 'property',
            'title' => $title,  // WordPress 4.6+ supports 'title' parameter
            'posts_per_page' => 1,
            'fields' => 'ids',
            'post_status' => 'any',
            'suppress_filters' => true,
        ];

        $query = new WP_Query($args);
        $found_by_title = $query->have_posts();

        if ($found_by_title) {
            error_log("Esti Sync: Found existing post with title: '$title' (using title parameter)");
            return true;
        }

        // Fallback: Use meta_query or get_page_by_title as alternative
        global $wpdb;

        $existing_post = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} 
         WHERE post_title = %s 
         AND post_type = 'property' 
         AND post_status != 'trash'",
            $title
        ));

        if ($existing_post) {
            error_log("Esti Sync: Found existing post with title: '$title' (using direct query)");
            return true;
        }

        return false;
    }

    /**
     * Redirect to admin page with error parameter
     * 
     * @param string $error_code Error code to display
     * @return void
     */
    private function redirectWithError(string $error_code): void
    {
        wp_redirect(admin_url('admin.php?page=esti-data-sync&error=' . urlencode($error_code)));
        exit;
    }

    /**
     * Get debug information for troubleshooting
     * 
     * @return array Debug information
     */
    public function get_debug_info(): array
    {
        $debug_info = [];

        // Check if ESTI_SYNC_DATA_FILE constant is defined
        $debug_info['constant_defined'] = defined('ESTI_SYNC_DATA_FILE');
        $debug_info['file_path'] = defined('ESTI_SYNC_DATA_FILE') ? ESTI_SYNC_DATA_FILE : 'NOT DEFINED';

        // Get data reader debug info
        if (isset($this->dataReader)) {
            $debug_info['data_reader'] = $this->dataReader->get_debug_info();
        }

        return $debug_info;
    }

    /**
     * Verify current user has required permissions
     * 
     * @return void
     */
    private function verifyPermissions(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to perform this action.', 'esti-data-sync')); // Added text domain
        }
    }

    /**
     * Verify the nonce for the sync action
     * 
     * @return void
     */
    private function verifyNonce(): void
    {
        if (
            !isset($_POST['esti_sync_nonce']) ||
            !wp_verify_nonce($_POST['esti_sync_nonce'], 'esti_sync_action')
        ) {
            wp_die(__('Nonce verification failed. Please try again.', 'esti-data-sync')); // Added text domain
        }
    }

    /**
     * Get the number of items to process from the form submission
     * 
     * @return int Number of items to process
     */
    private function getItemsToProcess(): int
    {
        $items = $_POST['items_to_process'] ?? self::DEFAULT_ITEMS_TO_PROCESS;
        return max(1, intval(sanitize_text_field($items))); // Ensure at least 1
    }

    /**
     * Process the data items for synchronization
     * 
     * @param array $dataItems Array of data items to process
     * @param array $syncParams Sync parameters for debugging
     * @return array Results of the synchronization process
     */
    private function processSyncItems(array $dataItems, array $syncParams = []): array
    {
        $results = $this->initializeResultsArray();

        // Add debugging information
        $results[self::RESULT_MESSAGES][] = sprintf(
            __('Debug: Received %d data items for processing.', 'esti-data-sync'),
            count($dataItems)
        );

        // Add sync parameters to debug output
        if (!empty($syncParams)) {
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
        }

        if (empty($dataItems)) {
            $results[self::RESULT_MESSAGES][] = __('No data items found to process or error reading data source.', 'esti-data-sync');

            // Add more specific debugging
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

        // Rest of the method remains the same...
        if (!$this->postManager) {
            $results[self::RESULT_MESSAGES][] = __('Error: Post Manager not initialized.', 'esti-data-sync');
            $results[self::RESULT_ERROR] = count($dataItems);
            return $results;
        }

        foreach ($dataItems as $item) {
            if (!is_array($item) || !isset($item['id'])) {
                $results[self::RESULT_ERROR]++;
                $results[self::RESULT_MESSAGES][] = __('Invalid item structure encountered during sync.', 'esti-data-sync');
                continue;
            }
            $itemId = $item['id'];
            $result = $this->postManager->sync_property($item);
            $results = $this->updateResults($results, $result, $itemId);
        }

        return $results;
    }

    /**
     * Initialize the results array with default values
     * 
     * @return array The initialized results array
     */
    private function initializeResultsArray(): array
    {
        return [
            self::RESULT_SUCCESS => 0,
            self::RESULT_SKIPPED => 0,
            self::RESULT_ERROR => 0,
            self::RESULT_MESSAGES => []
        ];
    }

    /**
     * Update the results array based on the sync result
     * 
     * @param array $results Current results array
     * @param mixed $syncResult Result from sync operation (int for post ID, string 'skipped', or WP_Error)
     * @param string|int $itemId ID of the current item
     * @return array Updated results array
     */
    private function updateResults(array $results, $syncResult, $itemId): array
    {
        // Check if using SyncStatus Enum from PostManager
        $skipped_status_value = defined('SyncStatus::class') ? SyncStatus::SKIPPED->value : self::SYNC_STATUS_SKIPPED;

        $resultType = match (true) {
            is_wp_error($syncResult) => 'error',
            $syncResult === $skipped_status_value => 'skipped',
            is_int($syncResult) && $syncResult > 0 => 'success',
            default => 'unknown'
        };

        $results = match ($resultType) {
            'error' => [
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
            ],
            'skipped' => [
                ...$results,
                self::RESULT_SKIPPED => $results[self::RESULT_SKIPPED] + 1,
                self::RESULT_MESSAGES => [
                    ...$results[self::RESULT_MESSAGES],
                    sprintf(
                        __('Item ID %s skipped.', 'esti-data-sync'),
                        esc_html($itemId)
                    )
                ]
            ],
            'success' => [
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
            ],
            'unknown' => [
                ...$results,
                self::RESULT_ERROR => $results[self::RESULT_ERROR] + 1,
                self::RESULT_MESSAGES => [
                    ...$results[self::RESULT_MESSAGES],
                    sprintf(
                        __('Unknown error or unexpected result for item ID %s.', 'esti-data-sync'),
                        esc_html($itemId)
                    )
                ]
            ]
        };

        return $results;
    }
}
