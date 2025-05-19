<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Maps external property data to WordPress formats.
 * 
 * Transforms raw property data into structured arrays for WordPress posts,
 * meta fields, taxonomies, and media attachments.
 */
class Esti_Data_Mapper
{
    /**
     * @var array Dictionary data for mapping external values to internal formats
     */
    private array $dictionary_data;

    /**
     * Constructor.
     * 
     * @param array $dictionary_data Dictionary lookup data for mapping values
     */
    public function __construct(array $dictionary_data)
    {
        $this->dictionary_data = $dictionary_data;
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

        $this->_map_core_post_args($mapped, $item_data);
        $this->_map_meta_input($mapped, $item_data);
        $this->_map_taxonomy_input($mapped, $item_data);
        $this->_map_additional_features_meta($mapped, $item_data);
        $this->_map_images($mapped, $item_data);
        // $this->_map_agent_info($mapped, $item_data);

        return $mapped;
    }

    /**
     * Sanitizes text input.
     * 
     * @param mixed $value Input value
     * @return string Sanitized text
     */
    private function _s_text($value): string
    {
        return sanitize_text_field((string) $value);
    }

    /**
     * Converts value to integer.
     * 
     * @param mixed $value Input value
     * @return int Integer value
     */
    private function _s_int($value): int
    {
        return intval($value);
    }

    /**
     * Converts value to float.
     * 
     * @param mixed $value Input value
     * @return float Float value
     */
    private function _s_float($value): float
    {
        return floatval(str_replace(',', '.', (string) $value));
    }

    /**
     * Sanitizes price value.
     * 
     * @param mixed $value Input price value
     * @return string Sanitized price
     */
    private function _s_price($value): string
    {
        $cleaned_value = preg_replace('/[^\d.]/', '', (string) $value);
        return sanitize_text_field($cleaned_value);
    }

    /**
     * Retrieves value from dictionary by key.
     * 
     * @param DictionaryKey $dictionary_key_enum Dictionary key enum
     * @param mixed $item_value_key Value key to look up
     * @param string $default Default value if not found
     * @return string Dictionary value or default
     */
    private function _get_dict_value(DictionaryKey $dictionary_key_enum, $item_value_key, string $default = ''): string
    {
        if ($item_value_key === null || $item_value_key === '') {
            return $default;
        }

        $dictionary_key = $dictionary_key_enum->value;
        if (!isset($this->dictionary_data[$dictionary_key][$item_value_key])) {
            return $default;
        }

        return $this->_s_text($this->dictionary_data[$dictionary_key][$item_value_key]);
    }

    /**
     * Maps core post data (title, content, status, etc.).
     * 
     * @param array $mapped Reference to mapped data array
     * @param array $item_data Raw property data
     */
    private function _map_core_post_args(array &$mapped, array $item_data): void
    {
        // Set post title
        $title = $item_data['portalTitle'] ?? 'Property ' . ($item_data['id'] ?? 'Unknown');
        $mapped['post_args']['post_title'] = $this->_s_text($title);

        // Set post content
        $description = $item_data['descriptionWebsite'] ?? $item_data['description'] ?? '';
        $mapped['post_args']['post_content'] = wp_kses_post($description);

        // Set post type and status
        $mapped['post_args']['post_status'] = 'publish';
        $mapped['post_args']['post_type'] = HouzezWpEntity::POST_TYPE_PROPERTY->value;

        // Add dates if available
        $this->_add_date_fields($mapped, $item_data);
    }

    /**
     * Adds date fields to post args if available.
     * 
     * @param array $mapped Reference to mapped data array
     * @param array $item_data Raw property data
     */
    private function _add_date_fields(array &$mapped, array $item_data): void
    {
        if (!empty($item_data['addDate'])) {
            $date = new DateTime($item_data['addDate']);
            $mapped['post_args']['post_date'] = $date->format('Y-m-d H:i:s');
            $mapped['post_args']['post_date_gmt'] = get_gmt_from_date($mapped['post_args']['post_date']);
        }

        if (!empty($item_data['updateDate'])) {
            $date = new DateTime($item_data['updateDate']);
            $mapped['post_args']['post_modified'] = $date->format('Y-m-d H:i:s');
            $mapped['post_args']['post_modified_gmt'] = get_gmt_from_date($mapped['post_args']['post_modified']);
        }
    }

