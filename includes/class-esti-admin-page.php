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
     * Get debug information HTML (temporary for troubleshooting)
     *
     * @return string Debug information HTML
     */
    private function get_debug_info_html(): string
    {
        global $esti_main;

        if (!$esti_main || !method_exists($esti_main, 'get_debug_info')) {
            return <<<HTML
             <div class="notice notice-info">
                <p>Debug info not available</p>
             </div>
            HTML;
        }

        $debug = $esti_main->get_debug_info();
        $debug_items = $this->build_debug_list_items($debug);

        return <<<HTML
         <div class="notice notice-info">
              <p><strong>Json Data File Information:</strong></p>
              <ul>
                 {$debug_items}
              </ul>
        </div>
        HTML;
    }

    /**
     * Build the debug list items HTML
     *
     * @param array $debug Debug data
     * @return string HTML list items
     */
    private function build_debug_list_items(array $debug): string
    {
        $items = '';

        foreach ($debug as $key => $value) {
            if (is_array($value)) {
                $sub_items = $this->build_sub_list_items($value);
                $escaped_key = esc_html($key);

                $items .= <<<HTML
                 <li>
                   <strong>{$escaped_key}:</strong>
                      <ul>
                       {$sub_items}
                     </ul>
                 </li>
            HTML;
            } else {
                $escaped_key = esc_html($key);
                $escaped_value = esc_html($this->format_value($value));

                $items .= <<<HTML
                    <li><strong>{$escaped_key}:</strong> {$escaped_value}</li>
                HTML;
            }
        }

        return $items;
    }

    /**
     * Build sub-list items for nested arrays
     *
     * @param array $items Array items
     * @return string HTML sub-list items
     */
    private function build_sub_list_items(array $items): string
    {
        $sub_items = '';

        foreach ($items as $subkey => $subvalue) {
            $escaped_subkey = esc_html($subkey);
            $escaped_subvalue = esc_html($this->format_value($subvalue));

            $sub_items .= <<<HTML
              <li>{$escaped_subkey}: {$escaped_subvalue}</li>
            HTML;
        }

        return $sub_items;
    }

    /**
     * Format value for display (handles boolean conversion)
     *
     * @param mixed $value Value to format
     * @return string Formatted value
     */
    private function format_value($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
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
        $error_message = $this->get_error_message();
        $debug_info = $this->get_debug_info_html();

        $page_html = <<<HTML
        <div class="wrap">
            <h1>{$this->translate('Esti Data Synchronizer')}</h1>
            <p>{$this->translate('Synchronize property data from the JSON file into your WordPress site.')}</p>
            
            {$error_message}
            {$sync_results_html}
             {$debug_info}

            <form method="post" action="{$this->get_admin_post_url()}">
                <input type="hidden" name="action" value="esti_perform_sync">
                {$this->get_nonce_field()}
                
                <table class="form-table">
                    <tr>
                        <th scope="row">{$this->translate('Sync Mode')}</th>
                        <td>
                            <label>
                                <input type="radio" name="sync_mode" value="count" checked>
                                {$this->translate('Sync by count')}
                            </label><br>
                            <label>
                                <input type="radio" name="sync_mode" value="range">
                                {$this->translate('Sync by index range')}
                            </label>
                        </td>
                    </tr>
                    <tr id="count_row">
                        <th scope="row">
                            <label for="items_to_process">{$this->translate('Number of items to process:')}</label>
                        </th>
                        <td>
                            <input type="number" id="items_to_process" name="items_to_process" value="2" min="0">
                            <p class="description">{$this->translate('0 for all items, default 2 for testing')}</p>
                        </td>
                    </tr>
                    <tr id="range_row" style="display: none;">
                        <th scope="row">{$this->translate('Index Range')}</th>
                        <td>
                            <label for="start_index">{$this->translate('Start Index:')}</label>
                            <input type="number" id="start_index" name="start_index" value="0" min="0" style="margin-right: 10px;">
                            
                            <label for="end_index">{$this->translate('End Index:')}</label>
                            <input type="number" id="end_index" name="end_index" value="10" min="0">
                            <p class="description">{$this->translate('Both indices are inclusive (e.g., 0 to 5 will process 6 items)')}</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">{$this->translate('Skip Duplicates')}</th>
                        <td>
                            <label>
                                <input type="checkbox" name="skip_duplicates" value="1" checked>
                                {$this->translate('Skip entries that already exist (based on portalTitle)')}
                            </label>
                        </td>
                    </tr>
                </table>

                {$this->get_submit_button()}
            </form>
            <p><em>{$this->translate('Note: Processing many items can take time and resources. For large datasets, consider running this in batches or via WP-CLI if performance becomes an issue.')}</em></p>
            
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                const syncModeRadios = document.querySelectorAll('input[name="sync_mode"]');
                const countRow = document.getElementById('count_row');
                const rangeRow = document.getElementById('range_row');
                
                syncModeRadios.forEach(radio => {
                    radio.addEventListener('change', function() {
                        if (this.value === 'count') {
                            countRow.style.display = 'table-row';
                            rangeRow.style.display = 'none';
                        } else {
                            countRow.style.display = 'none';
                            rangeRow.style.display = 'table-row';
                        }
                    });
                });
            });
            </script>
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
                <!-- <li>{$this->translate_with_count('Skipped: %d',$results['skipped'])}</li>
                <li>{$this->translate_with_count('Errors: %d',$results['error'])}</li> -->
            </ul>
            {$messages_html}
        </div>
        HTML;

        delete_transient('esti_sync_results');
        return $results_html;
    }

    /**
     * Get error message HTML if there are validation errors
     *
     * @since 1.0.0
     * @return string Error message HTML or empty string
     */
    private function get_error_message()
    {
        if (!isset($_GET['error'])) {
            return '';
        }

        $error_messages = [
            'invalid_range' => $this->translate('Error: Start index must be less than or equal to end index.'),
            'invalid_input' => $this->translate('Error: Invalid input parameters.')
        ];

        $error_key = sanitize_text_field($_GET['error']);
        $message = $error_messages[$error_key] ?? $this->translate('An unknown error occurred.');

        return '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
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
