<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages synchronization of property data to WordPress posts
 */
class Esti_Post_Manager {
    /**
     * Status constants
     */
    private const STATUS_SKIPPED = 'skipped';
    
    /**
     * Post type for properties
     */
    private const POST_TYPE_PROPERTY = 'property';
    
    /**
     * Meta keys
     */
    private const META_JSON_ID = '_esti_json_id';
    private const META_SIDELOADED_URL = '_sideloaded_source_url';
    private const META_GALLERY_IMAGES = 'fave_property_images';
    
    /**
     * @var Esti_Data_Mapper
     */
    private Esti_Data_Mapper $mapper;

    /**
     * Constructor
     *
     * @param Esti_Data_Mapper $mapper Data mapper for property data
     */
    public function __construct(Esti_Data_Mapper $mapper) {
        $this->mapper = $mapper;
    }

    /**
     * Synchronize a property item to WordPress
     *
     * @param array $itemData The property data to synchronize
     * @return int|string|WP_Error Post ID on success, 'skipped', or WP_Error on failure
     */
    public function sync_property(array $itemData) {
        if (empty($itemData['id'])) {
            return new WP_Error('missing_id', 'Item data is missing a unique ID.');
        }

        $jsonItemId = $itemData['id']; // For logging
        error_log("Esti Sync: Starting sync_property for JSON ID: " . $jsonItemId);

        $mappedData = $this->mapper->map_to_wp_args($itemData);

        if (empty($mappedData['post_args']['post_title'])) {
            error_log("Esti Sync: Skipped JSON ID: " . $jsonItemId . " due to empty post_title after mapping.");
            return self::STATUS_SKIPPED;
        }

        $postId = $this->processPost($jsonItemId, $mappedData);
        
        if (is_wp_error($postId)) {
            return $postId;
        }

        $this->processMetaData($postId, $mappedData);
        $this->processTaxonomies($postId, $mappedData);
        $this->processFeaturedImage($postId, $mappedData);
        $this->processGalleryImages($postId, $mappedData);
        
        error_log("Esti Sync: Finished sync_property for JSON ID: " . $jsonItemId . ". Resulting Post ID: " . $postId);
        return $postId;
    }

    /**
     * Process post creation or update
     *
     * @param int|string $jsonItemId The ID from the JSON data
     * @param array $mappedData The mapped property data
     * @return int|WP_Error Post ID on success, WP_Error on failure
     */
    private function processPost($jsonItemId, array $mappedData) {
        $existingPostId = $this->findExistingPost($jsonItemId);
        
        if ($existingPostId) {
            error_log("Esti Sync: Found existing Post ID: " . $existingPostId . " for JSON ID: " . $jsonItemId);
            $mappedData['post_args']['ID'] = $existingPostId;
            $updateResult = wp_update_post($mappedData['post_args'], true);
            
            if (is_wp_error($updateResult)) {
                error_log("Esti Sync: Error updating post for JSON ID: " . $jsonItemId . ". Error: " . $updateResult->get_error_message());
                return $updateResult;
            }
            
            return $existingPostId;
        } else {
            error_log("Esti Sync: No existing post found for JSON ID: " . $jsonItemId . ". Creating new post.");
            $insertResult = wp_insert_post($mappedData['post_args'], true);
            
            if (is_wp_error($insertResult)) {
                error_log("Esti Sync: Error inserting new post for JSON ID: " . $jsonItemId . ". Error: " . $insertResult->get_error_message());
                return $insertResult;
            }
            
            error_log("Esti Sync: Created new Post ID: " . $insertResult . " for JSON ID: " . $jsonItemId);
            return $insertResult;
        }
    }

    /**
     * Process meta data for a post
     *
     * @param int $postId The post ID
     * @param array $mappedData The mapped property data
     * @return void
     */
    private function processMetaData(int $postId, array $mappedData): void {
        if (!empty($mappedData['meta_input'])) {
            // error_log("Esti Sync: Updating meta for Post ID: " . $postId . " Data: " . print_r($mappedData['meta_input'], true)); // Can be verbose
            foreach ($mappedData['meta_input'] as $key => $value) {
                update_post_meta($postId, $key, $value);
            }
        }
    }

    /**
     * Process taxonomies for a post
     *
     * @param int $postId The post ID
     * @param array $mappedData The mapped property data
     * @return void
     */
    private function processTaxonomies(int $postId, array $mappedData): void {
        if (!empty($mappedData['tax_input'])) {
            // error_log("Esti Sync: Setting taxonomies for Post ID: " . $postId . " Data: " . print_r($mappedData['tax_input'], true)); // Can be verbose
            foreach ($mappedData['tax_input'] as $taxonomy => $terms) {
                wp_set_object_terms($postId, $terms, $taxonomy, false);
            }
        }
    }

