<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Esti_Admin_Page
 * 
 * Handles the admin interface for Esti Data Synchronization
 * 
 * @since 1.0.0
 */
class Esti_Admin_Page
{

    /**
     * Constructor - registers admin hooks
     *
     * @since 1.0.0
     */
    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_admin_menu_page']);
    }

    /**
     * Register the admin menu page
     *
     * @since 1.0.0
     * @return void
     */
    public function add_admin_menu_page()
    {
        add_menu_page(
            __('Esti Data Sync', 'esti-data-sync'),
            __('Esti Sync', 'esti-data-sync'),
            'manage_options',
            'esti-data-sync',
            [$this, 'render_admin_page'],
            'dashicons-update-alt',
            26
        );
    }

    /**
     * Render the admin page content
     *
     * @since 1.0.0
     * @return void
     */
    public function render_admin_page()
    {
        $sync_results_html = $this->get_sync_results_html();

        $page_html = <<<HTML
        <div class="wrap">
            <h1>{$this->translate('Esti Data Synchronizer')}</h1>
            <p>{$this->translate('Synchronize property data from the JSON file into your WordPress site.')}</p>
            
            {$sync_results_html}

            <form method="post" action="{$this->get_admin_post_url()}">
                <input type="hidden" name="action" value="esti_perform_sync">
                {$this->get_nonce_field()}
                
                <p>
                    <label for="items_to_process">{$this->translate('Number of items to process (0 for all, default 2 for testing):')}</label>
                    <input type="number" id="items_to_process" name="items_to_process" value="2" min="0">
                </p>

                {$this->get_submit_button()}
            </form>
            <p><em>{$this->translate('Note: Processing many items can take time and resources. For large datasets, consider running this in batches or via WP-CLI if performance becomes an issue.')}</em></p>
        </div>
        HTML;

        echo $page_html;
    }

    /**
     * Generate the HTML for displaying sync results
     *
     * @since 1.0.0
     * @return string HTML for sync results or empty string if no results
     */
    private function get_sync_results_html()
    {
        if (!isset($_GET['synced']) || $_GET['synced'] !== 'true') {
            return '';
        }

        $results = get_transient('esti_sync_results');
        if (!$results) {
            return '';
        }

        $messages_html = '';
        if (!empty($results['messages'])) {
            $messages_html = '<p><strong>' . $this->translate('Details:') . '</strong></p><ul style="max-height: 200px; overflow-y: auto;">';
            foreach ($results['messages'] as $message) {
                $messages_html .= '<li>' . esc_html($message) . '</li>';
            }
            $messages_html .= '</ul>';
        }

        $results_html = <<<HTML
        <div id="message" class="updated notice is-dismissible">
            <p><strong>{$this->translate('Sync Results:')}</strong></p>
            <ul>
                <li>{$this->translate_with_count('Successfully synced: %d',$results['success'])}</li>
                <li>{$this->translate_with_count('Skipped: %d',$results['skipped'])}</li>
                <li>{$this->translate_with_count('Errors: %d',$results['error'])}</li>
            </ul>
            {$messages_html}
        </div>
        HTML;

        delete_transient('esti_sync_results');
        return $results_html;
    }

    /**
     * Helper method to translate text
     *
     * @since 1.0.0
     * @param string $text Text to translate
     * @return string Translated text
     */
    private function translate($text)
    {
        return __($text, 'esti-data-sync');
    }

    /**
     * Helper method to translate text with a count
     *
     * @since 1.0.0
     * @param string $text Text to translate with a %d placeholder
     * @param int $count Count to insert into the placeholder
     * @return string Translated text with count
     */
    private function translate_with_count($text, $count)
    {
        return sprintf(__($text, 'esti-data-sync'), $count);
    }

    /**
     * Get escaped admin post URL
     *
     * @since 1.0.0
     * @return string Escaped admin-post.php URL
     */
    private function get_admin_post_url()
    {
        return esc_url(admin_url('admin-post.php'));
    }

    /**
     * Get WordPress nonce field
     *
     * @since 1.0.0
     * @return string Nonce field HTML
     */
    private function get_nonce_field()
    {
        return wp_nonce_field('esti_sync_action', 'esti_sync_nonce', true, false);
    }

    /**
     * Get submit button HTML
     *
     * @since 1.0.0
     * @return string Submit button HTML
     */
    private function get_submit_button()
    {
        return get_submit_button(__('Start Synchronization', 'esti-data-sync'));
    }
}
