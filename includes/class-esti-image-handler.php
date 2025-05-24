<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles image operations for property posts.
 * 
 * Manages featured images, gallery images, attachments, and thumbnail generation.
 */
class Esti_Image_Handler
{
    private const META_SIDELOADED_URL = '_sideloaded_source_url';
    private const META_GALLERY_IMAGES = 'fave_property_images';
    private const DEFAULT_THUMBNAIL_PATH = ESTI_SYNC_PLUGIN_PATH . 'data/default-thumbnail.webp';

    /**
     * @var Esti_WordPress_Service WordPress abstraction service
     */
    private Esti_WordPress_Service $wpService;

    /**
     * Constructor.
     * 
     * @param Esti_WordPress_Service $wpService WordPress service for interacting with WP functions
     */
    public function __construct(Esti_WordPress_Service $wpService)
    {
        $this->wpService = $wpService;
    }

    /**
     * Updates the featured image for a post.
     * 
     * @param int $postId Post ID to update
     * @param string|null $imageUrl URL of image to use as featured image
     * @param string $imageAlt Alt text for the image
     */
    public function updatePostFeaturedImage(int $postId, ?string $imageUrl, string $imageAlt): void
    {
        if (empty($imageUrl)) {
            return;
        }

        $this->setFeaturedImage($postId, $imageUrl, $imageAlt);
    }

    /**
     * Updates gallery images for a post.
     * 
     * @param int $postId Post ID to update
     * @param array $galleryUrls URLs of images to use in the gallery
     * @param string $postTitle Post title to use in image alt text
     */
    public function updatePostGalleryImages(int $postId, array $galleryUrls, string $postTitle): void
    {
        if (!empty($galleryUrls)) {
            error_log("Esti Sync (ProcessGallery): Attaching gallery images for Post ID: " . $postId . ". Count: " . count($galleryUrls));
        } else {
            error_log("Esti Sync (ProcessGallery): No gallery_image_urls for Post ID: " . $postId . ". Will ensure gallery meta is cleared.");
        }

        $this->manageGalleryAttachments($postId, $galleryUrls, $postTitle);
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
            error_log("Esti Sync (FeaturedImg): Primary image failed for Post ID {$postId}, attempting to use default thumbnail");
            $attachmentIdToSet = $this->getOrCreateDefaultThumbnail($postId, $imageAlt);
        }

        if (!$attachmentIdToSet) {
            error_log("Esti Sync (FeaturedImg): Both primary and default thumbnail failed for Post ID {$postId}");
            return;
        }

        if ($this->wpService->getPostThumbnailId($postId) == $attachmentIdToSet) {
            return;
        }

        $this->wpService->setPostThumbnail($postId, $attachmentIdToSet);
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
     * Creates or gets attachment for the default thumbnail.
     * 
     * @param int $postId Parent post ID
     * @param string $altText Alt text for the image
     * @return int|null Attachment ID on success, null on failure
     */
    private function getOrCreateDefaultThumbnail(int $postId, string $altText = ''): ?int
    {
        if (!file_exists(self::DEFAULT_THUMBNAIL_PATH)) {
            error_log("Esti Sync (DefaultThumbnail): Default thumbnail file not found at: " . self::DEFAULT_THUMBNAIL_PATH);
            return null;
        }

        $defaultThumbnailUrl = 'file://' . self::DEFAULT_THUMBNAIL_PATH;
        $existingAttachmentId = $this->findAttachmentBySourceUrl($defaultThumbnailUrl);

        if ($existingAttachmentId) {
            return $existingAttachmentId;
        }

        $filename = basename(self::DEFAULT_THUMBNAIL_PATH);
        $upload_dir = $this->wpService->wpUploadDir();

        if ($upload_dir['error']) {
            error_log("Esti Sync (DefaultThumbnail): Upload directory error: " . $upload_dir['error']);
            return null;
        }

        $new_filename = wp_unique_filename($upload_dir['path'], $filename);
        $new_file_path = $upload_dir['path'] . '/' . $new_filename;

        if (!copy(self::DEFAULT_THUMBNAIL_PATH, $new_file_path)) {
            error_log("Esti Sync (DefaultThumbnail): Failed to copy default thumbnail to uploads directory");
            return null;
        }

        $attachment = [
            'guid'           => $upload_dir['url'] . '/' . $new_filename,
            'post_mime_type' => 'image/webp',
            'post_title'     => !empty($altText) ? $altText : 'Default Property Thumbnail',
            'post_content'   => '',
            'post_status'    => 'inherit'
        ];

        $attachment_id = $this->wpService->wpInsertAttachment($attachment, $new_file_path, $postId);

        if (is_wp_error($attachment_id) || !$attachment_id) {
            error_log("Esti Sync (DefaultThumbnail): Failed to create attachment for default thumbnail");
            return null;
        }

        $this->wpService->updatePostMeta($attachment_id, self::META_SIDELOADED_URL, $defaultThumbnailUrl);

        if (!empty($altText)) {
            $this->wpService->updatePostMeta($attachment_id, '_wp_attachment_image_alt', $altText);
        }

        $this->regenerateThumbnailsForAttachment($attachment_id);

        return $attachment_id;
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