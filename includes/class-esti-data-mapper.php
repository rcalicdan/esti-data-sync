<?php
if (!defined('ABSPATH')) {
    exit;
}
class Esti_Data_Mapper
{
    private array $dictionary_data;

    public function __construct(array $dictionary_data)
    {
        $this->dictionary_data = $dictionary_data;
    }

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

    private function _s_text($value): string
    {
        return sanitize_text_field((string) $value);
    }

    private function _s_int($value): int
    {
        return intval($value);
    }

    private function _s_float($value): float
    {
        return floatval(str_replace(',', '.', (string) $value));
    }


    private function _s_price($value): string
    {
        $cleaned_value = preg_replace('/[^\d.]/', '', (string) $value);
        return sanitize_text_field($cleaned_value);
    }

    private function _get_dict_value(DictionaryKey $dictionary_key_enum, $item_value_key, string $default = ''): string
    {
        if ($item_value_key === null || $item_value_key === '') {
            return $default;
        }
        if (isset($this->dictionary_data[$dictionary_key_enum->value][$item_value_key])) {
            return $this->_s_text($this->dictionary_data[$dictionary_key_enum->value][$item_value_key]);
        }
        return $default;
    }

    private function _map_core_post_args(array &$mapped, array $item_data): void
    {
        $mapped['post_args']['post_title']   = $this->_s_text($item_data['portalTitle'] ?? 'Property ' . ($item_data['id'] ?? 'Unknown'));
        $description = !empty($item_data['descriptionWebsite']) ? $item_data['descriptionWebsite'] : ($item_data['description'] ?? '');
        $mapped['post_args']['post_content'] = wp_kses_post($description);
        $mapped['post_args']['post_status']  = 'publish';
        $mapped['post_args']['post_type']    = HouzezWpEntity::POST_TYPE_PROPERTY->value;

        if (! empty($item_data['addDate'])) {
            $date = new DateTime($item_data['addDate']);
            $mapped['post_args']['post_date'] = $date->format('Y-m-d H:i:s');
            $mapped['post_args']['post_date_gmt'] = get_gmt_from_date($mapped['post_args']['post_date']);
        }
        if (! empty($item_data['updateDate'])) {
            $date = new DateTime($item_data['updateDate']);
            $mapped['post_args']['post_modified'] = $date->format('Y-m-d H:i:s');
            $mapped['post_args']['post_modified_gmt'] = get_gmt_from_date($mapped['post_args']['post_modified']);
        }
    }