    /**
     * Process featured image for a post
     *
     * @param int $postId The post ID
     * @param array $mappedData The mapped property data
     * @return void
     */
    private function processFeaturedImage(int $postId, array $mappedData): void {
        if (!empty($mappedData['featured_image_url'])) {
            error_log("Esti Sync: Attempting to set featured image for Post ID: " . $postId . " from URL: " . $mappedData['featured_image_url']);
            $this->setFeaturedImage($postId, $mappedData['featured_image_url'], $mappedData['post_args']['post_title']);
        } else {
            error_log("Esti Sync: No featured_image_url for Post ID: " . $postId);
        }
    }

    /**
     * Process gallery images for a post
     *
     * @param int $postId The post ID
     * @param array $mappedData The mapped property data
     * @return void
     */
    private function processGalleryImages(int $postId, array $mappedData): void {
        if (!empty($mappedData['gallery_image_urls'])) {
            error_log("Esti Sync: Attempting to attach gallery images for Post ID: " . $postId . ". Count: " . count($mappedData['gallery_image_urls']));
            $this->attachGalleryImages($postId, $mappedData['gallery_image_urls'], $mappedData['post_args']['post_title']);
        } else {
            error_log("Esti Sync: No gallery_image_urls for Post ID: " . $postId . ". Clearing existing gallery meta if any.");
            // If no images from JSON, ensure gallery meta is cleared.
            delete_post_meta($postId, self::META_GALLERY_IMAGES);
        }
    }

    /**
     * Find an existing post by its JSON ID
     *
     * @param int|string $jsonId The JSON ID to search for
     * @return int|null Post ID if found, null otherwise
     */
    private function findExistingPost($jsonId): ?int {
        $args = [
            'post_type'      => self::POST_TYPE_PROPERTY,
            'posts_per_page' => 1,
            'meta_key'       => self::META_JSON_ID,
            'meta_value'     => intval($jsonId),
            'fields'         => 'ids',
            'post_status'    => 'any'
        ];
        
        $query = new WP_Query($args);
        
        if ($query->have_posts()) {
            return (int)$query->posts[0];
        }
        
        return null;
    }

    /**
     * Find an attachment by its source URL
     *
     * @param string $url The source URL to search for
     * @return int|null Attachment ID if found, null otherwise
     */
    private function findAttachmentBySourceUrl(string $url): ?int {
        if (empty($url)) {
            return null;
        }
        
        $args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 1,
            'meta_query'     => [
                [
                    'key'   => self::META_SIDELOADED_URL,
                    'value' => $url,
                ],
            ],
            'fields'         => 'ids'
        ];
        
        $query = new WP_Query($args);
        
        if ($query->have_posts()) {
            return (int)$query->posts[0];
        }
        
