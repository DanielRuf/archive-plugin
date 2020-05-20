<?php

/**
 * Plugin Name: Archive Plugin
 * Plugin URI: https://github.com/DanielRuf/wp-archive-plugin
 * Description: Archive a plugin as encrypted/password protected zip file.
 * Version: 1.0.0
 * Author: Daniel Ruf
 * Author URI: https://daniel-ruf.de
 */

// prevent direct access
if (!defined('ABSPATH')) {
    exit('Forbidden');
}

// prevent access outside of wp-admin
if (!function_exists('is_admin')) {
    exit('Forbidden');
}

// add additional plugin link
function add_plugin_link($plugin_actions, $plugin_file, $plugin_data)
{
    // prepare new actions
    $new_actions = array();
    $plugin_name = $plugin_data['Name'] ?? '';
    $archived_string = '(archived)';
    $is_archived = false;
    if (strpos($plugin_name, $archived_string) === (strlen($plugin_name) - strlen($archived_string))) {
        $is_archived = true;
    }
    // build URL query object
    $url = add_query_arg(
        [
            'page' => $is_archived ? 'unarchive-plugin' : 'archive-plugin', // page name for menu
            'action' => $is_archived ? 'unarchive-plugin' : 'archive-plugin', // internal action
            'plugin'   => $plugin_file, // plugin entry file
            'nonce'  => wp_create_nonce('archive-plugin'), // generate nonce
        ],
        admin_url('plugins.php') // use wp-admin/plugins.php
    );
    // only add the new link if the plugin is inactive and if the user can delete plugins
    if (!$is_archived && isset($plugin_actions['activate']) && current_user_can('delete_plugins')) {
        $new_actions['archive'] = sprintf(__('<a href="%s">Archive & Delete</a>', 'archive-plugin'), esc_url($url));
    } else if ($is_archived && isset($plugin_actions['activate']) && current_user_can('install_plugins')) {
        $plugin_actions = array();
        $new_actions['unarchive'] = sprintf(__('<a href="%s">Unarchive</a>', 'archive-plugin'), esc_url($url));
    }
    // append the new link
    return array_merge($plugin_actions, $new_actions);
}

// finally call the function when the plugin links are rendered
add_filter('plugin_action_links', 'add_plugin_link', 10, 3);

// archive and delete the supplied plugin
function archive_plugin($plugin_file)
{
    // check if the current user can delete plugins
    if (current_user_can('delete_plugins')) {
        // cancel if the plugin is still active
        if (is_plugin_active($plugin_file)) {
            exit;
        };
        // get the plugin name
        $plugin = basename(plugin_dir_path($plugin_file));
        // get all plugins
        $all_plugins = get_plugins();
        // assume that the plugin is not installed
        $plugin_found = false;
        $plugin_data = null;
        // check if the plugin is installed
        foreach ($all_plugins as $current_plugin => $current_plugin_data) {
            if ($current_plugin === $plugin_file) {
                $plugin_found = true;
                $plugin_data = $current_plugin_data;
                break;
            }
        }
        // cancel if it is not found
        if (!$plugin_found) {
            exit;
        }
        // assume that this is in wp-plugins/plugins
        $is_subfolder = true;
        // if this is not the case the file is the whole plugin
        if ($plugin === '.') {
            $is_subfolder = false;
            $plugin = $plugin_file;
        }
        // the root path of the files to archive
        $root_path = WP_PLUGIN_DIR . '/' . $plugin;
        // set zip archive filename
        $zipcreated = WP_PLUGIN_DIR . '/' . $plugin . '_archived.zip';
        // create new zip archive instance
        $zip = new ZipArchive;
        // try to create zip archive
        if ($zip->open($zipcreated, ZipArchive::CREATE) === TRUE) {
            // zip recursively if it is a folder
            if ($is_subfolder) {
                // create recursive directory iterator
                /** @var SplFileInfo[] $files */
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($root_path),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                // iterate over the files
                foreach ($files as $name => $file) {
                    // skip directories (they would be added automatically)
                    if (!$file->isDir()) {
                        // get real and relative path for current file
                        $file_path = $file->getRealPath();
                        $relative_path = substr($file_path, strlen($root_path) + 1);

                        // add current file to archive
                        $zip->addFile($file_path, $relative_path);
                        // encrypt with the SECURE_AUTH_SALT constant as password using AES 256
                        $zip->setEncryptionName($relative_path, ZipArchive::EM_AES_256, SECURE_AUTH_SALT);
                    }
                }
            } else {
                // add current file to archive
                $zip->addFile($root_path, $plugin_file);
                // encrypt with the SECURE_AUTH_SALT constant as password using AES 256
                $zip->setEncryptionName($plugin_file, ZipArchive::EM_AES_256, SECURE_AUTH_SALT);
            }
            // zip archive will be created only after closing object
            $result = $zip->close();
            if ($result) {
                // delete plugin if zip archive was successfully created
                $deleted = delete_plugins([$plugin_file]);
                if ($deleted) {
                    create_archived_plugin_placeholder_file($root_path, $plugin_data);
                }
            }
        }
    }
}

