<?php  

class Esti_Data_Mapper
{
    private array $dictionary_data;

    /**
     * Constructor.
     * @param array $dictionary_data The decoded JSON dictionary for lookups.
     */
    public function __construct(array $dictionary_data)
    {
        $this->dictionary_data = $dictionary_data;
    }

    /**
     * Maps JSON item data to WordPress post arguments and meta data.
     * @param array $item_data A single item from the JSON.
     * @return array Mapped data ['post_args' => [], 'meta_input' => [], 'tax_input' => [], 'featured_image_url' => '', 'gallery_image_urls' => []]
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
        $this->_map_images($mapped, $item_data);

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

    private function _s_price($value): string
    {
        return sanitize_text_field(str_replace(',', '', (string) $value));
    }

    /**
     * Helper to get a value from a specific dictionary section using Enum for key.
     */
    private function _get_dict_value(DictionaryKey $dictionary_key_enum, $item_value_key, string $default = ''): string
    {
        if (isset($this->dictionary_data[$dictionary_key_enum->value][$item_value_key])) {
            return $this->_s_text($this->dictionary_data[$dictionary_key_enum->value][$item_value_key]);
        }
        return $default;
    }

    private function _map_core_post_args(array &$mapped, array $item_data): void
    {
        $mapped['post_args']['post_title']   = $this->_s_text($item_data['portalTitle'] ?? 'Property ' . ($item_data['id'] ?? 'Unknown'));
        $mapped['post_args']['post_content'] = wp_kses_post($item_data['description'] ?? '');
        $mapped['post_args']['post_status']  = 'publish'; // Or 'draft'
        $mapped['post_args']['post_type']    = HouzezWpEntity::POST_TYPE_PROPERTY->value;

        if (! empty($item_data['addDate'])) {
            $mapped['post_args']['post_date'] = $this->_s_text($item_data['addDate']);
        }
        if (! empty($item_data['updateDate'])) {
            $mapped['post_args']['post_modified'] = $this->_s_text($item_data['updateDate']);
        }
    }

