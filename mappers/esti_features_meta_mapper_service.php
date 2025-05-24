<?php
if (!defined('ABSPATH')) {
    exit;
}

class Esti_Features_Meta_Mapper_Service
{
    private Esti_Sanitizer_Service $sanitizer;
    private Esti_Dictionary_Service $dictionary_service;

    public function __construct(Esti_Sanitizer_Service $sanitizer, Esti_Dictionary_Service $dictionary_service)
    {
        $this->sanitizer = $sanitizer;
        $this->dictionary_service = $dictionary_service;
    }

    public function map(array &$mapped, array $item_data): void
    {
        $additional_features_list = [];
        $property_features_terms = $mapped['tax_input'][HouzezTaxonomy::FEATURE->value] ?? [];

        $this->_add_dictionary_features($additional_features_list, $item_data);

        if (isset($item_data['availableDate']) && !empty($item_data['availableDate'])) {
            try {
                $date_obj = new DateTime($item_data['availableDate']);
                $additional_features_list[] = [
                    'fave_additional_feature_title' => __('Available From', 'your-text-domain'),
                    'fave_additional_feature_value' => $this->sanitizer->s_text($date_obj->format('Y-m-d')),
                ];
            } catch (Exception $e) {
                error_log("Error parsing availableDate '{$item_data['availableDate']}': " . $e->getMessage());
            }
        }

        if (isset($item_data['buildingHeating'])) {
            $heating_term = $this->dictionary_service->get_dict_value(DictionaryKey::HEATING, $item_data['buildingHeating']);
            if ($heating_term) {
                $property_features_terms[] = $heating_term;
            }
        }

        $this->_add_binary_features_terms($property_features_terms, $item_data);
        $this->_store_additional_features($mapped, $additional_features_list, $property_features_terms);
    }

    private function _add_dictionary_features(array &$features_list, array $item_data): void
    {
        $dictionary_features = [
            'buildingConditionId' => ['dictionary' => DictionaryKey::BUILDING_CONDITION, 'label' => __('Building Condition', 'your-text-domain')],
            'apartmentOwnership' => ['dictionary' => DictionaryKey::APARTMENT_OWNERSHIP, 'label' => __('Ownership Type', 'your-text-domain')],
            'apartmentFurnishings' => ['dictionary' => DictionaryKey::APARTMENT_FURNISHINGS, 'label' => __('Furnishings', 'your-text-domain')],
            'buildingType' => ['dictionary' => DictionaryKey::BUILDING_TYPE, 'label' => __('Building Type', 'your-text-domain')],
            'buildingMaterial' => ['dictionary' => DictionaryKey::BUILDING_MATERIAL, 'label' => __('Building Material', 'your-text-domain')]
        ];

        foreach ($dictionary_features as $key => $feature_info) {
            if (!isset($item_data[$key])) continue;
            $value = $this->dictionary_service->get_dict_value($feature_info['dictionary'], $item_data[$key]);
            if (empty($value) || strtolower($value) === 'dowolny' || strtolower($value) === 'any') continue;
            $features_list[] = [
                'fave_additional_feature_title' => $feature_info['label'],
                'fave_additional_feature_value' => $value,
            ];
        }
    }

