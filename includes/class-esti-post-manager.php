<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once ESTI_SYNC_PLUGIN_PATH . 'services/esti_wordpress_service.php';

class Esti_Post_Manager
{
    private const STATUS_SKIPPED = 'skipped';
    private const POST_TYPE_PROPERTY = 'property';
    private const META_JSON_ID = '_esti_json_id';
    private const META_SIDELOADED_URL = '_sideloaded_source_url';
    private const META_GALLERY_IMAGES = 'fave_property_images';

    private Esti_Data_Mapper $mapper;
    private Esti_WordPress_Service $wpService;

    public function __construct(Esti_Data_Mapper $mapper, Esti_WordPress_Service $wpService)
    {
        $this->mapper = $mapper;
        $this->wpService = $wpService;
    }

    public function sync_property(array $itemData)
    {
        if (empty($itemData['id'])) {
            return new WP_Error('missing_id', 'Item data is missing a unique ID.');
        }
        $jsonItemId = $itemData['id'];
        error_log("Esti Sync: Starting sync_property for JSON ID: " . $jsonItemId);

        $mappedData = $this->mapper->map_to_wp_args($itemData);

        if (empty($mappedData['post_args']['post_title'])) {
            error_log("Esti Sync: Skipped JSON ID: " . $jsonItemId . " due to empty post_title after mapping.");
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

        error_log("Esti Sync: Finished sync_property for JSON ID: " . $jsonItemId . ". Resulting Post ID: " . $postId);
        return $postId;
    }

    private function createOrUpdatePost($jsonItemId, array $postArgs): int|WP_Error
    {
        $existingPostId = $this->findExistingPostByJsonId($jsonItemId);
        if ($existingPostId) {
            return $this->updateExistingPost($existingPostId, $postArgs, $jsonItemId);
        }
        return $this->insertNewPost($postArgs, $jsonItemId);
    }

    private function updateExistingPost(int $existingPostId, array $postArgs, $jsonItemId): int|WP_Error
    {
        error_log("Esti Sync: Found existing Post ID: " . $existingPostId . " for JSON ID: " . $jsonItemId . ". Updating.");
        $postArgs['ID'] = $existingPostId;
        $result = $this->wpService->wpUpdatePost($postArgs, true);

        if (is_wp_error($result)) {
            error_log("Esti Sync: Error updating post for JSON ID: " . $jsonItemId . ". Error: " . $result->get_error_message());
            return $result;
        }
        return $existingPostId;
    }

    private function insertNewPost(array $postArgs, $jsonItemId): int|WP_Error
    {
        error_log("Esti Sync: No existing post found for JSON ID: " . $jsonItemId . ". Creating new post.");
        $result = $this->wpService->wpInsertPost($postArgs, true);

        if (is_wp_error($result)) {
            error_log("Esti Sync: Error inserting new post for JSON ID: " . $jsonItemId . ". Error: " . $result->get_error_message());
            return $result;
        }
        error_log("Esti Sync: Created new Post ID: " . $result . " for JSON ID: " . $jsonItemId);
        return $result;
    }

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

    private function updatePostTaxonomies(int $postId, array $taxInput): void
    {
        if (empty($taxInput)) {
            return;
        }
        foreach ($taxInput as $taxonomy => $terms) {
            $this->wpService->wpSetObjectTerms($postId, $terms, $taxonomy, false);
        }
    }

    private function updatePostFeaturedImage(int $postId, ?string $imageUrl, string $imageAlt): void
    {
        if (empty($imageUrl)) {
            error_log("Esti Sync: No featured_image_url for Post ID: " . $postId);
            return;
        }
        error_log("Esti Sync: Attempting to set featured image for Post ID: " . $postId . " from URL: " . $imageUrl);
        $this->setFeaturedImage($postId, $imageUrl, $imageAlt);
    }

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
     * Helper to find an existing attachment or create a new one by sideloading.
     */
    private function getOrCreateAttachment(string $imageUrl, int $postId, string $altText, string $logPrefix = ''): ?int
    {
        $existingAttachmentId = $this->findAttachmentBySourceUrl($imageUrl);

        if ($existingAttachmentId) {
            error_log("Esti Sync ({$logPrefix}): Found existing Attachment ID: " . $existingAttachmentId . " for URL: " . $imageUrl);
            return $existingAttachmentId;
        }

        error_log("Esti Sync ({$logPrefix}): Sideloading URL: " . $imageUrl . " for Post ID: " . $postId . " with alt: " . $altText);
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
        error_log("Esti Sync ({$logPrefix}): Sideloaded successfully. New Attachment ID: " . $sideloadedId);
        $this->regenerateThumbnailsForAttachment($sideloadedId);
        return $sideloadedId;
    }

    private function setFeaturedImage(int $postId, string $imageUrl, string $imageAlt = ''): void
    {
        $attachmentIdToSet = $this->getOrCreateAttachment($imageUrl, $postId, $imageAlt, 'FeaturedImg');

        if (!$attachmentIdToSet) {
            return;
        }

        if ($this->wpService->getPostThumbnailId($postId) == $attachmentIdToSet) {
            error_log("Esti Sync (FeaturedImg): Post ID " . $postId . " thumbnail already set to Attachment ID: " . $attachmentIdToSet);
            return;
        }

        $this->wpService->setPostThumbnail($postId, $attachmentIdToSet);
        error_log("Esti Sync (FeaturedImg): Set Post ID " . $postId . " thumbnail to Attachment ID: " . $attachmentIdToSet);
    }

    private function updatePostGalleryImages(int $postId, array $galleryUrls, string $postTitle): void
    {
        if (!empty($galleryUrls)) {
            error_log("Esti Sync (ProcessGallery): Attaching gallery images for Post ID: " . $postId . ". Count: " . count($galleryUrls));
        } else {
            error_log("Esti Sync (ProcessGallery): No gallery_image_urls for Post ID: " . $postId . ". Will ensure gallery meta is cleared.");
        }
        $this->manageGalleryAttachments($postId, $galleryUrls, $postTitle);
    }

    private function manageGalleryAttachments(int $postId, array $imageUrls, string $imageAltPrefix = ''): void
    {
        $newAttachmentIds = [];
        error_log("Esti Sync (Gallery): Attaching for Post ID: " . $postId . ". Input URL count: " . count($imageUrls));

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

        error_log("Esti Sync (Gallery): Final valid new_attachment_ids for Post ID " . $postId . ": " . print_r($newAttachmentIds, true));

        $this->wpService->deletePostMeta($postId, self::META_GALLERY_IMAGES);
        error_log("Esti Sync (Gallery): Cleared existing meta entries for " . self::META_GALLERY_IMAGES . " for Post ID " . $postId);

        $this->persistGalleryAttachments($postId, $newAttachmentIds);
    }

    /**
     * Helper to persist the final list of gallery attachments to post meta.
     */
    private function persistGalleryAttachments(int $postId, array $attachmentIds): void
    {
        if (empty($attachmentIds)) {
            $this->wpService->deletePostMeta($postId, 'fave_property_images_count');
            $this->wpService->deletePostMeta($postId, 'fave_video_images');
            error_log("Esti Sync (Gallery): No new gallery images. Cleared related count/flag meta for Post ID " . $postId);
            return;
        }

        foreach ($attachmentIds as $attach_id) {
            $this->wpService->addPostMeta($postId, self::META_GALLERY_IMAGES, (string)$attach_id, false);
        }
        error_log("Esti Sync (Gallery): Added " . count($attachmentIds) . " individual meta entries for " . self::META_GALLERY_IMAGES);

        $this->wpService->updatePostMeta($postId, 'fave_property_images_count', count($attachmentIds));
        $this->wpService->updatePostMeta($postId, 'fave_video_images', 'image');

        foreach ($attachmentIds as $attach_id) {
            $this->regenerateThumbnailsForAttachment($attach_id);
        }
    }

    private function regenerateThumbnailsForAttachment(int $attachment_id): void
    {
        if ($attachment_id <= 0) {
            error_log("Esti Sync (RegenThumbs): Invalid attachment ID: " . $attachment_id);
            return;
        }

        $fullsizepath = $this->wpService->getAttachedFile($attachment_id);

        if (false === $fullsizepath || !@file_exists($fullsizepath)) {
            error_log("Esti Sync (RegenThumbs): Failed to get attached file or file does not exist for attachment ID: " . $attachment_id . ". Path: " . $fullsizepath);
            return;
        }

        $metadata = $this->wpService->wpGenerateAttachmentMetadata($attachment_id, $fullsizepath);

        if (is_wp_error($metadata)) {
            error_log("Esti Sync (RegenThumbs): Error generating metadata for attachment ID {$attachment_id}: " . $metadata->get_error_message());
            return;
        }

        if (empty($metadata)) {
            error_log("Esti Sync (RegenThumbs): Generated empty metadata for attachment ID {$attachment_id}. File: {$fullsizepath}.");
            return;
        }

        if ($this->wpService->wpUpdateAttachmentMetadata($attachment_id, $metadata)) {
            error_log("Esti Sync (RegenThumbs): Successfully regenerated thumbnails for attachment ID: " . $attachment_id);
        } else {
            error_log("Esti Sync (RegenThumbs): Failed to update attachment metadata for ID: " . $attachment_id);
        }
    }
}
