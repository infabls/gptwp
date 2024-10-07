<?php
/**
 * Plugin Name: OpenAI Content Rewriter
 * Description: A plugin that rewrites content from URLs using OpenAI's GPT-3 API, with integration for Yoast SEO.
 * Version: 1.2
 * Author: German Bragin
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Create a menu item in the WordPress admin dashboard
add_action('admin_menu', 'openai_content_rewriter_menu');
function openai_content_rewriter_menu() {
    add_menu_page(
        'Content Rewriter', 
        'Content Rewriter', 
        'manage_options', 
        'openai-content-rewriter', 
        'openai_content_rewriter_page', 
        'dashicons-admin-tools', 
        6
    );
    
    // Add a submenu for API key settings
    add_submenu_page(
        'openai-content-rewriter', 
        'OpenAI Settings', 
        'API Settings', 
        'manage_options', 
        'openai-content-rewriter-settings', 
        'openai_content_rewriter_settings_page'
    );
}

// Display the plugin's admin page for rewriting content
function openai_content_rewriter_page() {
    ?>
    <div class="wrap">
        <h1>OpenAI Content Rewriter</h1>
        <form method="post" action="">
            <label for="url">Enter URL to Rewrite Content:</label><br>
            <input type="url" name="url" id="url" required style="width: 50%;"><br><br>
            <input type="submit" name="generate_content" class="button button-primary" value="Generate Content">
        </form>
        <br>
    <?php
    if (isset($_POST['generate_content'])) {
        $url = sanitize_text_field($_POST['url']);
        $content = fetch_and_rewrite_content($url);
        if ($content) {
            echo '<h2>Rewritten Content:</h2>';
            echo '<textarea rows="15" style="width: 100%;">' . esc_html($content) . '</textarea>';
            generate_seo_meta($content);
        } else {
            echo '<p>Error fetching or rewriting content. Please check the URL.</p>';
        }
    }
    echo '</div>';
}

// Add settings page for API key
function openai_content_rewriter_settings_page() {
    ?>
    <div class="wrap">
        <h1>OpenAI API Settings</h1>
        <form method="post" action="options.php">
            <?php
                settings_fields('openai_content_rewriter_settings');
                do_settings_sections('openai-content-rewriter-settings');
                submit_button();
            ?>
        </form>
    </div>
    <?php
}

// Register settings, sections, and fields
add_action('admin_init', 'openai_content_rewriter_register_settings');
function openai_content_rewriter_register_settings() {
    register_setting('openai_content_rewriter_settings', 'openai_api_key');

    add_settings_section(
        'openai_content_rewriter_settings_section',
        'OpenAI API Key',
        'openai_content_rewriter_settings_section_callback',
        'openai-content-rewriter-settings'
    );

    add_settings_field(
        'openai_api_key_field',
        'API Key',
        'openai_content_rewriter_api_key_callback',
        'openai-content-rewriter-settings',
        'openai_content_rewriter_settings_section'
    );
}

function openai_content_rewriter_settings_section_callback() {
    echo 'Please enter your OpenAI API key to enable content rewriting.';
}

function openai_content_rewriter_api_key_callback() {
    $api_key = get_option('openai_api_key', '');
    ?>
    <input type="text" name="openai_api_key" value="<?php echo esc_attr($api_key); ?>" style="width: 50%;" />
    <p class="description">Enter your OpenAI API key here.</p>
    <?php
}

// Fetch content from the provided URL and rewrite using OpenAI API
function fetch_and_rewrite_content($url) {
    $response = wp_remote_get($url);
    
    if (is_wp_error($response)) {
        return false;
    }
    
    $html = wp_remote_retrieve_body($response);
    
    // Extract content (you can adjust this as per your content structure)
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $paragraphs = $xpath->query('//p');
    
    $content = '';
    foreach ($paragraphs as $p) {
        $content .= $p->nodeValue . "\n";
    }
    
    // Rewrite the content using OpenAI API
    $api_key = get_option('openai_api_key', ''); // Retrieve the API key from the database
    if (!$api_key) {
        return 'API key not set. Please configure it in the settings.';
    }
    
    $model = 'gpt-3.5-turbo';
    $prompt = "Rewrite the following content to make it unique:\n" . $content;

    $api_response = wp_remote_post('https://api.openai.com/v1/completions', array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json',
        ),
        'body' => json_encode(array(
            'model' => $model,
            'prompt' => $prompt,
            'max_tokens' => 500,
        )),
    ));

    if (is_wp_error($api_response)) {
        return false;
    }

    $response_body = wp_remote_retrieve_body($api_response);
    $result = json_decode($response_body, true);
    
    return $result['choices'][0]['text'] ?? false;
}

// Generate SEO meta title and description using Yoast SEO or other SEO plugins
function generate_seo_meta($content) {
    $title_prompt = "Generate an SEO-friendly title for the following content:\n" . $content;
    $desc_prompt = "Generate an SEO-friendly meta description for the following content:\n" . $content;

    // Make a call to OpenAI for title and description generation
    $title = call_openai_api($title_prompt);
    $description = call_openai_api($desc_prompt);
    
    if (function_exists('wpseo_replace_vars')) {
        update_post_meta(get_the_ID(), '_yoast_wpseo_title', sanitize_text_field($title));
        update_post_meta(get_the_ID(), '_yoast_wpseo_metadesc', sanitize_text_field($description));
    } else {
        echo '<p>Yoast SEO or other SEO plugin not detected.</p>';
    }

    echo '<h3>Generated SEO Title:</h3><p>' . esc_html($title) . '</p>';
    echo '<h3>Generated SEO Description:</h3><p>' . esc_html($description) . '</p>';
}

// Utility function to make OpenAI API call
function call_openai_api($prompt) {
    $api_key = get_option('openai_api_key', ''); // Retrieve the API key from the settings
    if (!$api_key) {
        return 'API key not set. Please configure it in the settings.';
    }

    $model = 'gpt-3.5-turbo';

    $api_response = wp_remote_post('https://api.openai.com/v1/completions', array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json',
        ),
        'body' => json_encode(array(
            'model' => $model,
            'prompt' => $prompt,
            'max_tokens' => 100,
        )),
    ));

    if (is_wp_error($api_response)) {
        return false;
    }

    $response_body = wp_remote_retrieve_body($api_response);
    $result = json_decode($response_body, true);
    
    return $result['choices'][0]['text'] ?? '';
}

/** 
 * Auto-update functionality
 */