    private function _add_binary_features_terms(array &$terms_list, array $item_data): void
    {
        $binary_features_map = [
            'additionalBalcony'         => __('Balcony', 'your-text-domain'),
            'additionalStorage'         => __('Storage Room', 'your-text-domain'),
            'additionalParkingunderground' => __('Underground Parking', 'your-text-domain'),
            'securityIntercom'          => __('Intercom', 'your-text-domain'),
            'securityVideocameras'      => __('Video Cameras', 'your-text-domain'),
            'buildingSwimmingpool'      => __('Swimming Pool', 'your-text-domain'),
            'buildingGym'               => __('Gym', 'your-text-domain'),
            'securityGuarded'           => __('Guarded', 'your-text-domain'),
            'securityReception'         => __('Reception', 'your-text-domain'),
            'securityVideointercom'     => __('Video Intercom', 'your-text-domain'),
            'securityGated'             => __('Gated Community', 'your-text-domain'),
            'securitySecuredoor'        => __('Secure Door', 'your-text-domain'),
            'securityBlinds'            => __('Blinds', 'your-text-domain'),
            'securityGrating'           => __('Security Grating', 'your-text-domain'),
            'securityMonitoring'        => __('Security Monitoring', 'your-text-domain'),
            'securitySmokeDetector'     => __('Smoke Detector', 'your-text-domain'),
            'securityAccessControl'     => __('Access Control', 'your-text-domain'),
            'securityAlarm'             => __('Alarm System', 'your-text-domain'),
            'buildingAdapted'           => __('Disabled Access', 'your-text-domain'),
            'buildingAirConditioning'   => __('Air Conditioning', 'your-text-domain'),
            'additionalLoggia'          => __('Loggia', 'your-text-domain'),
            'additionalTerrace'         => __('Terrace', 'your-text-domain'),
            'additionalBasement'        => __('Basement', 'your-text-domain'),
            'additionalAttic'           => __('Attic', 'your-text-domain'),
            'additionalParking'         => __('Parking', 'your-text-domain'),
            'additionalGarage'          => __('Garage', 'your-text-domain'),
            'additionalGarden'          => __('Garden', 'your-text-domain'),
            'buildingCarPark'           => __('Car Park', 'your-text-domain'),
        ];

        foreach ($binary_features_map as $json_key => $feature_name) {
            if (!isset($item_data[$json_key]) || $this->sanitizer->s_int($item_data[$json_key]) !== 1) {
                continue;
            }
            $terms_list[] = $feature_name;
        }

        $this->_handle_elevator_feature($terms_list, $item_data);
        $this->_handle_special_features($terms_list, $item_data);
    }

    private function _handle_special_features(array &$terms_list, array $item_data): void
    {
        if (isset($item_data['apartmentEquipment'])) {
            $equipment_value = $this->dictionary_service->get_dict_value(DictionaryKey::APARTMENT_EQUIPMENT, $item_data['apartmentEquipment']);

            if (!empty($equipment_value)) {
                $equipment_lower = strtolower($equipment_value);
                if (strpos($equipment_lower, 'sauna') !== false) $terms_list[] = __('Sauna', 'your-text-domain');
                if (strpos($equipment_lower, 'shower') !== false || strpos($equipment_lower, 'prysznic') !== false) $terms_list[] = __('Shower', 'your-text-domain');
                // Add more keywords if needed: e.g., jacuzzi, bathtub
            }
        }

        if (isset($item_data['apartmentBathroomType'])) {
            $bathroom_type = $this->dictionary_service->get_dict_value(DictionaryKey::APARTMENT_BATHROOM_TYPE, $item_data['apartmentBathroomType']);
            if (!empty($bathroom_type)) {
                $bathroom_lower = strtolower($bathroom_type);
                if (strpos($bathroom_lower, 'shower') !== false || strpos($bathroom_lower, 'prysznic') !== false) $terms_list[] = __('Shower', 'your-text-domain');
                if (strpos($bathroom_lower, 'bathtub') !== false || strpos($bathroom_lower, 'wanna') !== false) $terms_list[] = __('Bathtub', 'your-text-domain');
            }
        }
    }

    private function _handle_elevator_feature(array &$terms_list, array $item_data): void
    {
        $elevator_term = __('Elevator', 'your-text-domain');
        $has_elevator_value = isset($item_data['buildingElevatornumber']) && $this->sanitizer->s_int($item_data['buildingElevatornumber']) > 0;
        $has_elevator_flag = isset($item_data['buildingElevator']) && $this->sanitizer->s_int($item_data['buildingElevator']) === 1;

        if (($has_elevator_value || $has_elevator_flag) && !in_array($elevator_term, $terms_list)) {
            $terms_list[] = $elevator_term;
        }
    }

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
}
