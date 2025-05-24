<?php
if (!defined('ABSPATH')) {
    exit;
}

class Esti_Core_Post_Mapper_Service
{
    private Esti_Sanitizer_Service $sanitizer;

    public function __construct(Esti_Sanitizer_Service $sanitizer)
    {
        $this->sanitizer = $sanitizer;
    }

    public function map(array &$mapped, array $item_data): void
    {
        $title = $item_data['portalTitle'] ?? 'Property ' . ($item_data['id'] ?? 'Unknown');
        $mapped['post_args']['post_title'] = $this->sanitizer->s_text($title);

        $description = $item_data['descriptionWebsite'] ?? $item_data['description'] ?? '';
        $mapped['post_args']['post_content'] = wp_kses_post($description);

        $mapped['post_args']['post_status'] = 'publish';
        $mapped['post_args']['post_type'] = HouzezWpEntity::POST_TYPE_PROPERTY->value;

        $this->_add_date_fields($mapped, $item_data);
    }

    private function _add_date_fields(array &$mapped, array $item_data): void
    {
        if (!empty($item_data['addDate'])) {
            try {
                $date = new DateTime($item_data['addDate']);
                $mapped['post_args']['post_date'] = $date->format('Y-m-d H:i:s');
                $mapped['post_args']['post_date_gmt'] = get_gmt_from_date($mapped['post_args']['post_date']);
            } catch (Exception $e) {
                error_log("Error parsing addDate '{$item_data['addDate']}': " . $e->getMessage());
            }
        }

        if (!empty($item_data['updateDate'])) {
            try {
                $date = new DateTime($item_data['updateDate']);
                $mapped['post_args']['post_modified'] = $date->format('Y-m-d H:i:s');
                $mapped['post_args']['post_modified_gmt'] = get_gmt_from_date($mapped['post_args']['post_modified']);
            } catch (Exception $e) {
                error_log("Error parsing updateDate '{$item_data['updateDate']}': " . $e->getMessage());
            }
        }
    }
}