    /**
     * Maps property data to meta fields.
     * 
     * @param array $mapped Reference to mapped data array
     * @param array $item_data Raw property data
     */
    private function _map_meta_input(array &$mapped, array $item_data): void
    {
        $meta = &$mapped['meta_input'];

        // Core Identifiers
        $meta[HouzezMetaKey::JSON_ID->value] = $this->_s_int($item_data['id'] ?? 0);
        $meta[HouzezMetaKey::PROPERTY_ID->value] = $this->_s_text($item_data['number'] ?? ($item_data['id'] ?? ''));

        $this->_map_price_meta($meta, $item_data);
        $this->_map_size_area_meta($meta, $item_data);
        $this->_map_room_counts_meta($meta, $item_data);
        $this->_map_building_details_meta($meta, $item_data);
        $this->_map_garage_info($meta, $item_data);
        $this->_map_address_meta($mapped, $item_data);
        $this->_map_featured_property_meta($meta, $item_data);

        // Default values
        $meta[HouzezMetaKey::HOMESLIDER->value] = 'no';
        $meta[HouzezMetaKey::AGENT_DISPLAY_OPTION->value] = 'agent_info';
    }

    /**
     * Maps price information to meta fields.
     * 
     * @param array $meta Reference to meta array
     * @param array $item_data Raw property data
     */
    private function _map_price_meta(array &$meta, array $item_data): void
    {
        // Base price
        if (isset($item_data['price'])) {
            $meta[HouzezMetaKey::PRICE->value] = $this->_s_price($item_data['price']);
        }

        // Currency information
        if (isset($item_data['priceCurrency'])) {
            $currency_symbol = $this->_get_dict_value(DictionaryKey::CURRENCY, $item_data['priceCurrency']);
            if ($currency_symbol) {
                $meta[HouzezMetaKey::CURRENCY->value] = $currency_symbol;
                $meta[HouzezMetaKey::PRICE_PREFIX->value] = $currency_symbol;
            }
        }

        // Price per meter
        if (isset($item_data['pricePermeter']) && $this->_s_float($item_data['pricePermeter']) > 0) {
            $meta[HouzezMetaKey::SECOND_PRICE->value] = $this->_s_price($item_data['pricePermeter']);
            $meta[HouzezMetaKey::PRICE_POSTFIX->value] = 'm²';
        }
    }

    /**
     * Maps size and area information to meta fields.
     * 
     * @param array $meta Reference to meta array
     * @param array $item_data Raw property data
     */
    private function _map_size_area_meta(array &$meta, array $item_data): void
    {
        // Total area
        if (isset($item_data['areaTotal']) && $this->_s_float($item_data['areaTotal']) > 0) {
            $meta[HouzezMetaKey::SIZE->value] = $this->_s_text($item_data['areaTotal']);
            $meta[HouzezMetaKey::SIZE_PREFIX->value] = 'm²';
        }

        // Plot area
        if (isset($item_data['areaPlot']) && $this->_s_float($item_data['areaPlot']) > 0) {
            $meta[HouzezMetaKey::LAND_AREA->value] = $this->_s_text($item_data['areaPlot']);
            $meta[HouzezMetaKey::LAND_AREA_POSTFIX->value] = 'm²';
        }
    }

    /**
     * Maps room count information to meta fields.
     * 
     * @param array $meta Reference to meta array
     * @param array $item_data Raw property data
     */
    private function _map_room_counts_meta(array &$meta, array $item_data): void
    {
        $room_count_fields = [
            'apartmentRoomNumber' => HouzezMetaKey::ROOMS,
            'apartmentBedroomNumber' => HouzezMetaKey::BEDROOMS,
            'apartmentBathroomNumber' => HouzezMetaKey::BATHROOMS,
            'apartmentToiletNumber' => HouzezMetaKey::RESTROOMS
        ];

        foreach ($room_count_fields as $source_field => $target_meta_key) {
            if (isset($item_data[$source_field])) {
                $meta[$target_meta_key->value] = $this->_s_int($item_data[$source_field]);
            }
        }
    }

