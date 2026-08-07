<?php

class PluginAlpreportPlainTextPlaceholderBuilder
{
    /**
     * Build scalar placeholders such as {{asset_name}}.
     *
     * @param string[] $knownDeviceTypes
     * @param callable(string,int):(?string) $resolveForeignKey
     * @return array<string,string>
     */
    public static function build(
        PluginAlpreportTemplateData $data,
        array $knownDeviceTypes,
        callable $resolveForeignKey
    ): array {
        $item = $data->asset;
        $fields = $item->fields;
        $map = [];

        PluginAlpreportPlaceholderRegistry::seedDefaults($map, $knownDeviceTypes);

        $map['{{asset_type}}']          = $item::getTypeName(1);
        $map['{{asset_id}}']            = (string)($fields['id'] ?? '');
        $map['{{asset_name}}']          = (string)($fields['name'] ?? '');
        $map['{{asset_hostname}}']      = (string)($fields['hostname'] ?? '');
        $map['{{asset_serial}}']        = (string)($fields['serial'] ?? '');
        $map['{{asset_otherserial}}']   = (string)($fields['otherserial'] ?? '');
        $map['{{asset_comment}}']       = (string)($fields['comment'] ?? '');
        $map['{{asset_contact}}']       = (string)($fields['contact'] ?? '');
        $map['{{asset_contact_num}}']   = (string)($fields['contact_num'] ?? '');
        $map['{{asset_uuid}}']          = (string)($fields['uuid'] ?? '');
        $map['{{asset_date_mod}}']      = (string)($fields['date_mod'] ?? '');
        $map['{{asset_date_creation}}'] = (string)($fields['date_creation'] ?? '');
        $map['{{generated_at}}']        = date('Y-m-d H:i:s');

        foreach ($fields as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $map['{{field_' . $key . '}}'] = (string)$value;
            }

            if (is_string($key) && substr($key, -3) === '_id' && !empty($value) && is_numeric($value)) {
                $resolved = $resolveForeignKey($key, (int)$value);
                if ($resolved !== null) {
                    $map['{{hardware_' . $key . '}}'] = $resolved;
                    $map['{{hardware_' . substr($key, 0, -3) . '}}'] = $resolved;
                }
            }
        }

        $aliasTables = self::assetAliasTables($item);
        foreach (self::assetAliases($item) as $alias => $fieldKey) {
            if (!empty($fields[$fieldKey]) && is_numeric($fields[$fieldKey])) {
                $resolved = $resolveForeignKey($fieldKey, (int)$fields[$fieldKey]);
                if ($resolved === null && isset($aliasTables[$alias])) {
                    $resolved = self::dropdownName($aliasTables[$alias], (int)$fields[$fieldKey]);
                }
                if ($resolved !== null) {
                    $map['{{asset_' . $alias . '}}'] = $resolved;
                }
            }
        }

        self::addUserPlaceholders($map, $fields);
        self::addCollectedPlaceholders($map, $data);

