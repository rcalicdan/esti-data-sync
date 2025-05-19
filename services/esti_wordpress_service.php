<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Service class to interact with WordPress core functions.
 * This helps in centralizing WordPress calls and improves testability.
 */
class Esti_WordPress_Service
{
    /**
     * Ensures WordPress media functions are loaded.
     * Call this before using media-related functions if not in admin context.
     */
    public function ensureMediaFunctionsExist(): void
    {
        if (!function_exists('media_sideload_image')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }
    }

    /**
     * Wrapper for WP_Query.
     *
     * @param array $args Query arguments.
     * @return WP_Query
     */
    public function newWpQuery(array $args): WP_Query
    {
        return new WP_Query($args);
    }

    /**
     * Wrapper for wp_insert_post.
     *
     * @param array $postarr An array of elements that make up a post to be inserted.
     * @param bool  $wp_error Whether to return a WP_Error on failure. Default false.
     * @return int|WP_Error The post ID on success. The value 0 or WP_Error on failure.
     */
    public function wpInsertPost(array $postarr, bool $wp_error = false)
    {
        return wp_insert_post($postarr, $wp_error);
    }

    /**
     * Wrapper for wp_update_post.
     *
     * @param array|object $postarr An array or object of post data.
     * @param bool         $wp_error Whether to return a WP_Error on failure. Default false.
     * @return int|WP_Error The post ID on success. The value 0 or WP_Error on failure.
     */
    public function wpUpdatePost($postarr, bool $wp_error = false)
    {
        return wp_update_post($postarr, $wp_error);
    }

    /**
     * Wrapper for update_post_meta.
     *
     * @param int    $post_id Post ID.
     * @param string $meta_key Metadata key.
     * @param mixed  $meta_value Metadata value.
     * @param mixed  $prev_value Optional. Previous value to check before updating.
     * @return int|bool Meta ID if the key didn't exist, true on successful update, false on failure.
     */
    public function updatePostMeta(int $post_id, string $meta_key, $meta_value, $prev_value = '')
    {
        return update_post_meta($post_id, $meta_key, $meta_value, $prev_value);
    }

    /**
     * Wrapper for add_post_meta.
     *
     * @param int    $post_id Post ID.
     * @param string $meta_key Metadata key.
     * @param mixed  $meta_value Metadata value. Must be serializable if non-scalar.
     * @param bool   $unique Optional. Whether the same key should not be added. Default false.
     * @return int|false Meta ID on success, false on failure.
     */
    public function addPostMeta(int $post_id, string $meta_key, $meta_value, bool $unique = false)
    {
        return add_post_meta($post_id, $meta_key, $meta_value, $unique);
    }

    /**
     * Wrapper for delete_post_meta.
     *
     * @param int    $post_id Post ID.
     * @param string $meta_key Metadata key.
     * @param mixed  $meta_value Optional. Metadata value. Must be serializable if non-scalar.
     * @return bool True on success, false on failure.
     */
    public function deletePostMeta(int $post_id, string $meta_key, $meta_value = '')
    {
        return delete_post_meta($post_id, $meta_key, $meta_value);
    }

    /**
     * Wrapper for get_post_meta.
     *
     * @param int    $post_id Post ID.
     * @param string $key Optional. The meta key to retrieve. By default, returns data for all keys.
     * @param bool   $single Optional. Whether to return a single value. Default false.
     * @return mixed Will be an array if $single is false. Will be value of meta data field if $single is true.
     */
    public function getPostMeta(int $post_id, string $key = '', bool $single = false)
    {
        return get_post_meta($post_id, $key, $single);
    }

    /**
     * Wrapper for wp_set_object_terms.
     *
     * @param int          $object_id The object ID.
     * @param string|array $terms The slug or save term ID, or array of either.
     * @param string       $taxonomy Taxonomy name.
     * @param bool         $append Optional. If true, terms will be appended to the object. If false, terms will replace existing terms.
     * @return array|WP_Error Term taxonomy IDs of the affected terms. WP_Error on failure.
     */
    public function wpSetObjectTerms(int $object_id, $terms, string $taxonomy, bool $append = false)
    {
        return wp_set_object_terms($object_id, $terms, $taxonomy, $append);
    }

    /**
     * Wrapper for get_post_thumbnail_id.
     *
     * @param int|WP_Post|null $post Optional. Post ID or WP_Post object. Default is global $post.
     * @return int|false Post thumbnail ID or false if not set.
     */
    public function getPostThumbnailId($post = null)
    {
        return get_post_thumbnail_id($post);
    }

    /**
     * Wrapper for set_post_thumbnail.
     *
     * @param int|WP_Post $post Post ID or WP_Post object.
     * @param int         $thumbnail_id Thumbnail ID.
     * @return bool|int True on success, false on failure. Returns post thumbnail ID.
     */
    public function setPostThumbnail($post, int $thumbnail_id)
    {
        return set_post_thumbnail($post, $thumbnail_id);
    }

    /**
     * Wrapper for media_sideload_image.
     *
     * @param string      $file The URL of the image to download.
     * @param int         $post_id Optional. The post ID the media is to be attached to.
     * @param string|null $desc Optional. Description of the image.
     * @param string      $return_type Optional. How to return the attachment. Accepts 'html', 'src', or 'id'.
     * @return string|int|WP_Error HTML img element or attach ID on success, WP_Error object otherwise.
     */
    public function mediaSideloadImage(string $file, int $post_id = 0, ?string $desc = null, string $return_type = 'html')
    {
        $this->ensureMediaFunctionsExist();
        return media_sideload_image($file, $post_id, $desc, $return_type);
    }

    /**
     * Wrapper for get_attached_file.
     *
     * @param int  $attachment_id Attachment ID.
     * @param bool $unfiltered Optional. Whether to apply filters. Default false.
     * @return string|false The path to the attached file or false if an error occurs.
     */
    public function getAttachedFile(int $attachment_id, bool $unfiltered = false)
    {
        $this->ensureMediaFunctionsExist(); // May be needed for filters or if called in certain contexts
        return get_attached_file($attachment_id, $unfiltered);
    }

    /**
     * Wrapper for wp_generate_attachment_metadata.
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $file Path to attachment file.
     * @return array|WP_Error Returns computed metadata array or WP_Error object on error.
     */
    public function wpGenerateAttachmentMetadata(int $attachment_id, string $file)
    {
        $this->ensureMediaFunctionsExist();
        return wp_generate_attachment_metadata($attachment_id, $file);
    }

    /**
     * Wrapper for wp_update_attachment_metadata.
     *
     * @param int   $attachment_id Attachment ID.
     * @param array $data Attachment metadata.
     * @return bool True on success, false on failure.
     */
    public function wpUpdateAttachmentMetadata(int $attachment_id, array $data): bool
    {
        return wp_update_attachment_metadata($attachment_id, $data);
    }
}
