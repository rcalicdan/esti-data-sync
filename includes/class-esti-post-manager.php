<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages synchronization of property data to WordPress posts
 */
class Esti_Post_Manager
{
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
    private const META_GALLERY_IMAGES = 'fave_property_images'; // This is the key in question

    /**
     * @var Esti_Data_Mapper
     */
    private Esti_Data_Mapper $mapper;

    /**
     * Constructor
     *
     * @param Esti_Data_Mapper $mapper Data mapper for property data
     */
    public function __construct(Esti_Data_Mapper $mapper)
    {
        $this->mapper = $mapper;
    }

    /**
     * Synchronize a property item to WordPress
     *
     * @param array $itemData The property data to synchronize
     * @return int|string|WP_Error Post ID on success, 'skipped', or WP_Error on failure
     */
    public function sync_property(array $itemData)
    {
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
        $this->processGalleryImages($postId, $mappedData); // <<< This will use the fixed logic

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
    private function processPost($jsonItemId, array $mappedData)
    {
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
    private function processMetaData(int $postId, array $mappedData): void
    {
        if (!empty($mappedData['meta_input'])) {
            // error_log("Esti Sync: Updating meta for Post ID: " . $postId . " Data: " . print_r($mappedData['meta_input'], true)); // Can be verbose
            foreach ($mappedData['meta_input'] as $key => $value) {
                // Skip gallery images here, as it's handled by processGalleryImages
                if ($key === self::META_GALLERY_IMAGES) {
                    continue;
                }
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
    private function processTaxonomies(int $postId, array $mappedData): void
    {
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
    private function processFeaturedImage(int $postId, array $mappedData): void
    {
        if (!empty($mappedData['featured_image_url'])) {
            error_log("Esti Sync: Attempting to set featured image for Post ID: " . $postId . " from URL: " . $mappedData['featured_image_url']);
            $this->setFeaturedImage($postId, $mappedData['featured_image_url'], $mappedData['post_args']['post_title'] ?? 'Featured Image');
        } else {
            error_log("Esti Sync: No featured_image_url for Post ID: " . $postId);
        }
    }

    /**
     * Process gallery images for a post.
     * This now just prepares data and calls attachGalleryImages.
     *
     * @param int $postId The post ID
     * @param array $mappedData The mapped property data
     * @return void
     */
    private function processGalleryImages(int $postId, array $mappedData): void
    {
        $galleryUrls = !empty($mappedData['gallery_image_urls']) && is_array($mappedData['gallery_image_urls']) ? $mappedData['gallery_image_urls'] : [];
        $postTitle = $mappedData['post_args']['post_title'] ?? 'Property Gallery Image'; // Default alt prefix

        if (!empty($galleryUrls)) {
            error_log("Esti Sync (ProcessGallery): Attempting to attach gallery images for Post ID: " . $postId . ". Count: " . count($galleryUrls));
        } else {
            error_log("Esti Sync (ProcessGallery): No gallery_image_urls for Post ID: " . $postId . ". Will ensure gallery meta is cleared via attachGalleryImages.");
        }
        // Always call attachGalleryImages; it will handle empty $galleryUrls by clearing existing meta.
        $this->attachGalleryImages($postId, $galleryUrls, $postTitle);
    }


    /**
     * Find an existing post by its JSON ID
     *
     * @param int|string $jsonId The JSON ID to search for
     * @return int|null Post ID if found, null otherwise
     */
    private function findExistingPost($jsonId): ?int
    {
        $args = [
            'post_type'      => self::POST_TYPE_PROPERTY,
            'posts_per_page' => 1,
            'meta_key'       => self::META_JSON_ID,
            'meta_value'     => $jsonId, // WP_Query handles type casting for meta_value appropriately in most cases
            'fields'         => 'ids',
            'post_status'    => 'any',
            'suppress_filters' => true, // Good for performance and avoiding interference
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
    private function findAttachmentBySourceUrl(string $url): ?int
    {
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
            'fields'         => 'ids',
            'suppress_filters' => true,
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
    private function setFeaturedImage(int $postId, string $imageUrl, string $imageAlt = '')
    {
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

            error_log("Esti Sync (FeaturedImg): Sideloading URL: " . $imageUrl . " for Post ID: " . $postId . " with alt: " . $imageAlt);
            $sideloadedId = media_sideload_image($imageUrl, $postId, $imageAlt, 'id');

            if (!is_wp_error($sideloadedId) && is_int($sideloadedId)) {
                update_post_meta($sideloadedId, self::META_SIDELOADED_URL, $imageUrl);
                // Set alt text for the attachment if not already set by media_sideload_image correctly
                if (!empty($imageAlt) && empty(get_post_meta($sideloadedId, '_wp_attachment_image_alt', true))) {
                    update_post_meta($sideloadedId, '_wp_attachment_image_alt', $imageAlt);
                }
                $attachmentIdToSet = $sideloadedId;
                error_log("Esti Sync (FeaturedImg): Sideloaded successfully. New Attachment ID: " . $attachmentIdToSet . " for Post ID: " . $postId);
                $this->regenerate_thumbnails_for_attachment($attachmentIdToSet);
            } else {
                $error_message = is_wp_error($sideloadedId) ? $sideloadedId->get_error_message() : 'Unknown error during sideload';
                error_log("Esti Sync (FeaturedImg): FAILED to sideload for Post ID {$postId} from URL {$imageUrl}. Error: " . $error_message);
                return $sideloadedId instanceof WP_Error ? $sideloadedId : new WP_Error('sideload_failed', $error_message);
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
     * Attach gallery images to a post.
     * This is the corrected method to store image IDs as individual meta entries.
     *
     * @param int $postId The post ID
     * @param array $imageUrls Array of image URLs
     * @param string $imageAltPrefix Prefix for alt text
     * @return void
     */
    private function attachGalleryImages(int $postId, array $imageUrls, string $imageAltPrefix = ''): void
    {
        $newAttachmentIds = [];
        error_log("Esti Sync (Gallery): Attaching for Post ID: " . $postId . ". Input URL count: " . count($imageUrls));

        foreach ($imageUrls as $index => $imageUrl) {
            if (empty($imageUrl) || !is_string($imageUrl)) {
                error_log("Esti Sync (Gallery): Skipped invalid or empty URL at index " . $index . " for Post ID: " . $postId);
                continue;
            }

            $altText = trim($imageAltPrefix . ' - Gallery Image ' . ($index + 1));
            $attachmentId = null;

            $existingAttachmentId = $this->findAttachmentBySourceUrl($imageUrl);

            if ($existingAttachmentId) {
                $attachmentId = $existingAttachmentId;
                 error_log("Esti Sync (Gallery): Found existing Attachment ID: " . $attachmentId . " for URL: " . $imageUrl);
                // Optionally, ensure post_parent is correct if it matters for the theme
                // $att = get_post($existingAttachmentId);
                // if ($att && $att->post_parent != $postId) {
                //     wp_update_post(['ID' => $existingAttachmentId, 'post_parent' => $postId]);
                // }
            } else {
                $this->ensureMediaFunctionsExist();
                error_log("Esti Sync (Gallery): Sideloading URL: " . $imageUrl . " for Post ID: " . $postId . " with alt: " . $altText);
                $sideloadedId = media_sideload_image($imageUrl, $postId, $altText, 'id');

                if (!is_wp_error($sideloadedId) && is_int($sideloadedId)) {
                    update_post_meta($sideloadedId, self::META_SIDELOADED_URL, $imageUrl);
                     // Set alt text for the attachment if not already set by media_sideload_image correctly
                    if (!empty($altText) && empty(get_post_meta($sideloadedId, '_wp_attachment_image_alt', true))) {
                         update_post_meta($sideloadedId, '_wp_attachment_image_alt', $altText);
                    }
                    // wp_update_post for post_parent is implicitly handled by media_sideload_image if $postId is > 0
                    $attachmentId = $sideloadedId;
                    error_log("Esti Sync (Gallery): Sideloaded successfully. New Attachment ID: " . $attachmentId . " for Post ID: " . $postId);
                } else {
                    $error_message = is_wp_error($sideloadedId) ? $sideloadedId->get_error_message() : 'Unknown error during sideload';
                    error_log("Esti Sync (Gallery): FAILED to sideload image for Post ID {$postId} from URL {$imageUrl}. Error: " . $error_message);
                }
            }

            if ($attachmentId && is_int($attachmentId) && $attachmentId > 0 && !in_array($attachmentId, $newAttachmentIds)) {
                $newAttachmentIds[] = $attachmentId;
            }
        }

        error_log("Esti Sync (Gallery): Final valid new_attachment_ids for Post ID " . $postId . ": " . print_r($newAttachmentIds, true));

        // Always clear existing gallery meta first.
        // This handles cases where a property previously had images and now has none, or a different set.
        $deletedCount = delete_post_meta($postId, self::META_GALLERY_IMAGES);
        error_log("Esti Sync (Gallery): Deleted " . ($deletedCount !== false ? $deletedCount : '0 or error') . " existing meta entries for " . self::META_GALLERY_IMAGES . " for Post ID " . $postId);

        if (!empty($newAttachmentIds)) {
            foreach ($newAttachmentIds as $attach_id) {
                // Add each attachment ID as a separate meta entry.
                // Store as string to match the format from the manual log.
                add_post_meta($postId, self::META_GALLERY_IMAGES, (string)$attach_id, false);
            }
            error_log("Esti Sync (Gallery): Added " . count($newAttachmentIds) . " individual meta entries for " . self::META_GALLERY_IMAGES . " for Post ID " . $postId);

            update_post_meta($postId, 'fave_property_images_count', count($newAttachmentIds));
            update_post_meta($postId, 'fave_video_images', 'image'); // This is likely a flag indicating there are images.

            foreach ($newAttachmentIds as $attach_id) {
                $this->regenerate_thumbnails_for_attachment($attach_id);
            }
        } else {
            // No new images, ensure count and flag are also cleared/updated.
            delete_post_meta($postId, 'fave_property_images_count');
            delete_post_meta($postId, 'fave_video_images'); // Or set to a default "no media" value if theme expects it
            error_log("Esti Sync (Gallery): No new gallery images. Cleared related meta for " . self::META_GALLERY_IMAGES . " for Post ID " . $postId);
        }
    }


    /**
     * Regenerate thumbnails for an attachment.
     * Made more robust with checks and logging.
     *
     * @param int $attachment_id The attachment ID
     * @return void
     */
    private function regenerate_thumbnails_for_attachment(int $attachment_id): void
    {
        if ($attachment_id <= 0) {
            error_log("Esti Sync (RegenThumbs): Invalid attachment ID: " . $attachment_id);
            return;
        }

        $this->ensureMediaFunctionsExist(); // Needed for get_attached_file and wp_generate_attachment_metadata

        $fullsizepath = get_attached_file($attachment_id);

        if (false === $fullsizepath || !@file_exists($fullsizepath)) { // Suppress error for file_exists, log manually
            error_log("Esti Sync (RegenThumbs): Failed to get attached file or file does not exist for attachment ID: " . $attachment_id . ". Path: " . $fullsizepath);
            return;
        }

        $metadata = wp_generate_attachment_metadata($attachment_id, $fullsizepath);

        if (is_wp_error($metadata)) {
            error_log("Esti Sync (RegenThumbs): Error generating metadata for attachment ID {$attachment_id}: " . $metadata->get_error_message());
            return;
        }
        if (empty($metadata)) {
            error_log("Esti Sync (RegenThumbs): Generated empty metadata for attachment ID {$attachment_id}. File: {$fullsizepath}. This might indicate an issue with the image or server configuration.");
            return;
        }

        $update_result = wp_update_attachment_metadata($attachment_id, $metadata);
        if ($update_result) {
            error_log("Esti Sync (RegenThumbs): Successfully regenerated thumbnails for attachment ID: " . $attachment_id);
        } else {
            error_log("Esti Sync (RegenThumbs): Failed to update attachment metadata for ID: " . $attachment_id);
        }
    }

    /**
     * Ensure WordPress media functions are loaded
     *
     * @return void
     */
    private function ensureMediaFunctionsExist(): void
    {
        if (!function_exists('media_sideload_image')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }
    }
}