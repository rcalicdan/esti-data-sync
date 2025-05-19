<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once ESTI_SYNC_PLUGIN_PATH . 'services/esti_wordpress_service.php';

/**
 * Manages property posts synchronization.
 * 
 * Handles creation, updating, and management of property posts including
 * metadata, taxonomies, featured images, and gallery images.
 */
class Esti_Post_Manager
{
    private const STATUS_SKIPPED = 'skipped';
    private const POST_TYPE_PROPERTY = 'property';
    private const META_JSON_ID = '_esti_json_id';
    private const META_SIDELOADED_URL = '_sideloaded_source_url';
    private const META_GALLERY_IMAGES = 'fave_property_images';

    /**
     * @var Esti_Data_Mapper Data mapper for transforming raw data to WordPress format
     */
    private Esti_Data_Mapper $mapper;
    
    /**
     * @var Esti_WordPress_Service WordPress abstraction service
     */
    private Esti_WordPress_Service $wpService;

    /**
     * Constructor.
     * 
     * @param Esti_Data_Mapper $mapper Data mapper for transforming raw data
     * @param Esti_WordPress_Service $wpService WordPress service for interacting with WP functions
     */
    public function __construct(Esti_Data_Mapper $mapper, Esti_WordPress_Service $wpService)
    {
        $this->mapper = $mapper;
        $this->wpService = $wpService;
    }

    /**
     * Synchronizes a property from provided data.
     * 
     * @param array $itemData Raw property data to synchronize
     * @return int|string|WP_Error Post ID on success, status string, or error object
     */
    public function sync_property(array $itemData)
    {
        if (empty($itemData['id'])) {
            return new WP_Error('missing_id', 'Item data is missing a unique ID.');
        }

        $jsonItemId = $itemData['id'];

        $mappedData = $this->mapper->map_to_wp_args($itemData);

        if (empty($mappedData['post_args']['post_title'])) {
            return self::STATUS_SKIPPED;
        }

        $postId = $this->createOrUpdatePost($jsonItemId, $mappedData['post_args']);
        if (is_wp_error($postId)) {
            return $postId;
        }

        $this->updatePostMetaData($postId, $mappedData['meta_input'] ?? []);
        $this->updatePostTaxonomies($postId, $mappedData['tax_input'] ?? []);
        $this->updatePostFeaturedImage($postId, $mappedData['featured_image_url'] ?? null, $mappedData['post_args']['post_title'] ?? 'Featured Image');
        $this->updatePostGalleryImages($postId, $mappedData['gallery_image_urls'] ?? [], $mappedData['post_args']['post_title'] ?? 'Property Gallery Image');

        return $postId;
    }

    /**
     * Creates a new post or updates an existing post based on JSON ID.
     * 
     * @param string|int $jsonItemId Unique identifier from external data
     * @param array $postArgs Post arguments for insertion/update
     * @return int|WP_Error Post ID on success, WP_Error on failure
     */
    private function createOrUpdatePost($jsonItemId, array $postArgs): int|WP_Error
    {
        $existingPostId = $this->findExistingPostByJsonId($jsonItemId);
        if ($existingPostId) {
            return $this->updateExistingPost($existingPostId, $postArgs, $jsonItemId);
        }
        return $this->insertNewPost($postArgs, $jsonItemId);
    }

    /**
     * Updates an existing post with new data.
     * 
     * @param int $existingPostId ID of existing post to update
     * @param array $postArgs Post arguments for updating
     * @param string|int $jsonItemId Unique identifier from external data
     * @return int|WP_Error Post ID on success, WP_Error on failure
     */
    private function updateExistingPost(int $existingPostId, array $postArgs, $jsonItemId): int|WP_Error
    {
        $postArgs['ID'] = $existingPostId;
        $result = $this->wpService->wpUpdatePost($postArgs, true);

        if (is_wp_error($result)) {
            return $result;
        }
        return $existingPostId;
    }

    /**
     * Inserts a new post with the provided data.
     * 
     * @param array $postArgs Post arguments for insertion
     * @param string|int $jsonItemId Unique identifier from external data
     * @return int|WP_Error Post ID on success, WP_Error on failure
     */
    private function insertNewPost(array $postArgs, $jsonItemId): int|WP_Error
    {
        $result = $this->wpService->wpInsertPost($postArgs, true);

        if (is_wp_error($result)) {
            return $result;
        }

        return $result;
    }