    private function _map_meta_input(array &$mapped, array $item_data): void
    {
        $mapped['meta_input'][HouzezMetaKey::JSON_ID->value] = $this->_s_int($item_data['id'] ?? 0);
        $mapped['meta_input'][HouzezMetaKey::PROPERTY_ID->value] = $this->_s_text($item_data['number'] ?? ($item_data['id'] ?? ''));

        // Price
        if (isset($item_data['price'])) {
            $mapped['meta_input'][HouzezMetaKey::PRICE->value] = $this->_s_price($item_data['price']);
        }
        if (isset($item_data['currencyId'])) {
            $currency_symbol = $this->_get_dict_value(DictionaryKey::CURRENCY, $item_data['currencyId']);
            if ($currency_symbol) {
                $mapped['meta_input'][HouzezMetaKey::CURRENCY->value] = $currency_symbol;
                // $mapped['meta_input'][HouzezMetaKey::PRICE_POSTFIX->value] = $currency_symbol; // Or for rental '/month'
            }
        }
        if (isset($item_data['pricePermeter'])) {
            $mapped['meta_input'][HouzezMetaKey::PRICE_PER_SQFT->value] = $this->_s_price($item_data['pricePermeter']);
            $mapped['meta_input'][HouzezMetaKey::PRICE_PER_SQFT_POSTFIX->value] = '/m²'; // Or from settings
        }

        // Size & Area
        if (isset($item_data['areaTotal'])) {
            $mapped['meta_input'][HouzezMetaKey::SIZE->value] = $this->_s_text($item_data['areaTotal']);
            $mapped['meta_input'][HouzezMetaKey::SIZE_POSTFIX->value] = 'm²';
        }
        if (isset($item_data['areaPlot'])) {
            $mapped['meta_input'][HouzezMetaKey::LAND_AREA->value] = $this->_s_text($item_data['areaPlot']);
            $mapped['meta_input'][HouzezMetaKey::LAND_AREA_POSTFIX->value] = 'm²';
        }

        // Rooms
        if (isset($item_data['apartmentBedroomNumber'])) {
            $mapped['meta_input'][HouzezMetaKey::BEDROOMS->value] = $this->_s_int($item_data['apartmentBedroomNumber']);
        }
        if (isset($item_data['apartmentBathroomNumber'])) {
            $mapped['meta_input'][HouzezMetaKey::BATHROOMS->value] = $this->_s_int($item_data['apartmentBathroomNumber']);
        }
        if (isset($item_data['apartmentToiletNumber'])) {
            $mapped['meta_input'][HouzezMetaKey::RESTROOMS->value] = $this->_s_int($item_data['apartmentToiletNumber']);
        }

        // Other details
        if (isset($item_data['buildingYear'])) {
            $mapped['meta_input'][HouzezMetaKey::YEAR_BUILT->value] = $this->_s_int($item_data['buildingYear']);
        }
        if (isset($item_data['apartmentFloor'])) {
            $mapped['meta_input'][HouzezMetaKey::FLOOR_NO->value] = $this->_s_int($item_data['apartmentFloor']);
        }

        // Location & Map
        $this->_map_address_meta($mapped, $item_data);
        $latitude  = $this->_s_text($item_data['locationLatitude'] ?? '');
        $longitude = $this->_s_text($item_data['locationLongitude'] ?? '');

        if (!empty($latitude) && !empty($longitude)) {
            $mapped['meta_input'][HouzezMetaKey::LOCATION_COORDS->value] = "{$latitude},{$longitude},16";
            $mapped['meta_input'][HouzezMetaKey::LATITUDE->value]   = $latitude;
            $mapped['meta_input'][HouzezMetaKey::LONGITUDE->value]  = $longitude;
            $mapped['meta_input'][HouzezMetaKey::MAP_ENABLED->value] = '1';
        } else {
            $mapped['meta_input'][HouzezMetaKey::MAP_ENABLED->value] = '0';
        }
        
        if (isset($item_data['isFeatured']) && $item_data['isFeatured'] == 1) {
           $mapped['meta_input'][HouzezMetaKey::FEATURED_PROPERTY->value] = '1';
        } else {
           $mapped['meta_input'][HouzezMetaKey::FEATURED_PROPERTY->value] = '0';
        }

        $this->_map_additional_features_meta($mapped, $item_data);
    }

    private function _map_address_meta(array &$mapped, array $item_data): void
    {
        $address_parts = [];
        if (! empty($item_data['locationStreetName'])) $address_parts[] = $this->_s_text($item_data['locationStreetName']);
        if (! empty($item_data['locationCityName'])) $address_parts[] = $this->_s_text($item_data['locationCityName']);
        if (! empty($item_data['locationProvinceName'])) $address_parts[] = $this->_s_text($item_data['locationProvinceName']);
        if (! empty($item_data['locationCountryName'])) $address_parts[] = $this->_s_text($item_data['locationCountryName']);

        $full_address = implode(', ', array_filter($address_parts));

        if (! empty($full_address)) {
            $mapped['meta_input'][HouzezMetaKey::ADDRESS->value]     = $full_address;
            $mapped['meta_input'][HouzezMetaKey::MAP_ADDRESS->value] = $full_address;
        }
    }

