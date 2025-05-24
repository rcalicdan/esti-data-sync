<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once ESTI_SYNC_PLUGIN_PATH . 'services/esti_wordpress_service.php';

/**
 * Manages property posts synchronization.
 * 
 * Handles creation, updating, and management of property posts including
 * metadata and taxonomies. Image handling is delegated to Esti_Image_Handler.
 */
class Esti_Post_Manager
{
    private const STATUS_SKIPPED = 'skipped';
    private const POST_TYPE_PROPERTY = 'property';
    private const META_JSON_ID = '_esti_json_id';
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
     * @var Esti_Image_Handler Image handler for managing post images
     */
    private Esti_Image_Handler $imageHandler;

    /**
     * Constructor.
     * 
     * @param Esti_Data_Mapper $mapper Data mapper for transforming raw data
     * @param Esti_WordPress_Service $wpService WordPress service for interacting with WP functions
     * @param Esti_Image_Handler $imageHandler Image handler for managing post images
     */
    public function __construct(
        Esti_Data_Mapper $mapper,
        Esti_WordPress_Service $wpService,
        Esti_Image_Handler $imageHandler
    ) {
        $this->mapper = $mapper;
        $this->wpService = $wpService;
        $this->imageHandler = $imageHandler;
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

        // Delegate image handling to the image handler
        $this->imageHandler->updatePostFeaturedImage(
            $postId,
            $mappedData['featured_image_url'] ?? null,
            $mappedData['post_args']['post_title'] ?? 'Featured Image'
        );

        $this->imageHandler->updatePostGalleryImages(
            $postId,
            $mappedData['gallery_image_urls'] ?? [],
            $mappedData['post_args']['post_title'] ?? 'Property Gallery Image'
        );

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
}