    /**
     * Finds an existing post by its external JSON ID.
     * 
     * @param string|int $jsonId External JSON identifier to search for
     * @return int|null Post ID if found, null otherwise
     */
    private function findExistingPostByJsonId($jsonId): ?int
    {
        $args = [
            'post_type'      => self::POST_TYPE_PROPERTY,
            'posts_per_page' => 1,
            'meta_key'       => self::META_JSON_ID,
            'meta_value'     => $jsonId,
            'fields'         => 'ids',
            'post_status'    => 'any',
            'suppress_filters' => true,
        ];
        $query = $this->wpService->newWpQuery($args);
        return $query->have_posts() ? (int)$query->posts[0] : null;
    }

    /**
     * Updates post meta data for a post.
     * 
     * @param int $postId Post ID to update
     * @param array $metaInput Array of meta keys and values to update
     */
    private function updatePostMetaData(int $postId, array $metaInput): void
    {
        if (empty($metaInput)) {
            return;
        }

        $metaToProcess = array_filter(
            $metaInput,
            fn($key) => $key !== self::META_GALLERY_IMAGES,
            ARRAY_FILTER_USE_KEY
        );

        foreach ($metaToProcess as $key => $value) {
            $this->wpService->updatePostMeta($postId, $key, $value);
        }
    }

    /**
     * Updates taxonomy terms for a post.
     * 
     * @param int $postId Post ID to update
     * @param array $taxInput Array of taxonomies and their terms
     */
    private function updatePostTaxonomies(int $postId, array $taxInput): void
    {
        if (empty($taxInput)) {
            return;
        }
        foreach ($taxInput as $taxonomy => $terms) {
            $this->wpService->wpSetObjectTerms($postId, $terms, $taxonomy, false);
        }
    }

    /**
     * Updates the featured image for a post.
     * 
     * @param int $postId Post ID to update
     * @param string|null $imageUrl URL of image to use as featured image
     * @param string $imageAlt Alt text for the image
     */
    private function updatePostFeaturedImage(int $postId, ?string $imageUrl, string $imageAlt): void
    {
        if (empty($imageUrl)) {
            return;
        }

        $this->setFeaturedImage($postId, $imageUrl, $imageAlt);
    }

    /**
     * Finds an attachment by its source URL.
     * 
     * @param string $url Source URL to search for
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
                ['key' => self::META_SIDELOADED_URL, 'value' => $url]
            ],
            'fields'         => 'ids',
            'suppress_filters' => true,
        ];
        $query = $this->wpService->newWpQuery($args);
        return $query->have_posts() ? (int)$query->posts[0] : null;
    }

    /**
     * Gets an existing attachment or creates a new one by sideloading.
     * 
     * @param string $imageUrl URL of the image to sideload
     * @param int $postId Parent post ID
     * @param string $altText Alt text for the image
     * @param string $logPrefix Prefix for logging messages
     * @return int|null Attachment ID on success, null on failure
     */
    private function getOrCreateAttachment(string $imageUrl, int $postId, string $altText, string $logPrefix = ''): ?int
    {
        $existingAttachmentId = $this->findAttachmentBySourceUrl($imageUrl);

        if ($existingAttachmentId) {
            return $existingAttachmentId;
        }

        $sideloadedId = $this->wpService->mediaSideloadImage($imageUrl, $postId, $altText, 'id');

        if (is_wp_error($sideloadedId) || !is_int($sideloadedId) || $sideloadedId <= 0) {
            $error_message = is_wp_error($sideloadedId) ? $sideloadedId->get_error_message() : 'Unknown error or invalid ID during sideload';
            error_log("Esti Sync ({$logPrefix}): FAILED to sideload for Post ID {$postId} from URL {$imageUrl}. Error: " . $error_message);
            return null;
        }

        $this->wpService->updatePostMeta($sideloadedId, self::META_SIDELOADED_URL, $imageUrl);

        if (!empty($altText) && empty($this->wpService->getPostMeta($sideloadedId, '_wp_attachment_image_alt', true))) {
            $this->wpService->updatePostMeta($sideloadedId, '_wp_attachment_image_alt', $altText);
        }

        $this->regenerateThumbnailsForAttachment($sideloadedId);
        return $sideloadedId;
    }