// unarchive the supplied plugin
function unarchive_plugin($plugin_file) {
    $plugin = str_replace('_archived.php', '', $plugin_file);
    $php_string = '.php';
    $extract_to = WP_PLUGIN_DIR . '/' . $plugin;
    if (strpos($plugin, $php_string) === (strlen($plugin) - strlen($php_string))) {
        $extract_to = WP_PLUGIN_DIR;
    }
    $zipfile = WP_PLUGIN_DIR . '/' . $plugin . '_archived.zip';
    $zip = new ZipArchive;
    if ($zip->open($zipfile) === TRUE) {
        $zip->setPassword(SECURE_AUTH_SALT);
        $zip->extractTo($extract_to);
        $zip->close();
        unlink(WP_PLUGIN_DIR . '/' . $plugin_file);
        unlink($zipfile);
    }
}

// run the archive plugin on the page
function archive_plugin_init()
{
    // set some heading
    echo '<h1>Archive plugin</h1>';
    // check the provided parameters and verify the nonce
    if (
        isset($_GET['plugin']) &&
        isset($_GET['action']) &&
        isset($_GET['page']) &&
        $_GET['action'] === 'archive-plugin' &&
        $_GET['page'] === 'archive-plugin' &&
        wp_verify_nonce($_GET['nonce'], 'archive-plugin') &&
        is_admin()
    ) {
        // run the archive plugin logic
        archive_plugin($_GET['plugin']);
    }
}

// run the unarchive plugin on the page
function unarchive_plugin_init()
{
    // set some heading
    echo '<h1>Unarchive plugin</h1>';
    // check the provided parameters and verify the nonce
    if (
        isset($_GET['plugin']) &&
        isset($_GET['action']) &&
        isset($_GET['page']) &&
        $_GET['action'] === 'unarchive-plugin' &&
        $_GET['page'] === 'unarchive-plugin' &&
        wp_verify_nonce($_GET['nonce'], 'archive-plugin') &&
        is_admin()
    ) {
        // run the unarchive plugin logic
        unarchive_plugin($_GET['plugin']);
    }
}

// add new page to the admin menu
function archive_plugin_setup_menu()
{
    // set page title, menu title, capability, menu slug and the function to call 
    add_submenu_page(null, 'Archive Plugin Page', 'Archive Plugin', 'delete_plugins', 'archive-plugin', 'archive_plugin_init');
    add_submenu_page(null, 'Archive Plugin Page', 'Unarchive Plugin', 'install_plugins', 'unarchive-plugin', 'unarchive_plugin_init');
}

// finally call the function to add the new page
add_action('admin_menu', 'archive_plugin_setup_menu');

function create_archived_plugin_placeholder_file($path, $plugin_data)
{
    $template = <<<EOT
<?php

/**
 * Plugin Name: %s (archived)
 * Plugin URI: %s
 * Description: %s
 * Version: %s
 * Author: %s
 * Author URI: %s
 */

// prevent direct access
if (!defined('ABSPATH')) {
    exit('Forbidden');
}

// prevent access outside of wp-admin
if (!function_exists('is_admin')) {
    exit('Forbidden');
}
EOT;
    $template_filled = sprintf(
        $template,
        $plugin_data['Name'] ?? '',
        $plugin_data['PluginURI'] ?? '',
        $plugin_data['Description'] ?? '',
        $plugin_data['Version'] ?? '',
        $plugin_data['Author'] ?? '',
        $plugin_data['AuthorURI'] ?? '',
        '%s'
    );
    file_put_contents($path . '_archived.php', $template_filled);
}