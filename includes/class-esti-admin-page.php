<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Esti_Admin_Page {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_admin_menu_page' ] );
    }

    public function add_admin_menu_page() {
        add_menu_page(
            __( 'Esti Data Sync', 'esti-data-sync' ),
            __( 'Esti Sync', 'esti-data-sync' ),
            'manage_options',
            'esti-data-sync',
            [ $this, 'render_admin_page' ],
            'dashicons-update-alt',
            26
        );
    }

    public function render_admin_page() {
        ?>
        <div class="wrap">
            <h1><?php _e( 'Esti Data Synchronizer', 'esti-data-sync' ); ?></h1>
            <p><?php _e( 'Synchronize property data from the JSON file into your WordPress site.', 'esti-data-sync' ); ?></p>

            <?php
            // Display results from transient if sync was just performed
            if ( isset($_GET['synced']) && $_GET['synced'] == 'true' ) {
                $results = get_transient('esti_sync_results');
                if ($results) {
                    echo '<div id="message" class="updated notice is-dismissible">';
                    echo '<p><strong>' . __('Sync Results:', 'esti-data-sync') . '</strong></p>';
                    echo '<ul>';
                    echo '<li>' . sprintf(__('Successfully synced: %d', 'esti-data-sync'), $results['success']) . '</li>';
                    echo '<li>' . sprintf(__('Skipped: %d', 'esti-data-sync'), $results['skipped']) . '</li>';
                    echo '<li>' . sprintf(__('Errors: %d', 'esti-data-sync'), $results['error']) . '</li>';
                    echo '</ul>';
                    if (!empty($results['messages'])) {
                        echo '<p><strong>' . __('Details:', 'esti-data-sync') . '</strong></p><ul style="max-height: 200px; overflow-y: auto;">';
                        foreach ($results['messages'] as $message) {
                            echo '<li>' . esc_html($message) . '</li>';
                        }
                        echo '</ul>';
                    }
                    echo '</div>';
                    delete_transient('esti_sync_results');
                }
            }
            ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="esti_perform_sync">
                <?php wp_nonce_field( 'esti_sync_action', 'esti_sync_nonce' ); ?>
                
                <p>
                    <label for="items_to_process"><?php _e('Number of items to process (0 for all, default 2 for testing):', 'esti-data-sync'); ?></label>
                    <input type="number" id="items_to_process" name="items_to_process" value="2" min="0">
                </p>

                <?php submit_button( __( 'Start Synchronization', 'esti-data-sync' ) ); ?>
            </form>
            <p><em><?php _e('Note: Processing many items can take time and resources. For large datasets, consider running this in batches or via WP-CLI if performance becomes an issue.', 'esti-data-sync'); ?></em></p>
        </div>
        <?php
    }
}