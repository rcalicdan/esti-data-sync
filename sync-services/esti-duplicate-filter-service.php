<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles duplicate detection and filtering for property synchronization
 */
class Esti_Duplicate_Filter_Service
{
    /**
     * Filter out items that already exist based on portalTitle
     * 
     * @param array $dataItems Array of data items to filter
     * @return array Filtered array with duplicates removed
     */
    public function filterDuplicates(array $dataItems): array
    {
        $filtered_items = [];
        $skipped_count = 0;
        $no_title_count = 0;

        foreach ($dataItems as $index => $item) {
            if (!isset($item['portalTitle']) || empty($item['portalTitle'])) {
                $filtered_items[] = $item;
                $no_title_count++;
                error_log("Esti Sync: Item at index $index has no portalTitle, including it");
                continue;
            }

            $portal_title = $item['portalTitle'];

            if (!$this->postExistsByTitle($portal_title)) {
                $filtered_items[] = $item;
                error_log("Esti Sync: Item '$portal_title' does not exist, including it");
            } else {
                $skipped_count++;
                error_log("Esti Sync: Item '$portal_title' already exists, skipping it");
            }
        }

        error_log("Esti Sync: Duplicate filtering summary - Original: " . count($dataItems) . ", Final: " . count($filtered_items) . ", Skipped: $skipped_count, No title: $no_title_count");

        return $filtered_items;
    }

    /**
     * Check if a property post exists with the given title
     * 
     * @param string $title Title to search for
     * @return bool True if post exists, false otherwise
     */
    private function postExistsByTitle(string $title): bool
    {
        // First try exact title match
        $args = [
            'post_type' => 'property',
            'title' => $title,
            'posts_per_page' => 1,
            'fields' => 'ids',
            'post_status' => 'any',
            'suppress_filters' => true,
        ];

        $query = new WP_Query($args);
        if ($query->have_posts()) {
            error_log("Esti Sync: Found existing post with title: '$title' (using title parameter)");
            return true;
        }

        // Fallback: Direct database query
        global $wpdb;
        $existing_post = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} 
             WHERE post_title = %s 
             AND post_type = 'property' 
             AND post_status != 'trash'",
            $title
        ));

        if ($existing_post) {
            error_log("Esti Sync: Found existing post with title: '$title' (using direct query)");
            return true;
        }

        return false;
    }
}