    private function _map_meta_input(array &$mapped, array $item_data): void
    {
        // --- Core Identifiers ---
        $mapped['meta_input'][HouzezMetaKey::JSON_ID->value] = $this->_s_int($item_data['id'] ?? 0);
        $mapped['meta_input'][HouzezMetaKey::PROPERTY_ID->value] = $this->_s_text($item_data['number'] ?? ($item_data['id'] ?? ''));

        // --- Price Information ---
        if (isset($item_data['price'])) {
            $mapped['meta_input'][HouzezMetaKey::PRICE->value] = $this->_s_price($item_data['price']);
        }
        if (isset($item_data['priceCurrency'])) {
            $currency_symbol = $this->_get_dict_value(DictionaryKey::CURRENCY, $item_data['priceCurrency']);
            if ($currency_symbol) {
                $mapped['meta_input'][HouzezMetaKey::CURRENCY->value] = $currency_symbol;
                $mapped['meta_input'][HouzezMetaKey::PRICE_PREFIX->value] = $currency_symbol;
            }
        }
        if (isset($item_data['pricePermeter']) && $this->_s_float($item_data['pricePermeter']) > 0) {
            $mapped['meta_input'][HouzezMetaKey::SECOND_PRICE->value] = $this->_s_price($item_data['pricePermeter']);
            $mapped['meta_input'][HouzezMetaKey::PRICE_POSTFIX->value] = 'm²'; // 
        }

        // --- Size & Area ---
        if (isset($item_data['areaTotal']) && $this->_s_float($item_data['areaTotal']) > 0) {
            $mapped['meta_input'][HouzezMetaKey::SIZE->value] = $this->_s_text($item_data['areaTotal']);
            $mapped['meta_input'][HouzezMetaKey::SIZE_PREFIX->value] = 'm²';
        }
        if (isset($item_data['areaPlot']) && $this->_s_float($item_data['areaPlot']) > 0) {
            $mapped['meta_input'][HouzezMetaKey::LAND_AREA->value] = $this->_s_text($item_data['areaPlot']);
            $mapped['meta_input'][HouzezMetaKey::LAND_AREA_POSTFIX->value] = 'm²';
        }

        // --- Room Counts ---
        if (isset($item_data['apartmentRoomNumber'])) {
            $mapped['meta_input'][HouzezMetaKey::ROOMS->value] = $this->_s_int($item_data['apartmentRoomNumber']);
        }
        if (isset($item_data['apartmentBedroomNumber'])) {
            $mapped['meta_input'][HouzezMetaKey::BEDROOMS->value] = $this->_s_int($item_data['apartmentBedroomNumber']);
        }
        if (isset($item_data['apartmentBathroomNumber'])) {
            $mapped['meta_input'][HouzezMetaKey::BATHROOMS->value] = $this->_s_int($item_data['apartmentBathroomNumber']);
        }
        if (isset($item_data['apartmentToiletNumber'])) {
            $mapped['meta_input'][HouzezMetaKey::RESTROOMS->value] = $this->_s_int($item_data['apartmentToiletNumber']);
        }

        // --- Other Details ---
        if (isset($item_data['buildingYear'])) {
            $mapped['meta_input'][HouzezMetaKey::YEAR_BUILT->value] = $this->_s_int($item_data['buildingYear']);
        }
        if (isset($item_data['apartmentFloor'])) {
            $mapped['meta_input'][HouzezMetaKey::FLOOR_NO->value] = $this->_s_int($item_data['apartmentFloor']);
        }
        if (isset($item_data['buildingFloornumber'])) {
            $mapped['meta_input'][HouzezMetaKey::TOTAL_FLOORS->value] = $this->_s_int($item_data['buildingFloornumber']);
        }

        // --- Additional Features ---
        $garage_count = 0;
        if (isset($item_data['additionalGarage']) && $this->_s_int($item_data['additionalGarage']) > 0) {
            $garage_count = $this->_s_int($item_data['additionalGarage']);
        } elseif (isset($item_data['additionalParkingunderground']) && $item_data['additionalParkingunderground'] == 1) {
            $garage_count = 1;
        }
        if ($garage_count > 0) {
            $mapped['meta_input'][HouzezMetaKey::GARAGE_NUMBER->value] = (string)$garage_count;
        }

        // --- Location & Map ---
        $this->_map_address_meta($mapped, $item_data);
        $latitude  = !empty($item_data['locationLatitude']) ? $this->_s_text($item_data['locationLatitude']) : '';
        $longitude = !empty($item_data['locationLongitude']) ? $this->_s_text($item_data['locationLongitude']) : '';

        if (!empty($latitude) && !empty($longitude)) {
            $lat_float = filter_var($latitude, FILTER_VALIDATE_FLOAT);
            $long_float = filter_var($longitude, FILTER_VALIDATE_FLOAT);

            if ($lat_float !== false && $long_float !== false) {
                $mapped['meta_input'][HouzezMetaKey::LOCATION_COORDS->value] = "{$latitude},{$longitude},16";
                $mapped['meta_input'][HouzezMetaKey::LATITUDE->value]   = $latitude;
                $mapped['meta_input'][HouzezMetaKey::LONGITUDE->value]  = $longitude;
                $mapped['meta_input'][HouzezMetaKey::MAP_ENABLED->value] = '1';
                $map_display_address = $mapped['meta_input'][HouzezMetaKey::ADDRESS->value] ?? '';
                if (!empty($map_display_address)) {
                    $mapped['meta_input'][HouzezMetaKey::MAP_ADDRESS->value] = $map_display_address;
                }
            } else {
                $mapped['meta_input'][HouzezMetaKey::MAP_ENABLED->value] = '0';
            }
        } else {
            $mapped['meta_input'][HouzezMetaKey::MAP_ENABLED->value] = '0';
        }
        $mapped['meta_input'][HouzezMetaKey::MAP_STREET_VIEW->value] = 'show'; // Default

        $is_featured_flag = $item_data['isFeatured'] ?? ($item_data['labelNew'] ?? 0);
        if ($this->_s_int($is_featured_flag) === 1 || $this->_s_int($is_featured_flag) === JsonFeedCode::LABEL_NEW->value) { // Check isFeatured if it comes as 1
            $mapped['meta_input'][HouzezMetaKey::FEATURED_PROPERTY->value] = '1';
        } else {
            $mapped['meta_input'][HouzezMetaKey::FEATURED_PROPERTY->value] = '0';
        }

        $mapped['meta_input'][HouzezMetaKey::HOMESLIDER->value] = 'no';
        $mapped['meta_input'][HouzezMetaKey::AGENT_DISPLAY_OPTION->value] = 'agent_info'; // Default
    }

