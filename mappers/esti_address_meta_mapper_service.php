<?php
if (!defined('ABSPATH')) {
    exit;
}

class Esti_Address_Meta_Mapper_Service
{
    private Esti_Sanitizer_Service $sanitizer;

    public function __construct(Esti_Sanitizer_Service $sanitizer)
    {
        $this->sanitizer = $sanitizer;
    }

    public function map(array &$mapped, array $item_data): void
    {
        $meta = &$mapped['meta_input'];
        $street = $this->sanitizer->s_text($item_data['locationStreetName'] ?? '');

        if (!empty($street)) {
            $meta[HouzezMetaKey::ADDRESS->value] = $street;
        }

        if (!empty($item_data['locationPostal'])) {
            $meta[HouzezMetaKey::ZIP->value] = $this->sanitizer->s_text($item_data['locationPostal']);
        }

        $this->_set_map_address($meta, $street, $item_data);
        $this->_set_location_coordinates($meta, $item_data);
    }

    private function _set_map_address(array &$meta, string $street, array $item_data): void
    {
        $map_address_parts = [];
        if (!empty($street)) $map_address_parts[] = $street;
        if (!empty($item_data['locationCityName'])) $map_address_parts[] = $this->sanitizer->s_text($item_data['locationCityName']);
        if (!empty($item_data['locationPostal'])) $map_address_parts[] = $this->sanitizer->s_text($item_data['locationPostal']);
        if (!empty($item_data['locationProvinceName'])) $map_address_parts[] = $this->sanitizer->s_text($item_data['locationProvinceName']);
        if (!empty($item_data['locationCountryName'])) $map_address_parts[] = $this->sanitizer->s_text($item_data['locationCountryName']);

        $full_map_address = implode(', ', array_filter($map_address_parts));

        if (!empty($full_map_address)) {
            $meta[HouzezMetaKey::MAP_ADDRESS->value] = $full_map_address;
        } elseif (!empty($street)) {
            $meta[HouzezMetaKey::MAP_ADDRESS->value] = $street;
        }
    }

    private function _set_location_coordinates(array &$meta, array $item_data): void
    {
        $latitude = !empty($item_data['locationLatitude']) ? $this->sanitizer->s_text($item_data['locationLatitude']) : '';
        $longitude = !empty($item_data['locationLongitude']) ? $this->sanitizer->s_text($item_data['locationLongitude']) : '';
        $has_valid_coordinates = false;

        if (!empty($latitude) && !empty($longitude)) {
            $lat_float = filter_var(str_replace(',', '.', $latitude), FILTER_VALIDATE_FLOAT);
            $long_float = filter_var(str_replace(',', '.', $longitude), FILTER_VALIDATE_FLOAT);

            if ($lat_float !== false && $long_float !== false) {
                // Ensure they are stored in the dot-decimal format expected by Houzez
                $latitude_formatted = number_format($lat_float, 6, '.', '');
                $longitude_formatted = number_format($long_float, 6, '.', '');

                $meta[HouzezMetaKey::LOCATION_COORDS->value] = "{$latitude_formatted},{$longitude_formatted},16"; // 16 is a common zoom level
                $meta[HouzezMetaKey::LATITUDE->value] = $latitude_formatted;
                $meta[HouzezMetaKey::LONGITUDE->value] = $longitude_formatted;
                $has_valid_coordinates = true;
            }
        }

        $meta[HouzezMetaKey::MAP_ENABLED->value] = $has_valid_coordinates ? '1' : '0';
        $meta[HouzezMetaKey::MAP_STREET_VIEW->value] = 'show'; 
    }
}