    /**
     * Maps building details to meta fields.
     * 
     * @param array $meta Reference to meta array
     * @param array $item_data Raw property data
     */
    private function _map_building_details_meta(array &$meta, array $item_data): void
    {
        $building_fields = [
            'buildingYear' => HouzezMetaKey::YEAR_BUILT,
            'apartmentFloor' => HouzezMetaKey::FLOOR_NO,
            'buildingFloornumber' => HouzezMetaKey::TOTAL_FLOORS
        ];

        foreach ($building_fields as $source_field => $target_meta_key) {
            if (isset($item_data[$source_field])) {
                $meta[$target_meta_key->value] = $this->_s_int($item_data[$source_field]);
            }
        }
    }

    /**
     * Maps garage information to meta fields.
     * 
     * @param array $meta Reference to meta array
     * @param array $item_data Raw property data
     */
    private function _map_garage_info(array &$meta, array $item_data): void
    {
        $garage_count = 0;

        if (isset($item_data['additionalGarage']) && $this->_s_int($item_data['additionalGarage']) > 0) {
            $garage_count = $this->_s_int($item_data['additionalGarage']);
        } elseif (isset($item_data['additionalParkingunderground']) && $item_data['additionalParkingunderground'] == 1) {
            $garage_count = 1;
        }

        if ($garage_count > 0) {
            $meta[HouzezMetaKey::GARAGE_NUMBER->value] = (string)$garage_count;
        }
    }

    /**
     * Maps featured property flag to meta fields.
     * 
     * @param array $meta Reference to meta array
     * @param array $item_data Raw property data
     */
    private function _map_featured_property_meta(array &$meta, array $item_data): void
    {
        $is_featured_flag = $item_data['isFeatured'] ?? ($item_data['labelNew'] ?? 0);

        if ($this->_s_int($is_featured_flag) === 1 || $this->_s_int($is_featured_flag) === JsonFeedCode::LABEL_NEW->value) {
            $meta[HouzezMetaKey::FEATURED_PROPERTY->value] = '1';
        } else {
            $meta[HouzezMetaKey::FEATURED_PROPERTY->value] = '0';
        }
    }

    /**
     * Maps address information to meta fields.
     * 
     * @param array $mapped Reference to mapped data array
     * @param array $item_data Raw property data
     */
    private function _map_address_meta(array &$mapped, array $item_data): void
    {
        $meta = &$mapped['meta_input'];
        $street = $this->_s_text($item_data['locationStreetName'] ?? '');

        // Store street address
        if (!empty($street)) {
            $meta[HouzezMetaKey::ADDRESS->value] = $street;
        }

        // Store postal code
        if (!empty($item_data['locationPostal'])) {
            $meta[HouzezMetaKey::ZIP->value] = $this->_s_text($item_data['locationPostal']);
        }

        // Build full address for map
        $this->_set_map_address($meta, $street, $item_data);

        // Handle location coordinates
        $this->_set_location_coordinates($meta, $item_data);
    }

    /**
     * Sets the map address from available parts.
     * 
     * @param array $meta Reference to meta array
     * @param string $street Street address
     * @param array $item_data Raw property data
     */
    private function _set_map_address(array &$meta, string $street, array $item_data): void
    {
        $map_address_parts = [];

        if (!empty($street)) $map_address_parts[] = $street;
        if (!empty($item_data['locationCityName'])) $map_address_parts[] = $this->_s_text($item_data['locationCityName']);
        if (!empty($item_data['locationProvinceName'])) $map_address_parts[] = $this->_s_text($item_data['locationProvinceName']);
        if (!empty($item_data['locationCountryName'])) $map_address_parts[] = $this->_s_text($item_data['locationCountryName']);

        $full_map_address = implode(', ', array_filter($map_address_parts));

        if (!empty($full_map_address)) {
            $meta[HouzezMetaKey::MAP_ADDRESS->value] = $full_map_address;
        } elseif (!empty($street)) {
            $meta[HouzezMetaKey::MAP_ADDRESS->value] = $street;
        }
    }

