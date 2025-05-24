<?php
if (!defined('ABSPATH')) {
    exit;
}

class Esti_Image_Mapper_Service
{
    public function map(array &$mapped, array $item_data): void
    {
        $pictures = $item_data['pictures'] ?? [];

        $this->ensure_image_is_array_and_not_empty($pictures);

        $valid_urls = $this->ensure_valid_urls($pictures);

        if (!empty($valid_urls)) {
            $mapped['featured_image_url'] = $valid_urls[0];
            $mapped['gallery_image_urls'] = $valid_urls;
        } else {
            $mapped['featured_image_url'] = '';
            $mapped['gallery_image_urls'] = [];
        }
    }

    private function ensure_valid_urls(array $urls): array
    {
        $valid_urls = [];

        foreach ($urls as $url) {
            if (is_string($url) && filter_var(trim($url), FILTER_VALIDATE_URL)) {
                $valid_urls[] = esc_url_raw(trim($url));
            }
        }

        return $valid_urls;
    }

    private function ensure_image_is_array_and_not_empty($pictures)
    {
        if (!is_array($pictures) || empty($pictures)) {
            $mapped['featured_image_url'] = '';
            $mapped['gallery_image_urls'] = [];
            return;
        }
    }
}
