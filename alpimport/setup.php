<?php

/**
 * Plugin setup entrypoint.
 */

define('PLUGIN_ALPIMPORT_VERSION', '0.1.0');

function plugin_init_alpimport()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['alpimport'] = true;
    $PLUGIN_HOOKS['menu_entry']['alpimport'] = 'front/form.php';
    $PLUGIN_HOOKS['menu_toadd']['alpimport'] = ['tools' => 'PluginAlpimportMenu'];

    Plugin::registerClass('PluginAlpimportMenu');
}

function plugin_version_alpimport()
{
    return [
        'name'           => 'Alp Import',
        'version'        => PLUGIN_ALPIMPORT_VERSION,
        'author'         => 'Custom',
        'license'        => 'GPLv3+',
        'homepage'       => '',
        'minGlpiVersion' => '11.0.0',
        'maxGlpiVersion' => '11.9.99',
    ];
}

function plugin_alpimport_check_prerequisites()
{
    if (!extension_loaded('zip')) {
        echo 'The PHP Zip extension is required.';
        return false;
    }
    if (!extension_loaded('simplexml')) {
        echo 'The PHP SimpleXML extension is required.';
        return false;
    }

    return true;
}

function plugin_alpimport_check_config($verbose = false)
{
    return true;
}

function plugin_alpimport_install()
{
    require_once __DIR__ . '/install/install.php';
    return plugin_alpimport_do_install();
}

function plugin_alpimport_uninstall()
{
    require_once __DIR__ . '/install/uninstall.php';
    return plugin_alpimport_do_uninstall();
}