    private function _map_address_meta(array &$mapped, array $item_data): void
    {
        $street = $this->_s_text($item_data['locationStreetName'] ?? '');

        if (!empty($street)) {
            $mapped['meta_input'][HouzezMetaKey::ADDRESS->value] = $street;
        }

        if (!empty($item_data['locationPostal'])) {
            $mapped['meta_input'][HouzezMetaKey::ZIP->value] = $this->_s_text($item_data['locationPostal']);
        }

        $map_address_parts = [];
        if (!empty($street)) $map_address_parts[] = $street;
        if (!empty($item_data['locationCityName'])) $map_address_parts[] = $this->_s_text($item_data['locationCityName']);
        if (!empty($item_data['locationProvinceName'])) $map_address_parts[] = $this->_s_text($item_data['locationProvinceName']);
        if (!empty($item_data['locationCountryName'])) $map_address_parts[] = $this->_s_text($item_data['locationCountryName']);

        $full_map_address = implode(', ', array_filter($map_address_parts));
        if (!empty($full_map_address)) {
            $mapped['meta_input'][HouzezMetaKey::MAP_ADDRESS->value] = $full_map_address;
        } elseif (!empty($street)) {
            $mapped['meta_input'][HouzezMetaKey::MAP_ADDRESS->value] = $street;
        }
    }

