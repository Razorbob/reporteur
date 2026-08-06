<?php

class PluginAlpreportPlaceholderRegistry
{
    /**
     * Pre-seed all known placeholders so unresolved keys never leak as {{...}}.
     *
     * @param array<string,string> $map
     * @param string[] $knownDeviceTypes
     */
    public static function seedDefaults(array &$map, array $knownDeviceTypes): void
    {
        foreach (self::scalarPlaceholders() as $key) {
            $map['{{' . $key . '}}'] = '';
        }

        foreach ($knownDeviceTypes as $deviceType) {
            $shortKey = strtolower((string)preg_replace('/^Device/', '', (string)$deviceType));
            $map['{{components_' . $shortKey . '}}'] = '';
            $map['{{components_' . $shortKey . '_count}}'] = '0';
            $map['{{components_' . $shortKey . '_serial}}'] = '';
        }

        foreach (self::rowTableColumns() as $prefix => $columns) {
            foreach ($columns as $column) {
                $map['{{' . $prefix . '_' . $column . '}}'] = '';
            }
        }

        foreach (self::blockTablePlaceholders() as $placeholder => $defaultValue) {
            $map['{{' . $placeholder . '}}'] = $defaultValue;
        }
    }

    /**
     * @return string[]
     */
    public static function scalarPlaceholders(): array
    {
        return [
            'asset_type', 'asset_id', 'asset_name', 'asset_serial', 'asset_otherserial',
            'asset_comment', 'asset_contact', 'asset_contact_num', 'asset_uuid',
            'asset_date_mod', 'asset_date_creation', 'generated_at',
            'asset_location', 'asset_state', 'asset_manufacturer', 'asset_model',
            'asset_type', 'asset_os', 'asset_os_version', 'asset_os_servicepack',
            'asset_os_kernel', 'asset_os_architecture', 'asset_os_edition', 'asset_os_serial', 'asset_os_license_id',
            'asset_group', 'asset_entity', 'asset_network', 'asset_domain',
            'asset_user_name', 'asset_user_login', 'asset_user_realname',
            'asset_user_email', 'asset_user_phone',
            'asset_ip', 'asset_mac',
            'asset_firmware', 'asset_ram', 'asset_cpu',
            'network_ports', 'network_ports_count',
            'asset_rack', 'asset_rack_position', 'asset_rack_orientation', 'asset_rack_location',
            'rack_size', 'rack_used_units', 'rack_room', 'rack_room_position',
            'rack_max_power', 'rack_mesured_power', 'rack_max_weight', 'rack_mesured_weight',
            'rack_items', 'rack_items_count',
        ];
    }

    /**
     * @return array<string,string[]>
     */
    public static function rowTableColumns(): array
    {
        return [
            'components_processor' => ['manufacturer', 'model', 'cores', 'frequency', 'threads'],
            'components_memory' => ['manufacturer', 'model', 'size', 'frequency', 'type'],
            'components_harddrive' => ['manufacturer', 'model', 'size', 'type'],
            'components_networkcard' => ['manufacturer', 'model', 'mac', 'flow'],
            'components_graphiccard' => ['manufacturer', 'model', 'memory', 'interface', 'comment'],
            'monitors' => ['manufacturer', 'model', 'size', 'type', 'serial', 'serial_number'],
        ];
    }

    /**
     * @return array<string,string>
     */
    public static function blockTablePlaceholders(): array
    {
        return [
            'software' => '',
            'software_serial' => '',
            'software_count' => '0',
            'monitors_count' => '0',
        ];
    }
}