    private function _map_additional_features_meta(array &$mapped, array $item_data): void
    {
        $additional_features_list = [];
        $property_features_terms = $mapped['tax_input'][HouzezTaxonomy::FEATURE->value] ?? [];

        if (isset($item_data['buildingConditionId'])) {
            $condition_value = $this->_get_dict_value(DictionaryKey::BUILDING_CONDITION, $item_data['buildingConditionId']);
            if ($condition_value) {
                $additional_features_list[] = [
                    'fave_additional_feature_title' => __('Building Condition', 'your-text-domain'), // Make translatable
                    'fave_additional_feature_value' => $condition_value,
                ];
            }
        }

        if (isset($item_data['heatingId'])) {
            $heating_term = $this->_get_dict_value(DictionaryKey::HEATING, $item_data['heatingId']);
            if ($heating_term) {
                $property_features_terms[] = $heating_term;
            }
        }
        
        if (isset($item_data['kitchenTypeId'])) {
            $kitchen_term = $this->_get_dict_value(DictionaryKey::KITCHEN_TYPES, $item_data['kitchenTypeId']);
            if ($kitchen_term) {
                $property_features_terms[] = $kitchen_term;
            }
        }

        if (!empty($item_data['apartmentEquipmentIds']) && is_array($item_data['apartmentEquipmentIds'])) {
            foreach ($item_data['apartmentEquipmentIds'] as $equipment_id) {
                $equipment_term = $this->_get_dict_value(DictionaryKey::APARTMENT_EQUIPMENTS, $equipment_id);
                if ($equipment_term) {
                    $property_features_terms[] = $equipment_term;
                }
            }
        }
        
        // Example for binary features (Tak/Nie)
        if (isset($item_data['hasBalconyId']) && $this->_get_dict_value(DictionaryKey::BINARY, $item_data['hasBalconyId']) === 'Tak') {
            $property_features_terms[] = __('Balcony', 'your-text-domain');
        }


        if (!empty($additional_features_list)) {
            $mapped['meta_input'][HouzezMetaKey::ADDITIONAL_FEATURES_ENABLE->value] = 'enable';
            $mapped['meta_input'][HouzezMetaKey::ADDITIONAL_FEATURES->value] = $additional_features_list;
        }

        if (!empty($property_features_terms)) {
            $mapped['tax_input'][HouzezTaxonomy::FEATURE->value] = array_unique($property_features_terms);
        }
    }

    private function _map_taxonomy_input(array &$mapped, array $item_data): void
    {
        if (isset($item_data['typeId'])) {
            $type_name = $this->_get_dict_value(DictionaryKey::TYPES, $item_data['typeId']);
            if ($type_name && $type_name !== 'dowolny') {
                $mapped['tax_input'][HouzezTaxonomy::TYPE->value] = $type_name;
            }
        } elseif (! empty($item_data['typeName'])) {
             $mapped['tax_input'][HouzezTaxonomy::TYPE->value] = $this->_s_text($item_data['typeName']);
        }

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
        }

        if (isset($item_data['transaction'])) {
            $status_term = $this->_get_transaction_status_term((int) $item_data['transaction']);
            if ($status_term !== 'Unknown') {
                 $mapped['tax_input'][HouzezTaxonomy::STATUS->value] = $status_term;
            }
        }

        $labels = $this->_get_property_labels($item_data);
        if (! empty($labels)) {
            $mapped['tax_input'][HouzezTaxonomy::LABEL->value] = $labels;
        }
    }

    private function _get_transaction_status_term(int $transaction_code): string
    {
        switch ($transaction_code) {
            case JsonFeedCode::TRANSACTION_FOR_SALE->value:
                return 'For Sale';
            case JsonFeedCode::TRANSACTION_FOR_RENT->value:
                return 'For Rent';
            default:
                return 'Unknown';
        }
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

        if (isset($item_data['marketId'])) {
            $market_name = $this->_get_dict_value(DictionaryKey::MARKET, $item_data['marketId']);
            if ($market_name && $market_name !== 'dowolny') {
                $labels[] = $market_name;
            }
        }
        
        if (isset($item_data['isFeatured']) && $item_data['isFeatured'] == 1) {
           $labels[] = 'Featured';
        }

        return array_unique($labels);
    }

    private function _map_images(array &$mapped, array $item_data): void
    {
        $pictures = $item_data['pictures'] ?? [];
        if (is_array($pictures) && ! empty($pictures)) {
            if (! empty($pictures[0])) {
                $mapped['featured_image_url'] = esc_url_raw(trim($pictures[0]));
            }
            $mapped['gallery_image_urls'] = array_map(fn($url) => esc_url_raw(trim($url)), $pictures);
            $mapped['gallery_image_urls'] = array_filter($mapped['gallery_image_urls']);
        }
    }
}