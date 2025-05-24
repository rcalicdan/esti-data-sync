<?php
if (!defined('ABSPATH')) {
    exit;
}

class Esti_Property_Details_Meta_Mapper_Service
{
    private Esti_Sanitizer_Service $sanitizer;
    private Esti_Dictionary_Service $dictionary_service;

    public function __construct(Esti_Sanitizer_Service $sanitizer, Esti_Dictionary_Service $dictionary_service)
    {
        $this->sanitizer = $sanitizer;
        $this->dictionary_service = $dictionary_service;
    }

    public function map_identifiers_and_defaults(array &$meta, array $item_data): void
    {
        $meta[HouzezMetaKey::JSON_ID->value] = $this->sanitizer->s_int($item_data['id'] ?? 0);
        $meta[HouzezMetaKey::PROPERTY_ID->value] = $this->sanitizer->s_text($item_data['number'] ?? ($item_data['id'] ?? ''));
        
        $meta[HouzezMetaKey::HOMESLIDER->value] = 'no'; 
        // $meta[HouzezMetaKey::AGENT_DISPLAY_OPTION->value] = 'agent_info';
    }

    public function map_price(array &$meta, array $item_data): void
    {
        if (isset($item_data['price'])) {
            $price_value = $this->sanitizer->s_price($item_data['price']);
            if ($price_value !== '' && $price_value !== null) { 
                 $meta[HouzezMetaKey::PRICE->value] = $price_value;
            }
        }

        if (isset($item_data['priceCurrency'])) {
            $currency_symbol = $this->dictionary_service->get_dict_value(DictionaryKey::CURRENCY, $item_data['priceCurrency']);
            if ($currency_symbol) {
                $meta[HouzezMetaKey::CURRENCY->value] = $currency_symbol;
                $meta[HouzezMetaKey::PRICE_PREFIX->value] = $currency_symbol;
            }
        }

        if (isset($item_data['pricePermeter']) && $this->sanitizer->s_float($item_data['pricePermeter']) > 0) {
            $meta[HouzezMetaKey::SECOND_PRICE->value] = $this->sanitizer->s_price($item_data['pricePermeter']);
            $meta[HouzezMetaKey::PRICE_POSTFIX->value] = 'm²'; 
        }
    }

    public function map_size_area(array &$meta, array $item_data): void
    {
        if (isset($item_data['areaTotal']) && $this->sanitizer->s_float($item_data['areaTotal']) > 0) {
            $meta[HouzezMetaKey::SIZE->value] = $this->sanitizer->s_text($item_data['areaTotal']); 
            $meta[HouzezMetaKey::SIZE_PREFIX->value] = 'm²'; 
        }
        
        if (isset($item_data['areaPlot']) && $this->sanitizer->s_float($item_data['areaPlot']) > 0) {
            $meta[HouzezMetaKey::LAND_AREA->value] = $this->sanitizer->s_text($item_data['areaPlot']); 
            $meta[HouzezMetaKey::LAND_AREA_POSTFIX->value] = 'm²'; 
        }
    }

    public function map_room_counts(array &$meta, array $item_data): void
    {
        $room_count_fields = [
            'apartmentRoomNumber' => HouzezMetaKey::ROOMS,
            'apartmentBedroomNumber' => HouzezMetaKey::BEDROOMS,
            'apartmentBathroomNumber' => HouzezMetaKey::BATHROOMS,
            'apartmentToiletNumber' => HouzezMetaKey::RESTROOMS 
        ];

        foreach ($room_count_fields as $source_field => $target_meta_key) {
            if (isset($item_data[$source_field]) && $this->sanitizer->s_int($item_data[$source_field]) >= 0) { 
                $meta[$target_meta_key->value] = $this->sanitizer->s_int($item_data[$source_field]);
            }
        }
    }

    public function map_building_details(array &$meta, array $item_data): void
    {
        $building_fields = [
            'buildingYear' => HouzezMetaKey::YEAR_BUILT,
            'apartmentFloor' => HouzezMetaKey::FLOOR_NO,
            'buildingFloornumber' => HouzezMetaKey::TOTAL_FLOORS
        ];
        foreach ($building_fields as $source_field => $target_meta_key) {
            if (isset($item_data[$source_field]) && $this->sanitizer->s_int($item_data[$source_field]) > 0) { // Year/floor usually > 0
                $meta[$target_meta_key->value] = $this->sanitizer->s_int($item_data[$source_field]);
            }
        }
    }

    public function map_garage_info(array &$meta, array $item_data): void
    {
        $garage_count = 0;

        if (isset($item_data['additionalGarage']) && $this->sanitizer->s_int($item_data['additionalGarage']) > 0) {
            $garage_count = $this->sanitizer->s_int($item_data['additionalGarage']);
        } elseif (isset($item_data['additionalParkingunderground']) && $this->sanitizer->s_int($item_data['additionalParkingunderground']) === 1) {
            $garage_count = 1; 
        }

        if ($garage_count > 0) {
            $meta[HouzezMetaKey::GARAGE_NUMBER->value] = (string)$garage_count;
        }
    }

    public function map_featured_property(array &$meta, array $item_data): void
    {
        $is_featured_flag = $item_data['isFeatured'] ?? ($item_data['labelNew'] ?? 0);

        if ($this->sanitizer->s_int($is_featured_flag) === 1 || 
            (defined('JsonFeedCode::LABEL_NEW') && $this->sanitizer->s_int($is_featured_flag) === JsonFeedCode::LABEL_NEW->value)) {
            $meta[HouzezMetaKey::FEATURED_PROPERTY->value] = '1';
        } else {
            $meta[HouzezMetaKey::FEATURED_PROPERTY->value] = '0';
        }
    }
}