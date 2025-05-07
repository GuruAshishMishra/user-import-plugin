<?php
/**
 * Plugin Name: User Import Plugin
 * Description: Import users with roles via CSV/XML with batch processing support for large files
 * Version: 1.0.0
 * Author: v0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class UserImportPlugin {
    private $plugin_path;
    private $plugin_url;
    private $import_table = 'wp_user_imports';
    private $batch_size = 500; // Process 500 rows at a time

    public function __construct() {
        $this->plugin_path = plugin_dir_path(__FILE__);
        $this->plugin_url = plugin_dir_url(__FILE__);

        // Register activation hook
        register_activation_hook(__FILE__, array($this, 'activate_plugin'));

        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Register AJAX handlers
        add_action('wp_ajax_process_import_batch', array($this, 'process_import_batch'));
        add_action('wp_ajax_get_import_progress', array($this, 'get_import_progress'));

        // Enqueue scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));

        // ✅ New action to delete users
        // add_action('admin_init', array($this, 'delete_all_except_specific_user'));
    }

    // Delete users
    public function delete_all_except_specific_user() {
        // Only allow admin users
        if (!current_user_can('administrator')) {
            return;
        }

        $keep_email = 'ami.globaliasoft@gmail.com';
        $users = get_users();

        foreach ($users as $user) {
            if ($user->user_email !== $keep_email) {
                wp_delete_user($user->ID);
            }
        }

        // Optional message (only for testing)
        echo 'All users except ' . esc_html($keep_email) . ' have been deleted.';
        exit;
    }

    // Create Table on plugin active
    public function activate_plugin() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->import_table} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            file_name varchar(255) NOT NULL,
            file_id int(11) NOT NULL,
            file_path varchar(255) NOT NULL,
            post_type varchar(50) NOT NULL,
            total_rows int(11) NOT NULL DEFAULT 0,
            processed int(11) NOT NULL DEFAULT 0,
            skipped int(11) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'new',
            import_date datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    // Add Admin Menu
    public function add_admin_menu() {
        add_menu_page(
            'User Import',
            'User Import',
            'manage_options',
            'user-import',
            array($this, 'render_admin_page'),
            'dashicons-groups',
            30
        );
    }

    // Enqueue JS/CSS
    public function enqueue_scripts($hook) {
        if ($hook != 'toplevel_page_user-import') {
            return;
        }

        wp_enqueue_style('user-import-css', $this->plugin_url . 'assets/css/user-import.css', array(), '1.0.0');
        wp_enqueue_script('user-import-js', $this->plugin_url . 'assets/js/user-import.js', array('jquery'), '1.0.0', true);
        
        $upload_dir = wp_upload_dir();

        wp_localize_script('user-import-js', 'userImportData', array(
            'ajax_url'    => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce('user_import_nonce'),
            'uploads_url' => $upload_dir['baseurl'], // e.g., http://localhost/wp-content/uploads
            'uploads_dir' => $upload_dir['basedir']  // if needed for server path usage
        ));
    }

    // Admin page
    public function render_admin_page() {
        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'import';
        ?>
        <div class="wrap user-import-wrap">
            <h1>User Import</h1>
            
            <h2 class="nav-tab-wrapper">
                <a href="?page=user-import&tab=import" class="nav-tab <?php echo $active_tab == 'import' ? 'nav-tab-active' : ''; ?>">Import</a>
                <a href="?page=user-import&tab=history" class="nav-tab <?php echo $active_tab == 'history' ? 'nav-tab-active' : ''; ?>">History</a>
            </h2>
            
            <div class="tab-content">
                <?php
                if ($active_tab == 'import') {
                    $this->render_import_tab();
                } else {
                    $this->render_history_tab();
                }
                ?>
            </div>
        </div>
        <?php
    }

    // Import Tab
    private function render_import_tab() {
        ?>
        <div class="import-container">
            <h3>Import new xml/csv</h3>
            
            <div id="file-upload-container">
                <form id="user-import-form" method="post" enctype="multipart/form-data">
                    <div class="form-group">
                        <label for="import-file">Select file:</label>
                        <input type="file" name="import_file" id="import-file" accept=".xml,.csv">
                    </div>
                    
                    <div id="file-details" style="display: none;">
                        <p><strong>Title: </strong><span id="file-title"></span></p>
                        <p><strong>Size: </strong><span id="file-size"></span></p>
                        <p><strong>URL: </strong><span id="file-url"></span></p>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" id="select-file-btn" class="button">Select file</button>
                        <button type="submit" id="import-btn" class="button button-primary" style="display: none;">Import</button>
                    </div>
                </form>
            </div>
            
            <div id="import-progress-container" style="display: none;">
                <div class="progress-info">
                    <p><strong>Percentage Complete: </strong><span id="percentage-complete">0%</span></p>
                    <p><strong>Processed: </strong><span id="processed-count">0/0</span></p>
                    <p><strong>File: </strong><span id="file-info"></span></p>
                </div>
                <div class="progress-bar-container">
                    <div class="progress-bar" style="width: 0%"></div>
                </div>
                <div class="loader-container">
                    <div class="loader"></div>
                </div>
            </div>
        </div>
        <?php
    }

    // history Tab
    private function render_history_tab() {
        global $wpdb;
        
        $imports = $wpdb->get_results("SELECT * FROM {$this->import_table} ORDER BY import_date DESC");
        
        ?>
        <div class="history-container">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>File</th>
                        <th>Post type</th>
                        <th>Processed</th>
                        <th>Skipped</th>
                        <th>Status</th>
                        <th>Import Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($imports)) : ?>
                        <tr>
                            <td colspan="7">No import history found.</td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($imports as $import) : ?>
                            <tr>
                                <td><?php echo $import->id; ?></td>
                                <td><?php echo $import->file_id; ?></td>
                                <td><?php echo $import->post_type; ?></td>
                                <td><?php echo $import->processed . ' of ' . $import->total_rows; ?></td>
                                <td><?php echo $import->skipped; ?></td>
                                <td><?php echo $import->status; ?></td>
                                <td><?php echo $import->import_date; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    // Import user Ajax
    public function process_import_batch() {
        check_ajax_referer('user_import_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $import_id = isset($_POST['import_id']) ? intval($_POST['import_id']) : 0;
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;

        global $wpdb;

        if (!$import_id) {
            // First batch, handle file upload and create import record
            if (!isset($_FILES['import_file'])) {
                wp_send_json_error('No file uploaded');
            }

            $file = $_FILES['import_file'];
            $file_name = sanitize_file_name($file['name']);
            $file_type = pathinfo($file_name, PATHINFO_EXTENSION);

            if (!in_array($file_type, ['xml', 'csv'])) {
                wp_send_json_error('Invalid file type. Only XML and CSV files are supported.');
            }

            // Upload file to Media Library
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');

            $overrides = array('test_form' => false);
            $uploaded_file = wp_handle_upload($file, $overrides);

            if (isset($uploaded_file['error'])) {
                wp_send_json_error('Upload error: ' . $uploaded_file['error']);
            }

            // Register file in Media Library
            $attachment = array(
                'post_mime_type' => $uploaded_file['type'],
                'post_title'     => sanitize_file_name($file_name),
                'post_content'   => '',
                'post_status'    => 'inherit'
            );

            $attach_id = wp_insert_attachment($attachment, $uploaded_file['file']);
            $file_path = $uploaded_file['file'];

            // Count total rows
            $total_rows = $this->count_total_rows($file_path, $file_type);

            // Create import record
            $wpdb->insert(
                $this->import_table,
                array(
                    'file_name'   => $file_name,
                    'file_id'     => $attach_id,
                    'file_path'   => $file_path,
                    'post_type'   => 'rao',
                    'total_rows'  => $total_rows,
                    'status'      => 'processing',
                    'processed'   => 0
                )
            );

            $import_id = $wpdb->insert_id;

            wp_send_json_success(array(
                'import_id'   => $import_id,
                'total_rows'  => $total_rows,
                'file_name'   => $file_name,
                'message'     => 'Import started'
            ));
        } else {
            // Process batch
            $import = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->import_table} WHERE id = %d", $import_id));

            if (!$import) {
                wp_send_json_error('Import not found');
            }

            $file_type = pathinfo($import->file_name, PATHINFO_EXTENSION);
            $processed = $this->process_batch($import->file_path, $file_type, $offset, $this->batch_size);

            // Update import record
            $new_processed = $import->processed + $processed;
            $status = $new_processed >= $import->total_rows ? 'completed' : 'processing';

            $wpdb->update(
                $this->import_table,
                array(
                    'processed' => $new_processed,
                    'status'    => $status
                ),
                array('id' => $import_id)
            );

            wp_send_json_success(array(
                'processed'  => $new_processed,
                'total_rows' => $import->total_rows,
                'percentage' => round(($new_processed / $import->total_rows) * 100),
                'status'     => $status,
                'message'    => 'Batch processed'
            ));
        }
    }

    // Import progress
    public function get_import_progress() {
        check_ajax_referer('user_import_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $import_id = isset($_POST['import_id']) ? intval($_POST['import_id']) : 0;
        
        if (!$import_id) {
            wp_send_json_error('Invalid import ID');
        }
        
        global $wpdb;
        
        $import = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->import_table} WHERE id = %d", $import_id));
        
        if (!$import) {
            wp_send_json_error('Import not found');
        }
        
        wp_send_json_success(array(
            'processed' => $import->processed,
            'total_rows' => $import->total_rows,
            'percentage' => round(($import->processed / $import->total_rows) * 100),
            'status' => $import->status,
            'file_name' => $import->file_name
        ));
    }

    // Count Row
    private function count_total_rows($file_path, $file_type) {
        if ($file_type === 'xml') {
            $xml = simplexml_load_file($file_path);
            return count($xml->xpath('//user')); // Assuming users are in <user> tags
        } else {
            $row_count = 0;
            $handle = fopen($file_path, 'r');
            while (fgetcsv($handle)) {
                $row_count++;
            }
            fclose($handle);
            return $row_count - 1; // Subtract header row
        }
    }

    // Progress Bar
    private function process_batch($file_path, $file_type, $offset, $batch_size) {
        $processed = 0;
        
        if ($file_type === 'xml') {
            $xml = simplexml_load_file($file_path);
            $users = $xml->xpath('//user');
            
            $batch_users = array_slice($users, $offset, $batch_size);
            
            foreach ($batch_users as $user) {
                $this->create_or_update_user($user, 'xml');
                $processed++;
            }
        } else {
            $handle = fopen($file_path, 'r');
            
            // Skip header and previous rows
            for ($i = 0; $i <= $offset; $i++) {
                fgetcsv($handle);
            }
            
            // Process batch
            for ($i = 0; $i < $batch_size; $i++) {
                $data = fgetcsv($handle);
                if ($data === false) {
                    break;
                }
                
                $this->create_or_update_user($data, 'csv');
                $processed++;
            }
            
            fclose($handle);
        }
        
        return $processed;
    }

    // Create user
    private function create_or_update_user($data, $type) {
        // Extract user data based on file type
        if ($type === 'xml') {
            $username = (string)$data->username;
            $email = (string)$data->email;
            $first_name = (string)$data->first_name;
            $last_name = (string)$data->last_name;
            $role = (string)$data->role;
            $password = wp_generate_password();
        } else {
            // Assuming CSV columns: username, email, first_name, last_name, role
            $username = $data[0];
            $email = $data[1];
            $first_name = $data[2];
            $last_name = $data[3];
            $role = $data[4];
            $password = wp_generate_password();
        }
        
        // Check if user exists
        $user_id = username_exists($username);
        
        if (!$user_id && email_exists($email) === false) {
            // Create new user
            $user_id = wp_create_user($username, $password, $email);
            
            if (!is_wp_error($user_id)) {
                // Update user data
                wp_update_user(array(
                    'ID' => $user_id,
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'role' => $role
                ));
            }
        } else {
            // Update existing user
            wp_update_user(array(
                'ID' => $user_id,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'role' => $role
            ));
        }
        
        // Sleep briefly to prevent server overload
        usleep(10000); // 10ms
    }
}

// Initialize the plugin
$user_import_plugin = new UserImportPlugin();
