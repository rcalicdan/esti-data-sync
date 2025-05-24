<?php
if (!defined('ABSPATH')) {
    exit;
}

class Esti_Image_Mapper_Service
{
    public function map(array &$mapped, array $item_data): void
    {
        $pictures = $item_data['pictures'] ?? [];
        if (!is_array($pictures) || empty($pictures)) {
            $mapped['featured_image_url'] = '';
            $mapped['gallery_image_urls'] = [];
            return;
        }

        $valid_urls = [];
        foreach ($pictures as $url) {
            if (is_string($url) && filter_var(trim($url), FILTER_VALIDATE_URL)) {
                $valid_urls[] = esc_url_raw(trim($url));
            }
        }
        
        if (!empty($valid_urls)) {
            $mapped['featured_image_url'] = $valid_urls[0]; // First valid URL is featured
            // All valid URLs (including the first one) go into the gallery
            $mapped['gallery_image_urls'] = $valid_urls; 
        } else {
            $mapped['featured_image_url'] = '';
            $mapped['gallery_image_urls'] = [];
        }
    }
}