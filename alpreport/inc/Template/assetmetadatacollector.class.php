<?php

class PluginAlpreportAssetMetadataCollector
{
    /**
     * @return array{name:string,version:string,servicepack:string,kernel:string,architecture:string,edition:string,serial:string,license_id:string}
     */
    public static function collectOperatingSystem(CommonDBTM $item): array
    {
        global $DB;

        $info = [
            'name' => '', 'version' => '', 'servicepack' => '', 'kernel' => '',
            'architecture' => '', 'edition' => '', 'serial' => '', 'license_id' => '',
        ];

        if (!isset($DB) || !($DB instanceof DBmysql) || !$DB->tableExists('glpi_items_operatingsystems')) {
            return $info;
        }

        try {
            $iter = $DB->request([
                'FROM'  => 'glpi_items_operatingsystems',
                'WHERE' => [
                    'itemtype' => $item::getType(),
                    'items_id' => (int)$item->getID(),
                ],
                'ORDER' => 'id ASC',
                'LIMIT' => 1,
            ]);

            foreach ($iter as $row) {
                $info['serial']     = (string)($row['license_number'] ?? '');
                $info['license_id'] = (string)($row['licenseid'] ?? '');

                $resolveLookups = [
                    'name'         => ['operatingsystems_id', 'glpi_operatingsystems'],
                    'version'      => ['operatingsystemversions_id', 'glpi_operatingsystemversions'],
                    'servicepack'  => ['operatingsystemservicepacks_id', 'glpi_operatingsystemservicepacks'],
                    'kernel'       => ['operatingsystemkernelversions_id', 'glpi_operatingsystemkernelversions'],
                    'architecture' => ['operatingsystemarchitectures_id', 'glpi_operatingsystemarchitectures'],
                    'edition'      => ['operatingsystemeditions_id', 'glpi_operatingsystemeditions'],
                ];
                foreach ($resolveLookups as $key => [$field, $table]) {
                    if (!empty($row[$field]) && is_numeric($row[$field])) {
                        $name = Dropdown::getDropdownName($table, (int)$row[$field]);
                        if ($name && $name !== '&nbsp;') {
                            $info[$key] = trim(strip_tags($name));
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            // ignore
        }

        return $info;
    }

    /**
     * @param callable(string,int):(?string) $resolveForeignKey
     * @return string[]
     */
    public static function collectGroups(CommonDBTM $item, callable $resolveForeignKey): array
    {
        global $DB;

        $names = [];

        if (isset($DB) && $DB instanceof DBmysql && $DB->tableExists('glpi_groups_items')) {
            try {
                $iter = $DB->request([
                    'SELECT' => ['groups_id'],
                    'FROM'   => 'glpi_groups_items',
                    'WHERE'  => [
                        'itemtype' => $item::getType(),
                        'items_id' => (int)$item->getID(),
                    ],
                    'ORDER' => 'id ASC',
                ]);
                foreach ($iter as $row) {
                    $resolved = self::resolveGroupName((int)$row['groups_id'], $resolveForeignKey);
                    if ($resolved !== null) {
                        $names[] = $resolved;
                    }
                }
            } catch (Throwable $e) {
                // ignore
            }
        }

        foreach (['groups_id', 'groups_id_tech'] as $fieldKey) {
            if (!empty($item->fields[$fieldKey]) && is_numeric($item->fields[$fieldKey])) {
                $resolved = self::resolveGroupName((int)$item->fields[$fieldKey], $resolveForeignKey);
                if ($resolved !== null && !in_array($resolved, $names, true)) {
                    $names[] = $resolved;
                }
            }
        }

        return $names;
    }

    /**
     * @param callable(string,int):(?string) $resolveForeignKey
     */
    private static function resolveGroupName(int $id, callable $resolveForeignKey): ?string
    {
        $resolved = $resolveForeignKey('groups_id', $id);
        if ($resolved !== null) {
            return $resolved;
        }

        $name = Dropdown::getDropdownName('glpi_groups', $id);
        return ($name && $name !== '&nbsp;') ? trim(strip_tags($name)) : null;
    }

    /**
     * @param callable(string,int):(?string) $resolveForeignKey
     * @return string[]
     */
    public static function collectDomains(CommonDBTM $item, callable $resolveForeignKey): array
    {
        global $DB;

        $names = [];

        if (isset($DB) && $DB instanceof DBmysql && $DB->tableExists('glpi_domains_items')) {
            try {
                $iter = $DB->request([
                    'SELECT' => ['domains_id'],
                    'FROM'   => 'glpi_domains_items',
                    'WHERE'  => [
                        'itemtype' => $item::getType(),
                        'items_id' => (int)$item->getID(),
                    ],
                ]);
                foreach ($iter as $row) {
                    $resolved = $resolveForeignKey('domains_id', (int)$row['domains_id']);
                    if ($resolved !== null) {
                        $names[] = $resolved;
                    }
                }
            } catch (Throwable $e) {
                // ignore
            }
        }

        if (!empty($item->fields['domains_id']) && is_numeric($item->fields['domains_id'])) {
            $resolved = $resolveForeignKey('domains_id', (int)$item->fields['domains_id']);
            if ($resolved !== null && !in_array($resolved, $names, true)) {
                $names[] = $resolved;
            }
        }

        return $names;
    }
}
