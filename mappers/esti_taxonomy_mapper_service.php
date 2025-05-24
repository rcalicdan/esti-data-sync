<?php
if (!defined('ABSPATH')) {
    exit;
}

class Esti_Taxonomy_Mapper_Service
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
        $this->_map_property_type($mapped, $item_data);
        $this->_map_location_taxonomies($mapped, $item_data);
        $this->_map_property_status($mapped, $item_data);
        $this->_map_property_labels($mapped, $item_data);
    }

    private function _map_property_type(array &$mapped, array $item_data): void
    {
        $type_name = '';
        if (isset($item_data['mainTypeId'])) {
            $type_name = $this->dictionary_service->get_dict_value(DictionaryKey::TYPES, $item_data['mainTypeId']);
        } elseif (!empty($item_data['typeName'])) {
            $type_name = $this->sanitizer->s_text($item_data['typeName']);
        }

        if ($type_name && strtolower($type_name) !== 'dowolny' && strtolower($type_name) !== 'any') {
            $mapped['tax_input'][HouzezTaxonomy::TYPE->value] = $type_name;
        }
    }

    private function _map_location_taxonomies(array &$mapped, array $item_data): void
    {
        $location_taxonomies = [
            'locationCityName' => HouzezTaxonomy::CITY,
            'locationPrecinctName' => HouzezTaxonomy::AREA,
            'locationProvinceName' => HouzezTaxonomy::STATE,
            'locationCountryName' => HouzezTaxonomy::COUNTRY
        ];
        foreach ($location_taxonomies as $source_field => $taxonomy_enum) {
            if (empty($item_data[$source_field])) continue;

            $value = $this->sanitizer->s_text($item_data[$source_field]);

            if (empty($value)) continue;

            $mapped['tax_input'][$taxonomy_enum->value] = $value;

            if ($taxonomy_enum === HouzezTaxonomy::COUNTRY) {
                $mapped['meta_input']['fave_property_country'] = $value;
            }
        }
    }

    private function _map_property_status(array &$mapped, array $item_data): void
    {
        if (!isset($item_data['transaction'])) return;

        $transaction_code = $this->sanitizer->s_int($item_data['transaction']);
        $status_term = $this->_get_transaction_status_term($transaction_code);

        if ($status_term !== 'Unknown') {
            $mapped['tax_input'][HouzezTaxonomy::STATUS->value] = $status_term;
        }
    }

    private function _map_property_labels(array &$mapped, array $item_data): void
    {
        $labels = [];

        $label_flags = [
            'labelNew' => ['value' => JsonFeedCode::LABEL_NEW->value, 'label' => __('New', 'your-text-domain')],
            'labelSold' => ['value' => JsonFeedCode::LABEL_SOLD->value, 'label' => __('Sold', 'your-text-domain')],
            'labelReserved' => ['value' => JsonFeedCode::LABEL_RESERVED->value, 'label' => __('Reserved', 'your-text-domain')]
        ];

        foreach ($label_flags as $key => $info) {
            if (isset($item_data[$key]) && $this->sanitizer->s_int($item_data[$key]) === $info['value']) {
                $labels[] = $info['label'];
            }
        }

        // Market type from dictionary
        if (isset($item_data['market'])) {
            $market_name = $this->dictionary_service->get_dict_value(DictionaryKey::MARKET, $item_data['market']);
            if ($market_name && strtolower($market_name) !== 'dowolny' && strtolower($market_name) !== 'any') {
                $labels[] = $market_name;
            }
        }

        // Check if property is marked as featured in meta_input
        if (
            isset($mapped['meta_input'][HouzezMetaKey::FEATURED_PROPERTY->value]) &&
            $mapped['meta_input'][HouzezMetaKey::FEATURED_PROPERTY->value] === '1'
        ) {
            $labels[] = __('Featured', 'your-text-domain');
        }

        if (!empty($labels)) {
            $mapped['tax_input'][HouzezTaxonomy::LABEL->value] = array_unique($labels);
        }
    }

    private function _get_transaction_status_term(int $transaction_code): string
    {
        $transaction_map = [
            JsonFeedCode::TRANSACTION_FOR_SALE->value => __('For Sale', 'your-text-domain'),
            JsonFeedCode::TRANSACTION_FOR_RENT->value => __('For Rent', 'your-text-domain')
        ];

        return $transaction_map[$transaction_code] ?? 'Unknown'; 
    }
}
