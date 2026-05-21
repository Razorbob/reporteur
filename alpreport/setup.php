<?php

/**
 * Plugin setup entrypoint.
 */

define('PLUGIN_ALPREPORT_VERSION', '1.0.0');

function plugin_init_alpreport()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['alpreport'] = true;
    $PLUGIN_HOOKS['menu_entry']['alpreport'] = 'front/form.php';
    $PLUGIN_HOOKS['menu_toadd']['alpreport'] = ['tools' => 'PluginAlpreportMenu'];

    Plugin::registerClass('PluginAlpreportMenu');
}

function plugin_version_alpreport()
{
    return [
        'name'           => 'Alp Report',
        'version'        => PLUGIN_ALPREPORT_VERSION,
        'author'         => 'Custom',
        'license'        => 'GPLv3+',
        'homepage'       => '',
        'minGlpiVersion' => '11.0.0',
        'maxGlpiVersion' => '11.9.99',
    ];
}

function plugin_alpreport_check_prerequisites()
{
    if (!extension_loaded('zip')) {
        echo 'The PHP Zip extension is required.';
        return false;
    }

    return true;
}

function plugin_alpreport_check_config($verbose = false)
{
    return true;
}

function plugin_alpreport_install()
{
    require_once __DIR__ . '/install/install.php';
    return plugin_alpreport_do_install();
}

function plugin_alpreport_uninstall()
{
    require_once __DIR__ . '/install/uninstall.php';
    return plugin_alpreport_do_uninstall();
}