    /**
     * Sets location coordinates and map settings.
     * 
     * @param array $meta Reference to meta array
     * @param array $item_data Raw property data
     */
    private function _set_location_coordinates(array &$meta, array $item_data): void
    {
        $latitude = !empty($item_data['locationLatitude']) ? $this->_s_text($item_data['locationLatitude']) : '';
        $longitude = !empty($item_data['locationLongitude']) ? $this->_s_text($item_data['locationLongitude']) : '';

        $has_valid_coordinates = false;

        if (!empty($latitude) && !empty($longitude)) {
            $lat_float = filter_var($latitude, FILTER_VALIDATE_FLOAT);
            $long_float = filter_var($longitude, FILTER_VALIDATE_FLOAT);

            if ($lat_float !== false && $long_float !== false) {
                $meta[HouzezMetaKey::LOCATION_COORDS->value] = "{$latitude},{$longitude},16";
                $meta[HouzezMetaKey::LATITUDE->value] = $latitude;
                $meta[HouzezMetaKey::LONGITUDE->value] = $longitude;
                $has_valid_coordinates = true;
            }
        }

        $meta[HouzezMetaKey::MAP_ENABLED->value] = $has_valid_coordinates ? '1' : '0';
        $meta[HouzezMetaKey::MAP_STREET_VIEW->value] = 'show';
    }

    /**
     * Maps additional features to meta fields.
     * 
     * @param array $mapped Reference to mapped data array
     * @param array $item_data Raw property data
     */
    private function _map_additional_features_meta(array &$mapped, array $item_data): void
    {
        $additional_features_list = [];
        $property_features_terms = $mapped['tax_input'][HouzezTaxonomy::FEATURE->value] ?? [];

        // Dictionary-based features
        $this->_add_dictionary_features($additional_features_list, $item_data);

        // Add available date if present
        if (isset($item_data['availableDate'])) {
            $additional_features_list[] = [
                'fave_additional_feature_title' => __('Available From', 'your-text-domain'),
                'fave_additional_feature_value' => $this->_s_text((new DateTime($item_data['availableDate']))->format('Y-m-d')),
            ];
        }

        // Add heating term if available
        if (isset($item_data['buildingHeating'])) {
            $heating_term = $this->_get_dict_value(DictionaryKey::HEATING, $item_data['buildingHeating']);
            if ($heating_term) $property_features_terms[] = $heating_term;
        }

        // Add binary features as terms
        $this->_add_binary_features_terms($property_features_terms, $item_data);

        // Store the mapped features
        $this->_store_additional_features($mapped, $additional_features_list, $property_features_terms);
    }

    /**
     * Adds dictionary-based additional features.
     * 
     * @param array $features_list Reference to features list
     * @param array $item_data Raw property data
     */
    private function _add_dictionary_features(array &$features_list, array $item_data): void
    {
        $dictionary_features = [
            'buildingConditionId' => [
                'dictionary' => DictionaryKey::BUILDING_CONDITION,
                'label' => __('Building Condition', 'your-text-domain')
            ],
            'apartmentOwnership' => [
                'dictionary' => DictionaryKey::APARTMENT_OWNERSHIP,
                'label' => __('Ownership Type', 'your-text-domain')
            ],
            'apartmentFurnishings' => [
                'dictionary' => DictionaryKey::APARTMENT_FURNISHINGS,
                'label' => __('Furnishings', 'your-text-domain')
            ],
            'buildingType' => [
                'dictionary' => DictionaryKey::BUILDING_TYPE,
                'label' => __('Building Type', 'your-text-domain')
            ],
            'buildingMaterial' => [
                'dictionary' => DictionaryKey::BUILDING_MATERIAL,
                'label' => __('Building Material', 'your-text-domain')
            ]
        ];

        foreach ($dictionary_features as $key => $feature_info) {
            if (!isset($item_data[$key])) {
                continue;
            }

            $value = $this->_get_dict_value($feature_info['dictionary'], $item_data[$key]);
            
            if (empty($value)) {
                continue;
            }

            $features_list[] = [
                'fave_additional_feature_title' => $feature_info['label'],
                'fave_additional_feature_value' => $value,
            ];
        }
    }