add_filter('pre_set_site_transient_update_plugins', 'openai_content_rewriter_check_for_update');
function openai_content_rewriter_check_for_update($transient) {
    if (empty($transient->checked)) {
        return $transient;
    }

    // URL of the update-info.json file hosted on your server
    $remote_url = 'https://github.com/infabls/gptwp/blob/main/update.json';

    $remote = wp_remote_get($remote_url);

    if (!is_wp_error($remote) && isset($remote['response']['code']) && $remote['response']['code'] == 200) {
        $remote_body = json_decode(wp_remote_retrieve_body($remote), true);

        // Check if the remote version is higher than the installed version
        if (version_compare($transient->checked['openai-content-rewriter/openai-content-rewriter.php'], $remote_body['new_version'], '<')) {
            $transient->response['openai-content-rewriter/openai-content-rewriter.php'] = (object) array(
                'new_version' => $remote_body['new_version'],
                'package'     => $remote_body['download_url'],
                'url'         => 'https://your-server.com/', // Plugin homepage or documentation
                'slug'        => 'openai-content-rewriter',
                'tested'      => '6.3', // Tested up to WP version
            );
        }
    }

    return $transient;
}

// Register plugin update information
add_filter('plugins_api', 'openai_content_rewriter_plugins_api', 20, 3);
function openai_content_rewriter_plugins_api($res, $action, $args) {
    // Check if the action is for the plugin
    if ($action === 'plugin_information' && isset($args->slug) && $args->slug === 'openai-content-rewriter') {
        $remote_url = 'https://github.com/infabls/gptwp/blob/main/update.json';
        $remote = wp_remote_get($remote_url);

        if (!is_wp_error($remote) && isset($remote['response']['code']) && $remote['response']['code'] == 200) {
            $remote_body = json_decode(wp_remote_retrieve_body($remote), true);

            $res = (object) array(
                'name'         => 'OpenAI Content Rewriter',
                'slug'         => 'openai-content-rewriter',
                'version'      => $remote_body['new_version'],
                'author'       => 'Your Name',
                'download_link'=> $remote_body['download_url'],
                'tested'       => '6.3',
                'sections'     => array(
                    'description'  => 'Automatically rewrite content from URLs using OpenAI\'s GPT-3 API and generate SEO-friendly titles and descriptions.',
                    'changelog'    => $remote_body['changelog'],
                ),
            );
        }
    }

    return $res;
}