    /**
     * Sets the featured image for a post.
     * 
     * @param int $postId Post ID to update
     * @param string $imageUrl URL of image to use as featured image
     * @param string $imageAlt Alt text for the image
     */
    private function setFeaturedImage(int $postId, string $imageUrl, string $imageAlt = ''): void
    {
        $attachmentIdToSet = $this->getOrCreateAttachment($imageUrl, $postId, $imageAlt, 'FeaturedImg');

        if (!$attachmentIdToSet) {
            return;
        }

        if ($this->wpService->getPostThumbnailId($postId) == $attachmentIdToSet) {
            return;
        }

        $this->wpService->setPostThumbnail($postId, $attachmentIdToSet);
    }

    /**
     * Updates gallery images for a post.
     * 
     * @param int $postId Post ID to update
     * @param array $galleryUrls URLs of images to use in the gallery
     * @param string $postTitle Post title to use in image alt text
     */
    private function updatePostGalleryImages(int $postId, array $galleryUrls, string $postTitle): void
    {
        if (!empty($galleryUrls)) {
            error_log("Esti Sync (ProcessGallery): Attaching gallery images for Post ID: " . $postId . ". Count: " . count($galleryUrls));
        } else {
            error_log("Esti Sync (ProcessGallery): No gallery_image_urls for Post ID: " . $postId . ". Will ensure gallery meta is cleared.");
        }

        $this->manageGalleryAttachments($postId, $galleryUrls, $postTitle);
    }

    /**
     * Manages gallery attachments for a post.
     * 
     * @param int $postId Post ID to update
     * @param array $imageUrls URLs of images to use in the gallery
     * @param string $imageAltPrefix Prefix for alt text of gallery images
     */
    private function manageGalleryAttachments(int $postId, array $imageUrls, string $imageAltPrefix = ''): void
    {
        $newAttachmentIds = [];

        foreach ($imageUrls as $index => $imageUrl) {
            if (empty($imageUrl) || !is_string($imageUrl)) {
                error_log("Esti Sync (Gallery): Skipped invalid or empty URL at index " . $index . " for Post ID: " . $postId);
                continue;
            }

            $altText = trim($imageAltPrefix . ' - Gallery Image ' . ($index + 1));
            $attachmentId = $this->getOrCreateAttachment($imageUrl, $postId, $altText, 'Gallery');

            if ($attachmentId && !in_array($attachmentId, $newAttachmentIds)) {
                $newAttachmentIds[] = $attachmentId;
            }
        }

        $this->wpService->deletePostMeta($postId, self::META_GALLERY_IMAGES);

        $this->persistGalleryAttachments($postId, $newAttachmentIds);
    }

    /**
     * Persists gallery attachments to post meta.
     * 
     * @param int $postId Post ID to update
     * @param array $attachmentIds Attachment IDs to persist as gallery
     */
    private function persistGalleryAttachments(int $postId, array $attachmentIds): void
    {
        if (empty($attachmentIds)) {
            $this->wpService->deletePostMeta($postId, 'fave_property_images_count');
            $this->wpService->deletePostMeta($postId, 'fave_video_images');
            return;
        }

        foreach ($attachmentIds as $attach_id) {
            $this->wpService->addPostMeta($postId, self::META_GALLERY_IMAGES, (string)$attach_id, false);
        }

        $this->wpService->updatePostMeta($postId, 'fave_property_images_count', count($attachmentIds));
        $this->wpService->updatePostMeta($postId, 'fave_video_images', 'image');

        foreach ($attachmentIds as $attach_id) {
            $this->regenerateThumbnailsForAttachment($attach_id);
        }
    }

    /**
     * Regenerates thumbnails for an attachment.
     * 
     * @param int $attachment_id Attachment ID to regenerate thumbnails for
     */
    private function regenerateThumbnailsForAttachment(int $attachment_id): void
    {
        if ($attachment_id <= 0) {
            return;
        }

        $fullsizepath = $this->wpService->getAttachedFile($attachment_id);

        if (false === $fullsizepath || !@file_exists($fullsizepath)) {
            return;
        }

        $metadata = $this->wpService->wpGenerateAttachmentMetadata($attachment_id, $fullsizepath);

        if (is_wp_error($metadata)) {
            return;
        }

        if (empty($metadata)) {
            return;
        }

        $this->wpService->wpUpdateAttachmentMetadata($attachment_id, $metadata);
    }
}