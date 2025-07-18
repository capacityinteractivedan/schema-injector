<?php
/**
 * Plugin Name: Event Schema Injector
 * Plugin URI:  https://github.com/capacityinteractivedan/event-schema-injector
 * Description: Automatically injects Schema.org structured data for events based on CSV file data. Perfect for theaters, venues, and event organizers.
 * Version: 2.0.0 (Advanced Grouping)
 * Author:      Daniel Titmuss
 * License:     GPL v2 or later
 * Text Domain: event-schema-injector
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('ESI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ESI_PLUGIN_PATH', plugin_dir_path(__FILE__));

/**
 * Main plugin class
 */
class EventSchemaInjector {
    private $csv_data_cache = null;

    public function __construct() {
        add_action('init', array($this, 'init'));
    }

    public function init() {
        if (get_option('esi_enabled', 1)) {
            add_action('wp_head', array($this, 'inject_event_schema'));
        }
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_init', array($this, 'register_settings'));
        }
    }

    private function remove_null_values($array) {
        foreach ($array as $key => &$value) {
            if (is_array($value)) {
                $value = $this->remove_null_values($value);
            }
        }
        return array_filter($array, function($value) {
            return !is_null($value) && $value !== '' && (!is_array($value) || !empty($value));
        });
    }

    private function load_csv_data() {
    // 1. Check the fast instance cache first
    if ($this->csv_data_cache !== null) {
        return $this->csv_data_cache;
    }

    // 2. Check the persistent WordPress transient cache
    $cached_data = get_transient('esi_processed_events_data');
    if ($cached_data !== false) {
        $this->csv_data_cache = $cached_data; // Store in instance cache for this request
        return $cached_data;
    }

    // 3. If no cache exists, read from the CSV file
    $csv_file_path = get_option('esi_csv_file_path');
    if (!$csv_file_path || !file_exists($csv_file_path) || !is_readable($csv_file_path)) {
        return []; // Nothing to process, return empty
    }

    $event_data = [];
    if (($handle = fopen($csv_file_path, 'r')) !== FALSE) {
        $headers = fgetcsv($handle);
        if ($headers !== FALSE) {
            // Strip BOM character from the first header
            if (isset($headers[0])) {
                $headers[0] = preg_replace('/^\x{EF}\x{BB}\x{BF}/', '', $headers[0]);
            }
            $trimmed_headers = array_map('trim', $headers);

            while (($row = fgetcsv($handle)) !== FALSE) {
                if (count($trimmed_headers) == count($row)) {
                    $event_data[] = array_combine($trimmed_headers, $row);
                }
            }
        }
        fclose($handle);
    }
    
    // 4. Process the raw data into an efficient, URL-keyed array
    $processed_data = [];
    foreach ($event_data as $row) {
        if (!empty($row['MainPageURL'])) {
            $url = trim($row['MainPageURL']);
            if (!isset($processed_data[$url])) {
                $processed_data[$url] = [];
            }
            $processed_data[$url][] = $row;
        }
    }

    // 5. Store the efficiently structured data in the transient for 12 hours
    set_transient('esi_processed_events_data', $processed_data, 12 * HOUR_IN_SECONDS);
    
    $this->csv_data_cache = $processed_data;
    return $processed_data;
    }

    private function find_events_for_url($processed_data, $canonical_url) {
    // Instant lookup instead of a loop
    return $processed_data[$canonical_url] ?? [];
    }

    private function generate_grouped_event_schema($event_rows) {
        if (empty($event_rows)) {
            return null;
        }

        $grouped_events = [];

        foreach ($event_rows as $row) {
            $instance_key = $row['EventInstanceURL'] ?? null;
            if (!$instance_key) continue;

            if (!isset($grouped_events[$instance_key])) {
                $grouped_events[$instance_key] = [
                    '@context'    => 'https://schema.org',
                    '@type'       => 'Event',
                    '@id'         => $instance_key,
                    'name'        => $row['EventName'] ?? null,
                    'description' => $row['EventDescription'] ?? null,
                    'startDate'   => $row['EventStartDate'] ?? null,
                    'endDate'     => $row['EventEndDate'] ?? null,
                    'url'         => $instance_key,
                    'eventStatus' => !empty($row['EventStatus']) ? 'https://schema.org/' . $row['EventStatus'] : null,
                    'image'       => $row['EventImageURL'] ?? null,
                    'location'    => [
                        '@type'   => 'Place',
                        'name'    => $row['VenueName'] ?? null,
                        '@id'     => $row['VenueID'] ?? null,
                        'address' => [
                            '@type'           => 'PostalAddress',
                            'streetAddress'   => $row['VenueStreetAddress'] ?? null,
                            'addressLocality' => $row['VenueLocality'] ?? null,
                            'addressRegion'   => $row['VenueRegion'] ?? null,
                            'postalCode'      => $row['VenuePostalCode'] ?? null,
                            'addressCountry'  => $row['VenueCountry'] ?? null
                        ],
                    ],
                    'performer'   => [
                        '@type' => $row['PerformerType'] ?? 'PerformingGroup',
                        'name'  => $row['PerformerName'] ?? null,
                        '@id'   => $row['PerformerID'] ?? null
                    ],
                    'organizer'   => [
                        '@type' => 'Organization',
                        'name'  => $row['OrganizerName'] ?? null,
                        'url'   => $row['OrganizerURL'] ?? null,
                        '@id'   => $row['OrganizerID'] ?? null
                    ],
                    'offers'      => [],
                ];
            }

            $offer = [
                '@type'         => 'Offer',
                'name'          => $row['OfferName'] ?? null,
                'url'           => $row['OfferURL'] ?? null,
                'price'         => isset($row['OfferPrice']) ? filter_var($row['OfferPrice'], FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE) : null,
                'priceCurrency' => $row['OfferCurrency'] ?? null,
                'availability'  => !empty($row['OfferAvailability']) ? 'https://schema.org/' . $row['OfferAvailability'] : null,
            ];
            if(!empty($row['OfferID'])) {
                $offer['@id'] = $row['OfferID'];
            }
            
            $grouped_events[$instance_key]['offers'][] = $offer;
        }
        
        $final_events = array_map([$this, 'remove_null_values'], array_values($grouped_events));
        return count($final_events) > 0 ? $final_events : null;
    }

    public function inject_event_schema() {
        $canonical_url = wp_get_canonical_url();
        if (!$canonical_url) { return; }

        $csv_data = $this->load_csv_data();
        if (empty($csv_data)) { return; }

        $matching_rows = $this->find_events_for_url($csv_data, $canonical_url);
        if (empty($matching_rows)) { return; }
        
        $schema_data = $this->generate_grouped_event_schema($matching_rows);
        
        if (!empty($schema_data)) {
            echo '<script type="application/ld+json">';
            echo json_encode($schema_data, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_PRETTY_PRINT);
            echo '</script>' . "\n";
        }
    }

    public function add_admin_menu() {
        add_options_page(
            'Event Schema Settings',
            'Event Schema',
            'manage_options',
            'event-schema-injector',
            array($this, 'admin_page')
        );
    }

    public function register_settings() {
        register_setting('esi_settings', 'esi_csv_file_path');
        register_setting('esi_settings', 'esi_enabled');
    }

    public function admin_page() {
        if (isset($_POST['submit']) && isset($_POST['esi_csv_upload_nonce']) && wp_verify_nonce($_POST['esi_csv_upload_nonce'], 'esi_csv_upload_action')) {
            update_option('esi_enabled', isset($_POST['esi_enabled']) ? 1 : 0);
            if (isset($_FILES['esi_csv_file']) && $_FILES['esi_csv_file']['error'] === UPLOAD_ERR_OK) {
                $this->handle_csv_upload();
            }
            add_settings_error('esi_messages', 'esi_message', 'Settings Saved!', 'success');
        }
        settings_errors('esi_messages');
        ?>
        <div class="wrap">
            <h1>Event Schema Injector Settings</h1>
            <form method="post" action="" enctype="multipart/form-data">
                <?php
                wp_nonce_field('esi_csv_upload_action', 'esi_csv_upload_nonce');
                ?>
                <p>Upload a CSV file containing your event data to automatically inject Schema.org structured data.</p>
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row">Enable Plugin</th>
                            <td>
                                <?php
                                $enabled = get_option('esi_enabled', 1);
                                echo '<input type="checkbox" id="esi_enabled" name="esi_enabled" value="1" ' . checked(1, $enabled, false) . ' />';
                                echo '<label for="esi_enabled">Enable automatic schema injection</label>';
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Upload CSV File</th>
                            <td>
                                <?php
                                $current_file = get_option('esi_csv_file_path');
                                echo '<input type="file" name="esi_csv_file" accept=".csv" style="margin-bottom: 10px;" /><br>';
                                if ($current_file && file_exists($current_file)) {
                                    echo '<p><strong>Current file:</strong> ' . basename($current_file) . '</p>';
                                } else {
                                    echo '<p><em>No CSV file uploaded yet.</em></p>';
                                }
                                ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    private function handle_csv_upload() {
        $file_mime_type = mime_content_type($_FILES['esi_csv_file']['tmp_name']);
        $allowed_mime_types = ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'];

        if (!in_array($file_mime_type, $allowed_mime_types)) {
            add_settings_error('esi_messages', 'esi_message', 'Security Error: Invalid file type. Please upload a valid CSV file.', 'error');
            return;
        }
        
        $upload_dir = wp_upload_dir();
        $plugin_upload_dir = $upload_dir['basedir'] . '/event-schema-injector/';
        
        if (!file_exists($plugin_upload_dir)) {
            wp_mkdir_p($plugin_upload_dir);
        }
        
        $file_name = 'events-' . time() . '.csv';
        $target_path = $plugin_upload_dir . $file_name;
        
        if (move_uploaded_file($_FILES['esi_csv_file']['tmp_name'], $target_path)) {
            update_option('esi_csv_file_path', $target_path);
            $this->csv_data_cache = null;
            add_settings_error('esi_messages', 'esi_message', 'CSV file uploaded successfully!', 'updated');
        } else {
            add_settings_error('esi_messages', 'esi_message', 'Failed to upload CSV file.', 'error');
        }
    }
}

new EventSchemaInjector();

/**
 * Plugin activation hook
 */
register_activation_hook(__FILE__, 'esi_activate');
function esi_activate() {
    add_option('esi_enabled', 1);
    
    $upload_dir = wp_upload_dir();
    $plugin_upload_dir = $upload_dir['basedir'] . '/event-schema-injector/';
    if (!file_exists($plugin_upload_dir)) {
        wp_mkdir_p($plugin_upload_dir);
    }
}

/**
 * Plugin deactivation hook
 */
register_deactivation_hook(__FILE__, 'esi_deactivate');
function esi_deactivate() {
    // Clean up if needed (optional)
}
?>