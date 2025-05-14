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
            error_log('Esti Sync Error: Dictionary file not found at ' . $dictionary_file_path);
            $this->property_dictionary_data = [];
            return;
        }

        $dictionary_json_string = file_get_contents($dictionary_file_path);

        if ($dictionary_json_string === false) {
            error_log('Esti Sync Error: Failed to read dictionary file at ' . $dictionary_file_path);
            $this->property_dictionary_data = [];
            return;
        }

        if (empty($dictionary_json_string)) {
            error_log('Esti Sync Error: Dictionary JSON file is empty at ' . $dictionary_file_path);
            $this->property_dictionary_data = [];
            return;
        }

        $decoded_json = json_decode($dictionary_json_string, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('Esti Sync Error: Failed to decode dictionary JSON from file ' . $dictionary_file_path . '. Error: ' . json_last_error_msg());
            $this->property_dictionary_data = [];
            return;
        }

        if (isset($decoded_json['success']) && $decoded_json['success'] === true && isset($decoded_json['data']) && is_array($decoded_json['data'])) {
            $this->property_dictionary_data = $decoded_json['data'];
            error_log('Esti Sync: Property dictionary loaded successfully from ' . $dictionary_file_path);
        } else {
            error_log('Esti Sync Error: Dictionary JSON from file ' . $dictionary_file_path . ' is not in the expected format, "success" is not true, or "data" key is missing/not an array.');
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
        // Ensure Enums are loaded if they are in separate files and not autoloaded
        // require_once plugin_dir_path(__FILE__) . 'enums.php'; // Adjust path if needed

        $this->dataReader = new Esti_Data_Reader(ESTI_SYNC_DATA_FILE); // Assuming ESTI_SYNC_DATA_FILE is defined

        // Check if dictionary loaded successfully before instantiating mapper
        if (empty($this->property_dictionary_data)) {
            error_log('Esti Sync Critical Error: Property dictionary not loaded. Mapper cannot be instantiated.');
            // You might want to prevent further execution or display a persistent admin error.
            // For now, this will likely cause issues down the line if other methods expect $this->dataMapper.
            // A more robust solution would be to halt or throw an exception if dictionary is essential.
            // $this->dataMapper = new Esti_Data_Mapper([]); // Or pass empty and let mapper handle/error
            // Forcing an error is better to highlight the missing dictionary.
            // throw new \Exception("Property dictionary not loaded, cannot instantiate mapper.");
            // For now, let's proceed with an empty mapper if dict is empty, but this is not ideal
            // This will cause an error if an empty array is passed and constructor requires non-empty
            // To avoid the immediate fatal error from the constructor, you *could* pass an empty array,
            // but the mapper is designed to NEED the dictionary.
            // So, the proper fix is to ensure $this->property_dictionary_data IS populated.
            // If it's absolutely critical, you could do:
            // if (empty($this->property_dictionary_data)) {
            //     wp_die("Esti Sync Critical Error: Property dictionary failed to load. Plugin cannot function.");
            // }
            // For now, to directly address the constructor error, we ensure something is passed.
            // But the LOGIC of your plugin depends on a VALID dictionary.
            $this->dataMapper = new Esti_Data_Mapper($this->property_dictionary_data); // THIS IS THE FIX
        } else {
            $this->dataMapper = new Esti_Data_Mapper($this->property_dictionary_data); // THIS IS THE FIX
        }

        $this->postManager = new Esti_Post_Manager($this->dataMapper); // Pass the instantiated mapper
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
        // If Admin Page class adds its own menu, it might be called from there or here.
        // For example, if Esti_Admin_Page has a setup_menu method:
        // add_action('admin_menu', [$this->adminPage, 'setup_menu']);
    }

    /**
     * Process the sync action from admin page form submission
     * 
     * @return void
     */
    public function handleSyncAction(): void
    {
        $this->verifyPermissions();
        $this->verifyNonce();

        $itemsToProcess = $this->getItemsToProcess();
        $dataItems = $this->dataReader->get_data($itemsToProcess);
        $results = $this->processSyncItems($dataItems);

        set_transient(self::TRANSIENT_RESULTS_KEY, $results, self::TRANSIENT_EXPIRATION);

        // Make sure the admin page slug is correct
        wp_redirect(admin_url('admin.php?page=esti-data-sync&synced=true'));
        exit;
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
     * @return array Results of the synchronization process
     */
    private function processSyncItems(array $dataItems): array
    {
        $results = $this->initializeResultsArray();

        if (empty($dataItems)) {
            $results[self::RESULT_MESSAGES][] = __('No data items found to process or error reading data source.', 'esti-data-sync'); // Added text domain
            return $results;
        }

        // Ensure Post Manager is available
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

        if (is_wp_error($syncResult)) {
            $results[self::RESULT_ERROR]++;
            $results[self::RESULT_MESSAGES][] = sprintf(
                __('Error syncing item ID %1$s: %2$s', 'esti-data-sync'), // Added text domain
                esc_html($itemId),
                esc_html($syncResult->get_error_message())
            );
        } elseif ($syncResult === $skipped_status_value) { // Use the correct skipped status value
            $results[self::RESULT_SKIPPED]++;
            $results[self::RESULT_MESSAGES][] = sprintf(
                __('Item ID %s skipped.', 'esti-data-sync'), // Added text domain
                esc_html($itemId)
            );
        } elseif (is_int($syncResult) && $syncResult > 0) { // Check for valid Post ID
            $results[self::RESULT_SUCCESS]++;
            $results[self::RESULT_MESSAGES][] = sprintf(
                __('Successfully synced item ID %1$s to post ID %2$s.', 'esti-data-sync'), // Added text domain
                esc_html($itemId),
                esc_html($syncResult)
            );
        } else {
            // Catch-all for unexpected result types from sync_property
            $results[self::RESULT_ERROR]++;
            $results[self::RESULT_MESSAGES][] = sprintf(
                __('Unknown error or unexpected result for item ID %s.', 'esti-data-sync'),
                esc_html($itemId)
            );
            error_log('Esti Sync: Unexpected sync result for item ID ' . $itemId . ': ' . print_r($syncResult, true));
        }

        return $results;
    }
}