        return null;
    }

    /**
     * Set featured image for a post
     *
     * @param int $postId The post ID
     * @param string $imageUrl The image URL
     * @param string $imageAlt Alt text for the image
     * @return int|null|WP_Error Attachment ID on success, null or WP_Error on failure
     */
    private function setFeaturedImage(int $postId, string $imageUrl, string $imageAlt = '') {
        if (empty($imageUrl)) {
            error_log("Esti Sync (FeaturedImg): Empty image_url for Post ID: " . $postId);
            return null;
        }

        $attachmentIdToSet = null;
        $existingAttachmentId = $this->findAttachmentBySourceUrl($imageUrl);

        if ($existingAttachmentId) {
            $attachmentIdToSet = $existingAttachmentId;
            error_log("Esti Sync (FeaturedImg): Found existing Attachment ID: " . $attachmentIdToSet . " for URL: " . $imageUrl . " for Post ID: " . $postId);
        } else {
            $this->ensureMediaFunctionsExist();
            
            error_log("Esti Sync (FeaturedImg): Sideloading URL: " . $imageUrl . " for Post ID: " . $postId);
            $sideloadedId = media_sideload_image($imageUrl, $postId, $imageAlt, 'id');
            
            if (!is_wp_error($sideloadedId)) {
                update_post_meta($sideloadedId, self::META_SIDELOADED_URL, $imageUrl);
                $attachmentIdToSet = $sideloadedId;
                error_log("Esti Sync (FeaturedImg): Sideloaded successfully. New Attachment ID: " . $attachmentIdToSet . " for Post ID: " . $postId);
            } else {
                error_log("Esti Sync (FeaturedImg): FAILED to sideload for Post ID {$postId} from URL {$imageUrl}. Error: " . $sideloadedId->get_error_message());
                return $sideloadedId; // Return WP_Error
            }
        }

        if ($attachmentIdToSet) {
            if (get_post_thumbnail_id($postId) != $attachmentIdToSet) {
                set_post_thumbnail($postId, $attachmentIdToSet);
                error_log("Esti Sync (FeaturedImg): Set Post ID " . $postId . " thumbnail to Attachment ID: " . $attachmentIdToSet);
            } else {
                error_log("Esti Sync (FeaturedImg): Post ID " . $postId . " thumbnail already set to Attachment ID: " . $attachmentIdToSet);
            }
            return $attachmentIdToSet;
        }
        
        return null;
    }

    /**
     * Attach gallery images to a post
     *
     * @param int $postId The post ID
     * @param array $imageUrls Array of image URLs
     * @param string $imageAltPrefix Prefix for alt text
     * @return void
     */
    private function attachGalleryImages(int $postId, array $imageUrls, string $imageAltPrefix = ''): void {
        $newAttachmentIds = [];
        error_log("Esti Sync (Gallery): Attaching for Post ID: " . $postId . ". URL count: " . count($imageUrls));

        foreach ($imageUrls as $index => $imageUrl) {
            if (empty($imageUrl)) {
                error_log("Esti Sync (Gallery): Skipped empty URL at index " . $index . " for Post ID: " . $postId);
                continue;
            }
            // error_log("Esti Sync (Gallery): Processing URL (" . ($index + 1) . "): " . $imageUrl . " for Post ID: " . $postId); // Can be too verbose

            $altText = $imageAltPrefix . ' - Gallery Image ' . ($index + 1);
            $attachmentId = null;

            $existingAttachmentId = $this->findAttachmentBySourceUrl($imageUrl);

            if ($existingAttachmentId) {
                $attachmentId = $existingAttachmentId;
                // error_log("Esti Sync (Gallery): Found existing Attachment ID: " . $attachmentId . " for URL: " . $imageUrl . " for Post ID: " . $postId); // Can be too verbose
            } else {
                $this->ensureMediaFunctionsExist();
                
                // error_log("Esti Sync (Gallery): Sideloading URL: " . $imageUrl . " for Post ID: " . $postId . " Index: " . $index); // Can be too verbose
                $sideloadedId = media_sideload_image($imageUrl, $postId, $altText, 'id');
                
                if (!is_wp_error($sideloadedId)) {
                    update_post_meta($sideloadedId, self::META_SIDELOADED_URL, $imageUrl);
                    $attachmentId = $sideloadedId;
                    // error_log("Esti Sync (Gallery): Sideloaded successfully. New Attachment ID: " . $attachmentId . " for URL: " . $imageUrl . " for Post ID: " . $postId); // Can be too verbose
                } else {
                    error_log("Esti Sync (Gallery): FAILED to sideload image for Post ID {$postId} from URL {$imageUrl}. Error: " . $sideloadedId->get_error_message());
                }
            }

            if ($attachmentId && !in_array($attachmentId, $newAttachmentIds)) {
                $newAttachmentIds[] = $attachmentId;
            }
        }

        error_log("Esti Sync (Gallery): Final new_attachment_ids for Post ID " . $postId . ": " . print_r($newAttachmentIds, true));

        if (!empty($newAttachmentIds)) {
            update_post_meta($postId, self::META_GALLERY_IMAGES, $newAttachmentIds);
            error_log("Esti Sync (Gallery): Updated " . self::META_GALLERY_IMAGES . " for Post ID " . $postId . " with " . count($newAttachmentIds) . " IDs.");
        } else {
            // Only delete if it previously existed and now the new list is empty.
            // Or always delete if new list is empty to ensure it's cleared.
            // For simplicity now, always delete if $newAttachmentIds is empty.
            delete_post_meta($postId, self::META_GALLERY_IMAGES);
            error_log("Esti Sync (Gallery): Deleted " . self::META_GALLERY_IMAGES . " for Post ID " . $postId . " as new_attachment_ids was empty.");
        }
    }
    
    /**
     * Ensure WordPress media functions are loaded
     *
     * @return void
     */
    private function ensureMediaFunctionsExist(): void {
        if (!function_exists('media_sideload_image')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }
    }
}