    private function _map_additional_features_meta(array &$mapped, array $item_data): void
    {
        $additional_features_list = [];
        $property_features_terms = $mapped['tax_input'][HouzezTaxonomy::FEATURE->value] ?? [];

        if (isset($item_data['buildingConditionId'])) {
            $value = $this->_get_dict_value(DictionaryKey::BUILDING_CONDITION, $item_data['buildingConditionId']);
            if ($value) {
                $additional_features_list[] = [
                    'fave_additional_feature_title' => __('Building Condition', 'your-text-domain'),
                    'fave_additional_feature_value' => $value,
                ];
            }
        }

        if (isset($item_data['apartmentOwnership'])) {
            $value = $this->_get_dict_value(DictionaryKey::APARTMENT_OWNERSHIP, $item_data['apartmentOwnership']);
            if ($value) {
                $additional_features_list[] = [
                    'fave_additional_feature_title' => __('Ownership Type', 'your-text-domain'),
                    'fave_additional_feature_value' => $value,
                ];
            }
        }

        if (isset($item_data['apartmentFurnishings'])) {
            $value = $this->_get_dict_value(DictionaryKey::APARTMENT_FURNISHINGS, $item_data['apartmentFurnishings']);
            if ($value) {
                $additional_features_list[] = [
                    'fave_additional_feature_title' => __('Furnishings', 'your-text-domain'),
                    'fave_additional_feature_value' => $value,
                ];
            }
        }

        if (isset($item_data['buildingType'])) {
            $value = $this->_get_dict_value(DictionaryKey::BUILDING_TYPE, $item_data['buildingType']);
            if ($value) {
                $additional_features_list[] = [
                    'fave_additional_feature_title' => __('Building Type', 'your-text-domain'),
                    'fave_additional_feature_value' => $value,
                ];
            }
        }

        if (isset($item_data['buildingMaterial'])) {
            $value = $this->_get_dict_value(DictionaryKey::BUILDING_MATERIAL, $item_data['buildingMaterial']);
            if ($value) {
                $additional_features_list[] = [
                    'fave_additional_feature_title' => __('Building Material', 'your-text-domain'),
                    'fave_additional_feature_value' => $value,
                ];
            }
        }

        if (isset($item_data['availableDate'])) {
            $additional_features_list[] = [
                'fave_additional_feature_title' => __('Available From', 'your-text-domain'),
                'fave_additional_feature_value' => $this->_s_text((new DateTime($item_data['availableDate']))->format('Y-m-d')),
            ];
        }


        if (isset($item_data['buildingHeating'])) {
            $heating_term = $this->_get_dict_value(DictionaryKey::HEATING, $item_data['buildingHeating']);
            if ($heating_term) $property_features_terms[] = $heating_term;
        }

        // Binary features (Yes/No) from JSON sample
        $binary_features_map = [
            'additionalBalcony'         => __('Balcony', 'your-text-domain'),
            'additionalStorage'         => __('Storage Room', 'your-text-domain'),
            'additionalParkingunderground' => __('Underground Parking', 'your-text-domain'),
            'securityIntercom'          => __('Intercom', 'your-text-domain'),
            'securityVideocameras'      => __('Video Cameras', 'your-text-domain'),
            'buildingElevatornumber'    => __('Elevator', 'your-text-domain'),
        ];

        foreach ($binary_features_map as $json_key => $feature_name) {
            if (isset($item_data[$json_key]) && $this->_s_int($item_data[$json_key]) === 1) {
                $property_features_terms[] = $feature_name;
            }
        }

        // Special case for elevator if it's a count
        if (isset($item_data['buildingElevatornumber']) && $this->_s_int($item_data['buildingElevatornumber']) > 0 && !in_array(__('Elevator', 'your-text-domain'), $property_features_terms)) {
            $property_features_terms[] = __('Elevator', 'your-text-domain');
        }

        // --- Storing the mapped features ---
        if (!empty($additional_features_list)) {
            $mapped['meta_input'][HouzezMetaKey::ADDITIONAL_FEATURES_ENABLE->value] = 'enable';
            $mapped['meta_input'][HouzezMetaKey::ADDITIONAL_FEATURES->value] = $additional_features_list;
        } else {
            $mapped['meta_input'][HouzezMetaKey::ADDITIONAL_FEATURES_ENABLE->value] = '';
            $mapped['meta_input'][HouzezMetaKey::ADDITIONAL_FEATURES->value] = [];
        }

        if (!empty($property_features_terms)) {
            $mapped['tax_input'][HouzezTaxonomy::FEATURE->value] = array_unique($property_features_terms);
        }
    }