    /**
     * Adds binary features as taxonomy terms.
     * 
     * @param array $terms_list Reference to terms list
     * @param array $item_data Raw property data
     */
    private function _add_binary_features_terms(array &$terms_list, array $item_data): void
    {
        $binary_features_map = [
            'additionalBalcony'         => __('Balcony', 'your-text-domain'),
            'additionalStorage'         => __('Storage Room', 'your-text-domain'),
            'additionalParkingunderground' => __('Underground Parking', 'your-text-domain'),
            'securityIntercom'          => __('Intercom', 'your-text-domain'),
            'securityVideocameras'      => __('Video Cameras', 'your-text-domain'),
        ];

        foreach ($binary_features_map as $json_key => $feature_name) {
            if (!isset($item_data[$json_key]) || $this->_s_int($item_data[$json_key]) !== 1) {
                continue;
            }

            $terms_list[] = $feature_name;
        }

        // Special case for elevator
        $this->_handle_elevator_feature($terms_list, $item_data);
    }

    /**
     * Handles the special case for elevator feature.
     * 
     * @param array $terms_list Reference to terms list
     * @param array $item_data Raw property data
     */
    private function _handle_elevator_feature(array &$terms_list, array $item_data): void
    {
        $elevator_term = __('Elevator', 'your-text-domain');

        $has_elevator_term = in_array($elevator_term, $terms_list);
        $has_elevator_value = isset($item_data['buildingElevatornumber']) && $this->_s_int($item_data['buildingElevatornumber']) > 0;

        if ($has_elevator_value && !$has_elevator_term) {
            $terms_list[] = $elevator_term;
        }
    }

    /**
     * Stores additional features in mapped data.
     * 
     * @param array $mapped Reference to mapped data array
     * @param array $features_list List of additional features
     * @param array $terms_list List of feature terms
     */
    private function _store_additional_features(array &$mapped, array $features_list, array $terms_list): void
    {
        if (!empty($features_list)) {
            $mapped['meta_input'][HouzezMetaKey::ADDITIONAL_FEATURES_ENABLE->value] = 'enable';
            $mapped['meta_input'][HouzezMetaKey::ADDITIONAL_FEATURES->value] = $features_list;
        } else {
            $mapped['meta_input'][HouzezMetaKey::ADDITIONAL_FEATURES_ENABLE->value] = '';
            $mapped['meta_input'][HouzezMetaKey::ADDITIONAL_FEATURES->value] = [];
        }

        if (!empty($terms_list)) {
            $mapped['tax_input'][HouzezTaxonomy::FEATURE->value] = array_unique($terms_list);
        }
    }

    /**
     * Maps property taxonomy terms.
     * 
     * @param array $mapped Reference to mapped data array
     * @param array $item_data Raw property data
     */
    private function _map_taxonomy_input(array &$mapped, array $item_data): void
    {
        $this->_map_property_type($mapped, $item_data);
        $this->_map_location_taxonomies($mapped, $item_data);
        $this->_map_property_status($mapped, $item_data);
        $this->_map_property_labels($mapped, $item_data);
    }

    /**
     * Maps property type taxonomy.
     * 
     * @param array $mapped Reference to mapped data array
     * @param array $item_data Raw property data
     */
    private function _map_property_type(array &$mapped, array $item_data): void
    {
        if (isset($item_data['mainTypeId'])) {
            $type_name = $this->_get_dict_value(DictionaryKey::TYPES, $item_data['mainTypeId']);
            if ($type_name && strtolower($type_name) !== 'dowolny') {
                $mapped['tax_input'][HouzezTaxonomy::TYPE->value] = $type_name;
            }
        } elseif (!empty($item_data['typeName'])) {
            $mapped['tax_input'][HouzezTaxonomy::TYPE->value] = $this->_s_text($item_data['typeName']);
        }
    }

