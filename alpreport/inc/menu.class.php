<?php

/**
 * Menu entry for the Alp Report plugin (registered under Tools / Werkzeuge).
 */
class PluginAlpreportMenu extends CommonGLPI
{
    public static $rightname = 'config';

    public static function getTypeName($nb = 0)
    {
        return __('Alp Report', 'alpreport');
    }

    public static function getMenuName()
    {
        return self::getTypeName();
    }

    public static function getMenuContent()
    {
        $menu = [
            'title' => self::getMenuName(),
            'page'  => '/plugins/alpreport/front/form.php',
            'icon'  => 'ti ti-file-text',
        ];

        return $menu;
    }

    public static function getIcon()
    {
        return 'ti ti-file-text';
    }
}
