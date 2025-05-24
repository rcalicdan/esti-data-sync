<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main plugin class responsible for initialization and coordination
 */
class Esti_Main
{
    private Esti_Data_Reader $dataReader;
    private Esti_Data_Mapper $dataMapper;
    private Esti_Post_Manager $postManager;
    private Esti_Image_Handler $imageHandler;
    private Esti_WordPress_Service $wordPressService;
    private Esti_Admin_Page $adminPage;
    private Esti_Sync_Handler $syncHandler;
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
        require_once ESTI_SYNC_PLUGIN_PATH . 'includes/class-esti-sync-handler.php';

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
        $this->dataMapper = new Esti_Data_Mapper(empty($this->property_dictionary_data) ? [] : $this->property_dictionary_data);
        $this->postManager = new Esti_Post_Manager($this->dataMapper, $this->wordPressService, $this->imageHandler);
        $this->adminPage = new Esti_Admin_Page();

        $this->syncHandler = new Esti_Sync_Handler(
            $this->dataReader,
            $this->dataMapper,
            $this->postManager,
            $this->imageHandler,
            $this->wordPressService
        );
    }

    /**
     * Register WordPress action hooks
     * 
     * @return void
     */
    private function registerHooks(): void
    {
        add_action('admin_post_esti_perform_sync', [$this->syncHandler, 'handleSyncAction']);
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
}
