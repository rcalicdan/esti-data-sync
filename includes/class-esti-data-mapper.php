<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once ESTI_SYNC_PLUGIN_PATH . 'services/esti_dictionary_service.php';
require_once ESTI_SYNC_PLUGIN_PATH . 'services/esti_sanitizer_service.php';
require_once ESTI_SYNC_PLUGIN_PATH . 'mappers/esti_address_meta_mapper_service.php';
require_once ESTI_SYNC_PLUGIN_PATH . 'mappers/esti_agent_info_mapper_service.php';
require_once ESTI_SYNC_PLUGIN_PATH . 'mappers/esti_core_post_mapper_service.php';
require_once ESTI_SYNC_PLUGIN_PATH . 'mappers/esti_features_meta_mapper_service.php';
require_once ESTI_SYNC_PLUGIN_PATH . 'mappers/esti_image_mapper_service.php';
require_once ESTI_SYNC_PLUGIN_PATH . 'mappers/esti_property_detail_mapper_service.php';
require_once ESTI_SYNC_PLUGIN_PATH . 'mappers/esti_taxonomy_mapper_service.php';

/**
 * Maps external property data to WordPress formats.
 * 
 * Transforms raw property data into structured arrays for WordPress posts,
 * meta fields, taxonomies, and media attachments by delegating to specialized services.
 */
class Esti_Data_Mapper
{
    private Esti_Sanitizer_Service $sanitizer_service;
    private Esti_Dictionary_Service $dictionary_service;
    private Esti_Core_Post_Mapper_Service $core_post_mapper;
    private Esti_Agent_Info_Mapper_Service $agent_info_mapper;
    private Esti_Address_Meta_Mapper_Service $address_meta_mapper;
    private Esti_Property_Details_Meta_Mapper_Service $property_details_meta_mapper;
    private Esti_Features_Meta_Mapper_Service $features_meta_mapper;
    private Esti_Taxonomy_Mapper_Service $taxonomy_mapper;
    private Esti_Image_Mapper_Service $image_mapper;

    /**
     * Constructor.
     * 
     * @param array $dictionary_data Dictionary lookup data for mapping values
     */
    public function __construct(array $dictionary_data)
    {
        $this->sanitizer_service = new Esti_Sanitizer_Service();
        $this->dictionary_service = new Esti_Dictionary_Service($dictionary_data, $this->sanitizer_service);

        $this->core_post_mapper = new Esti_Core_Post_Mapper_Service($this->sanitizer_service);
        $this->agent_info_mapper = new Esti_Agent_Info_Mapper_Service($this->sanitizer_service);
        $this->address_meta_mapper = new Esti_Address_Meta_Mapper_Service($this->sanitizer_service);
        $this->property_details_meta_mapper = new Esti_Property_Details_Meta_Mapper_Service($this->sanitizer_service, $this->dictionary_service);
        $this->features_meta_mapper = new Esti_Features_Meta_Mapper_Service($this->sanitizer_service, $this->dictionary_service);
        $this->taxonomy_mapper = new Esti_Taxonomy_Mapper_Service($this->sanitizer_service, $this->dictionary_service);
        $this->image_mapper = new Esti_Image_Mapper_Service();
    }

    /**
     * Maps raw property data to WordPress-compatible format.
     * 
     * @param array $item_data Raw property data
     * @return array Mapped WordPress data
     */
    public function map_to_wp_args(array $item_data): array
    {
        $mapped = [
            'post_args'          => [],
            'meta_input'         => [],
            'tax_input'          => [],
            'featured_image_url' => '',
            'gallery_image_urls' => [],
        ];

        $this->core_post_mapper->map($mapped, $item_data);
        $this->property_details_meta_mapper->map_identifiers_and_defaults($mapped['meta_input'], $item_data);
        $this->property_details_meta_mapper->map_price($mapped['meta_input'], $item_data);
        $this->property_details_meta_mapper->map_size_area($mapped['meta_input'], $item_data);
        $this->property_details_meta_mapper->map_room_counts($mapped['meta_input'], $item_data);
        $this->property_details_meta_mapper->map_building_details($mapped['meta_input'], $item_data);
        $this->property_details_meta_mapper->map_garage_info($mapped['meta_input'], $item_data);
        // $this->property_details_meta_mapper->map_featured_property($mapped['meta_input'], $item_data);
        $this->address_meta_mapper->map($mapped, $item_data);
        $this->agent_info_mapper->map($mapped, $item_data);
        $this->features_meta_mapper->map($mapped, $item_data);
        $this->taxonomy_mapper->map($mapped, $item_data);
        $this->image_mapper->map($mapped, $item_data);

        return $mapped;
    }
}