    /**
     * Maps location taxonomies.
     * 
     * @param array $mapped Reference to mapped data array
     * @param array $item_data Raw property data
     */
    private function _map_location_taxonomies(array &$mapped, array $item_data): void
    {
        $location_taxonomies = [
            'locationCityName' => HouzezTaxonomy::CITY,
            'locationPrecinctName' => HouzezTaxonomy::AREA,
            'locationProvinceName' => HouzezTaxonomy::STATE,
            'locationCountryName' => HouzezTaxonomy::COUNTRY
        ];

        foreach ($location_taxonomies as $source_field => $taxonomy) {
            if (empty($item_data[$source_field])) {
                continue;
            }

            $value = $this->_s_text($item_data[$source_field]);
            $mapped['tax_input'][$taxonomy->value] = $value;

            // Special case for country
            if ($source_field === 'locationCountryName') {
                $mapped['meta_input']['fave_property_country'] = $value;
            }
        }
    }

    /**
     * Maps property status taxonomy.
     * 
     * @param array $mapped Reference to mapped data array
     * @param array $item_data Raw property data
     */
    private function _map_property_status(array &$mapped, array $item_data): void
    {
        if (!isset($item_data['transaction'])) {
            return;
        }

        $status_term = $this->_get_transaction_status_term($this->_s_int($item_data['transaction']));

        if ($status_term !== 'Unknown') {
            $mapped['tax_input'][HouzezTaxonomy::STATUS->value] = $status_term;
        }
    }

    /**
     * Maps property labels taxonomy.
     * 
     * @param array $mapped Reference to mapped data array
     * @param array $item_data Raw property data
     */
    private function _map_property_labels(array &$mapped, array $item_data): void
    {
        $labels = $this->_get_property_labels($item_data);

        if (!empty($labels)) {
            $mapped['tax_input'][HouzezTaxonomy::LABEL->value] = $labels;
        }
    }

    /**
     * Determines transaction status term based on code.
     * 
     * @param int $transaction_code Transaction code
     * @return string Status term
     */
    private function _get_transaction_status_term(int $transaction_code): string
    {
        $transaction_map = [
            JsonFeedCode::TRANSACTION_FOR_SALE->value => 'For Sale',
            JsonFeedCode::TRANSACTION_FOR_RENT->value => 'For Rent'
        ];

        return $transaction_map[$transaction_code] ?? 'Unknown';
    }

    /**
     * Gets property label terms from raw data.
     * 
     * @param array $item_data Raw property data
     * @return array Property label terms
     */
    private function _get_property_labels(array $item_data): array
    {
        $labels = [];

        // Label flags
        $label_flags = [
            'labelNew' => ['value' => JsonFeedCode::LABEL_NEW->value, 'label' => 'New'],
            'labelSold' => ['value' => JsonFeedCode::LABEL_SOLD->value, 'label' => 'Sold'],
            'labelReserved' => ['value' => JsonFeedCode::LABEL_RESERVED->value, 'label' => 'Reserved']
        ];

        foreach ($label_flags as $key => $info) {
            if (isset($item_data[$key]) && $this->_s_int($item_data[$key]) === $info['value']) {
                $labels[] = $info['label'];
            }
        }

        // Market type
        if (isset($item_data['market'])) {
            $market_name = $this->_get_dict_value(DictionaryKey::MARKET, $item_data['market']);
            if ($market_name && strtolower($market_name) !== 'dowolny') {
                $labels[] = $market_name;
            }
        }

        // Featured properties also get a label
        if (($mapped['meta_input'][HouzezMetaKey::FEATURED_PROPERTY->value] ?? '0') === '1') {
            $labels[] = 'Featured';
        }

        return array_unique($labels);
    }

    /**
     * Maps property images.
     * 
     * @param array $mapped Reference to mapped data array
     * @param array $item_data Raw property data
     */
    private function _map_images(array &$mapped, array $item_data): void
    {
        $pictures = $item_data['pictures'] ?? [];

        if (!is_array($pictures) || empty($pictures)) {
            return;
        }

        // Set featured image if available
        if (!empty($pictures[0]) && filter_var($pictures[0], FILTER_VALIDATE_URL)) {
            $mapped['featured_image_url'] = esc_url_raw(trim($pictures[0]));
        }

        // Process gallery images
        $gallery_urls = array_map(fn($url) => esc_url_raw(trim($url)), $pictures);
        $mapped['gallery_image_urls'] = array_filter($gallery_urls, fn($url) => filter_var($url, FILTER_VALIDATE_URL));
    }
}
