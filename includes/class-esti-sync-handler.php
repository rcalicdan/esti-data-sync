<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles property synchronization operations
 */
class Esti_Sync_Handler
{
    private const TRANSIENT_RESULTS_KEY = 'esti_sync_results';
    private const TRANSIENT_EXPIRATION = 60;

    private Esti_Data_Reader $dataReader;
    private Esti_Data_Mapper $dataMapper;
    private Esti_Post_Manager $postManager;
    private Esti_Image_Handler $imageHandler;
    private Esti_WordPress_Service $wordPressService;
    
    // Service instances
    private Esti_Sync_Parameter_Service $parameterService;
    private Esti_Duplicate_Filter_Service $duplicateFilterService;
    private Esti_Sync_Results_Service $resultsService;

    public function __construct(
        Esti_Data_Reader $dataReader,
        Esti_Data_Mapper $dataMapper,
        Esti_Post_Manager $postManager,
        Esti_Image_Handler $imageHandler,
        Esti_WordPress_Service $wordPressService
    ) {
        $this->dataReader = $dataReader;
        $this->dataMapper = $dataMapper;
        $this->postManager = $postManager;
        $this->imageHandler = $imageHandler;
        $this->wordPressService = $wordPressService;
        
        // Load service dependencies
        $this->loadDependencies();
    }

    /**
     * Load service service class dependencies
     */
    private function loadDependencies(): void
    {   
        // Load service classes
        require_once ESTI_SYNC_PLUGIN_PATH . 'sync-services/esti-sync-parameter-service.php';
        require_once ESTI_SYNC_PLUGIN_PATH . 'sync-services/esti-duplicate-filter-service.php';
        require_once ESTI_SYNC_PLUGIN_PATH . 'sync-services/esti-sync-results-service.php';
        
        // Initialize service instances
        $this->parameterService = new Esti_Sync_Parameter_Service();
        $this->duplicateFilterService = new Esti_Duplicate_Filter_Service();
        $this->resultsService = new Esti_Sync_Results_Service();
    }

    /**
     * Process the sync action from admin page form submission
     */
    public function handleSyncAction(): void
    {
        error_log('Esti Sync: POST data received - ' . print_r($_POST, true));
        
        $this->verifyPermissions();
        $this->verifyNonce();

        $syncParams = $this->parameterService->validateAndGetSyncParams();

        if (is_wp_error($syncParams)) {
            $this->redirectWithError($syncParams->get_error_code());
            return;
        }

        error_log('Esti Sync: Sync parameters - ' . print_r($syncParams, true));

        $dataItems = $this->getDataItems($syncParams);

        error_log('Esti Sync: Retrieved ' . count($dataItems) . ' items before filtering');

        if ($syncParams['skip_duplicates']) {
            $originalCount = count($dataItems);
            $dataItems = $this->duplicateFilterService->filterDuplicates($dataItems);
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
     * Get data items based on sync parameters
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
     * Process the data items for synchronization
     */
    private function processSyncItems(array $dataItems, array $syncParams = []): array
    {
        $results = $this->resultsService->initializeResults();
        $results = $this->resultsService->addDebugMessages($results, $dataItems, $syncParams);

        if (empty($dataItems)) {
            return $this->resultsService->handleEmptyDataItems($results);
        }

        if (!$this->postManager) {
            $results['messages'][] = __('Error: Post Manager not initialized.', 'esti-data-sync');
            $results['error'] = count($dataItems);
            return $results;
        }

        foreach ($dataItems as $item) {
            if (!is_array($item) || !isset($item['id'])) {
                $results = $this->resultsService->updateResults($results, new WP_Error('invalid_item', 'Invalid item structure'), 'unknown');
                continue;
            }

            $itemId = $item['id'];
            $syncResult = $this->postManager->sync_property($item);
            $results = $this->resultsService->updateResults($results, $syncResult, $itemId);
        }

        return $results;
    }

    /**
     * Redirect to admin page with error parameter
     */
    private function redirectWithError(string $error_code): void
    {
        wp_redirect(admin_url('admin.php?page=esti-data-sync&error=' . urlencode($error_code)));
        exit;
    }

    /**
     * Verify current user has required permissions
     */
    private function verifyPermissions(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to perform this action.', 'esti-data-sync'));
        }
    }

    /**
     * Verify the nonce for the sync action
     */
    private function verifyNonce(): void
    {
        if (!isset($_POST['esti_sync_nonce']) || !wp_verify_nonce($_POST['esti_sync_nonce'], 'esti_sync_action')) {
            wp_die(__('Nonce verification failed. Please try again.', 'esti-data-sync'));
        }
    }
}