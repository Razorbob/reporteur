<?php

/**
 * Menu entry for the Alp Import plugin (registered under Tools / Werkzeuge).
 */
class PluginAlpimportMenu extends CommonGLPI
{
    public static $rightname = 'config';

    public static function getTypeName($nb = 0)
    {
        return __('Alp Import', 'alpimport');
    }

    public static function getMenuName()
    {
        return self::getTypeName();
    }

    public static function getMenuContent()
    {
        $menu = [
            'title' => self::getMenuName(),
            'page'  => '/plugins/alpimport/front/form.php',
            'icon'  => 'ti ti-file-import',
        ];

        return $menu;
    }

    public static function getIcon()
    {
        return 'ti ti-file-import';
    }
}