        return $map;
    }

    /**
     * @return array<string,string>
     */
    private static function assetAliases(CommonDBTM $item): array
    {
        $aliases = [
            'location'     => 'locations_id',
            'state'        => 'states_id',
            'manufacturer' => 'manufacturers_id',
            'model'        => 'computermodels_id',
            'type'         => 'computertypes_id',
            'os'           => 'operatingsystems_id',
            'group'        => 'groups_id',
            'entity'       => 'entities_id',
            'network'      => 'networks_id',
            'domain'       => 'domains_id',
        ];

        if ($item instanceof NetworkEquipment) {
            $aliases['model']    = 'networkequipmentmodels_id';
            $aliases['type']     = 'networkequipmenttypes_id';
            $aliases['firmware'] = 'networkequipmentfirmwares_id';
        }
        if (class_exists('Rack') && $item instanceof Rack) {
            $aliases['model'] = 'rackmodels_id';
            $aliases['type']  = 'racktypes_id';
        }
        if (class_exists('Printer') && $item instanceof Printer) {
            $aliases['model'] = 'printermodels_id';
            $aliases['type']  = 'printertypes_id';
        }

        return $aliases;
    }

    /**
     * @return array<string,string>
     */
    private static function assetAliasTables(CommonDBTM $item): array
    {
        $tables = [
            'location'     => 'glpi_locations',
            'state'        => 'glpi_states',
            'manufacturer' => 'glpi_manufacturers',
            'model'        => 'glpi_computermodels',
            'type'         => 'glpi_computertypes',
            'group'        => 'glpi_groups',
            'entity'       => 'glpi_entities',
            'network'      => 'glpi_networks',
            'domain'       => 'glpi_domains',
        ];

        if ($item instanceof NetworkEquipment) {
            $tables['model'] = 'glpi_networkequipmentmodels';
            $tables['type'] = 'glpi_networkequipmenttypes';
            $tables['firmware'] = 'glpi_networkequipmentfirmwares';
        }
        if (class_exists('Rack') && $item instanceof Rack) {
            $tables['model'] = 'glpi_rackmodels';
            $tables['type'] = 'glpi_racktypes';
        }
        if (class_exists('Printer') && $item instanceof Printer) {
            $tables['model'] = 'glpi_printermodels';
            $tables['type'] = 'glpi_printertypes';
        }

        return $tables;
    }

    private static function dropdownName(string $table, int $id): ?string
    {
        $name = Dropdown::getDropdownName($table, $id);
        return ($name && $name !== '&nbsp;') ? trim(strip_tags($name)) : null;
    }

    /**
     * @param array<string,string> $map
     * @param array<string,mixed> $fields
     */
    private static function addUserPlaceholders(array &$map, array $fields): void
    {
        if (empty($fields['users_id'])) {
            return;
        }

        $map['{{asset_user_name}}'] = getUserName((int)$fields['users_id']);
        $user = new User();
        if ($user->getFromDB((int)$fields['users_id'])) {
            $map['{{asset_user_login}}']    = (string)($user->fields['name'] ?? '');
            $map['{{asset_user_realname}}'] = trim(((string)($user->fields['realname'] ?? '')) . ' ' . ((string)($user->fields['firstname'] ?? '')));
            $map['{{asset_user_email}}']    = method_exists($user, 'getDefaultEmail') ? (string)$user->getDefaultEmail() : '';
            $map['{{asset_user_phone}}']    = (string)($user->fields['phone'] ?? '');
        }
    }

    /**
     * @param array<string,string> $map
     */
    private static function addCollectedPlaceholders(array &$map, PluginAlpreportTemplateData $data): void
    {
        $map['{{asset_hostname}}'] = $data->primaryNetwork['hostname'] ?? '';
        $map['{{asset_ip}}']       = $data->primaryNetwork['ip'] ?? '';
        $map['{{asset_mac}}']      = $data->primaryNetwork['mac'] ?? '';

        $os = $data->operatingSystem;
        if (($os['name'] ?? '') !== '') {
            $map['{{asset_os}}']              = $os['name'];
            $map['{{asset_os_version}}']      = $os['version'];
            $map['{{asset_os_servicepack}}']  = $os['servicepack'];
            $map['{{asset_os_kernel}}']       = $os['kernel'];
            $map['{{asset_os_architecture}}'] = $os['architecture'];
            $map['{{asset_os_edition}}']      = $os['edition'];
            $map['{{asset_os_serial}}']       = $os['serial'];
            $map['{{asset_os_license_id}}']   = $os['license_id'];
        }

        if (!empty($data->groups)) {
            $map['{{asset_group}}'] = $data->groups[0];
        }
        if (!empty($data->domains)) {
            $map['{{asset_domain}}'] = implode(', ', $data->domains);
        }

        foreach ($data->components as $deviceType => $list) {
            $shortKey = strtolower((string)preg_replace('/^Device/', '', (string)$deviceType));
            $map['{{components_' . $shortKey . '}}'] = implode(', ', $list['lines'] ?? []);
            $map['{{components_' . $shortKey . '_count}}'] = (string)count($list['lines'] ?? []);
            $map['{{components_' . $shortKey . '_serial}}'] = implode(', ', array_filter($list['serials'] ?? []));
        }

        $map['{{network_ports}}'] = ($data->networkPorts['lines'] ?? []) !== []
            ? implode("\n", $data->networkPorts['lines'])
            : '';
        $map['{{network_ports_count}}'] = (string)count($data->networkPorts['lines'] ?? []);

        $rackMount = $data->rackMount;
        if (($rackMount['rack'] ?? '') !== '') {
            $map['{{asset_rack}}']             = $rackMount['rack'];
            $map['{{asset_rack_position}}']    = $rackMount['position'];
            $map['{{asset_rack_orientation}}'] = $rackMount['orientation'];
            $map['{{asset_rack_location}}']    = $rackMount['location'];
        }

        if (!empty($data->rackData)) {
            $map['{{rack_size}}']           = (string)($data->rackData['size'] ?? '');
            $map['{{rack_used_units}}']     = (string)($data->rackData['used_units'] ?? '');
            $map['{{rack_room}}']           = (string)($data->rackData['room'] ?? '');
            $map['{{rack_room_position}}']  = (string)($data->rackData['room_position'] ?? '');
            $map['{{rack_max_power}}']      = (string)($data->rackData['max_power'] ?? '');
            $map['{{rack_mesured_power}}']  = (string)($data->rackData['mesured_power'] ?? '');
            $map['{{rack_max_weight}}']     = (string)($data->rackData['max_weight'] ?? '');
            $map['{{rack_mesured_weight}}'] = (string)($data->rackData['mesured_weight'] ?? '');
            $map['{{rack_items}}']          = implode("\n", $data->rackData['items'] ?? []);
            $map['{{rack_items_count}}']    = (string)count($data->rackData['items'] ?? []);
        }

        if (!empty($data->software)) {
            $softwareLines = [];
            $softwareSerials = [];
            foreach ($data->software as $entry) {
                $line = $entry['name'];
                if ($entry['version'] !== '') {
                    $line .= ' ' . $entry['version'];
                }
                if ($entry['serial'] !== '') {
                    $line .= ' [SN: ' . $entry['serial'] . ']';
                    $softwareSerials[] = $entry['name'] . '=' . $entry['serial'];
                }
                $softwareLines[] = $line;
            }
            $map['{{software}}']        = implode(', ', $softwareLines);
            $map['{{software_serial}}'] = implode(', ', $softwareSerials);
            $map['{{software_count}}']  = (string)count($data->software);
        }

        if (!empty($data->monitors)) {
            $map['{{monitors_count}}'] = (string)count($data->monitors);
        }
    }

}