    private function _map_taxonomy_input(array &$mapped, array $item_data): void
    {
        // Property Type
        if (isset($item_data['mainTypeId'])) {
            $type_name = $this->_get_dict_value(DictionaryKey::TYPES, $item_data['mainTypeId']);
            if ($type_name && strtolower($type_name) !== 'dowolny') {
                $mapped['tax_input'][HouzezTaxonomy::TYPE->value] = $type_name;
            }
        } elseif (! empty($item_data['typeName'])) {
            $mapped['tax_input'][HouzezTaxonomy::TYPE->value] = $this->_s_text($item_data['typeName']);
        }

        // Location Taxonomies
        if (! empty($item_data['locationCityName'])) {
            $mapped['tax_input'][HouzezTaxonomy::CITY->value] = $this->_s_text($item_data['locationCityName']);
        }
        if (! empty($item_data['locationPrecinctName'])) {
            $mapped['tax_input'][HouzezTaxonomy::AREA->value] = $this->_s_text($item_data['locationPrecinctName']);
        }
        if (! empty($item_data['locationProvinceName'])) {
            $mapped['tax_input'][HouzezTaxonomy::STATE->value] = $this->_s_text($item_data['locationProvinceName']);
        }

        if (! empty($item_data['locationCountryName'])) {
            $mapped['tax_input'][HouzezTaxonomy::COUNTRY->value] = $this->_s_text($item_data['locationCountryName']);
            $mapped['meta_input']['fave_property_country'] = $this->_s_text($item_data['locationCountryName']);
        }

        // Property Status (For Sale, For Rent)
        if (isset($item_data['transaction'])) {
            $status_term = $this->_get_transaction_status_term($this->_s_int($item_data['transaction']));
            if ($status_term !== 'Unknown') {
                $mapped['tax_input'][HouzezTaxonomy::STATUS->value] = $status_term;
            }
        }

        // Property Labels (New, Sold, Reserved, Featured, Market Type)
        $labels = $this->_get_property_labels($item_data);
        if (! empty($labels)) {
            $mapped['tax_input'][HouzezTaxonomy::LABEL->value] = $labels;
        }
    }

    private function _get_transaction_status_term(int $transaction_code): string
    {
        if ($transaction_code === JsonFeedCode::TRANSACTION_FOR_SALE->value) {
            return 'For Sale';
        }
        if ($transaction_code === JsonFeedCode::TRANSACTION_FOR_RENT->value) {
            return 'For Rent';
        }
        return 'Unknown';
    }

    private function _get_property_labels(array $item_data): array
    {
        $labels = [];
        if (isset($item_data['labelNew']) && $this->_s_int($item_data['labelNew']) === JsonFeedCode::LABEL_NEW->value) {
            $labels[] = 'New';
        }
        if (isset($item_data['labelSold']) && $this->_s_int($item_data['labelSold']) === JsonFeedCode::LABEL_SOLD->value) {
            $labels[] = 'Sold';
        }
        if (isset($item_data['labelReserved']) && $this->_s_int($item_data['labelReserved']) === JsonFeedCode::LABEL_RESERVED->value) {
            $labels[] = 'Reserved';
        }

        if (isset($item_data['market'])) {
            $market_name = $this->_get_dict_value(DictionaryKey::MARKET, $item_data['market']);
            if ($market_name && strtolower($market_name) !== 'dowolny') {
                $labels[] = $market_name;
            }
        }

        if (($mapped['meta_input'][HouzezMetaKey::FEATURED_PROPERTY->value] ?? '0') === '1') {
            $labels[] = 'Featured';
        }

        return array_unique($labels);
    }

    private function _map_images(array &$mapped, array $item_data): void
    {
        $pictures = $item_data['pictures'] ?? [];
        if (is_array($pictures) && ! empty($pictures)) {
            if (! empty($pictures[0]) && filter_var($pictures[0], FILTER_VALIDATE_URL)) {
                $mapped['featured_image_url'] = esc_url_raw(trim($pictures[0]));
            }

            $mapped['gallery_image_urls'] = array_map(fn($url) => esc_url_raw(trim($url)), $pictures);
            $mapped['gallery_image_urls'] = array_filter($mapped['gallery_image_urls'], fn($url) => filter_var($url, FILTER_VALIDATE_URL));
        }
    }
}
