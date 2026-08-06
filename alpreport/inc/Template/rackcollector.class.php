<?php

class PluginAlpreportRackCollector
{
    /**
     * @return array{rack:string,position:string,orientation:string,location:string}
     */
    public static function collectRackMount(CommonDBTM $item): array
    {
        global $DB;

        $result = ['rack' => '', 'position' => '', 'orientation' => '', 'location' => ''];

        if (!isset($DB) || !($DB instanceof DBmysql) || !$DB->tableExists('glpi_items_racks')) {
            return $result;
        }

        try {
            $iter = $DB->request([
                'FROM'  => 'glpi_items_racks',
                'WHERE' => [
                    'itemtype' => $item::getType(),
                    'items_id' => (int)$item->getID(),
                ],
                'ORDER' => 'id ASC',
                'LIMIT' => 1,
            ]);
            foreach ($iter as $row) {
                $rackId = (int)($row['racks_id'] ?? 0);
                if ($rackId > 0) {
                    $name = Dropdown::getDropdownName('glpi_racks', $rackId);
                    if ($name && $name !== '&nbsp;') {
                        $result['rack'] = trim(strip_tags($name));
                    }
                    if (class_exists('Rack')) {
                        try {
                            $rack = new Rack();
                            if ($rack->getFromDB($rackId) && !empty($rack->fields['locations_id'])) {
                                $locName = Dropdown::getDropdownName('glpi_locations', (int)$rack->fields['locations_id']);
                                if ($locName && $locName !== '&nbsp;') {
                                    $result['location'] = trim(strip_tags($locName));
                                }
                            }
                        } catch (Throwable $e) {
                            // ignore
                        }
                    }
                }
                $result['position']    = (string)($row['position'] ?? '');
                $orient                = (int)($row['orientation'] ?? 0);
                $result['orientation'] = $orient === 1 ? 'rear' : 'front';
            }
        } catch (Throwable $e) {
            // ignore
        }

        return $result;
    }

    /**
     * @return array{size:string,used_units:string,room:string,room_position:string,max_power:string,mesured_power:string,max_weight:string,mesured_weight:string,items:string[],item_rows:array<int,array<string,string>>}
     */
    public static function collectRackData(CommonDBTM $item): array
    {
        global $DB;

        $fields = $item->fields;
        $result = [
            'size'           => (string)($fields['number_units'] ?? ''),
            'used_units'     => '',
            'room'           => '',
            'room_position'  => (string)($fields['position'] ?? ''),
            'max_power'      => (string)($fields['max_power'] ?? ''),
            'mesured_power'  => (string)($fields['mesured_power'] ?? ''),
            'max_weight'     => (string)($fields['max_weight'] ?? ''),
            'mesured_weight' => (string)($fields['mesured_weight'] ?? ''),
            'items'          => [],
            'item_rows'      => [],
        ];

        if (!empty($fields['dcrooms_id']) && is_numeric($fields['dcrooms_id'])) {
            $name = Dropdown::getDropdownName('glpi_dcrooms', (int)$fields['dcrooms_id']);
            if ($name && $name !== '&nbsp;') {
                $result['room'] = trim(strip_tags($name));
            }
        }

        if (!isset($DB) || !($DB instanceof DBmysql) || !$DB->tableExists('glpi_items_racks')) {
            return $result;
        }

        try {
            $iter = $DB->request([
                'FROM'  => 'glpi_items_racks',
                'WHERE' => ['racks_id' => (int)$item->getID()],
                'ORDER' => 'position DESC',
            ]);
            $usedUnits = 0;
            foreach ($iter as $row) {
                $itype  = (string)($row['itemtype'] ?? '');
                $iid    = (int)($row['items_id'] ?? 0);
                $pos    = (string)($row['position'] ?? '');
                $orient = ((int)($row['orientation'] ?? 0)) === 1 ? 'rear' : 'front';
                $hpos   = (string)($row['hpos'] ?? '');

                $itemName = '';
                $itemLocation = '';
                if ($itype !== '' && class_exists($itype) && $iid > 0) {
                    $obj = new $itype();
                    if ($obj instanceof CommonDBTM && $obj->getFromDB($iid)) {
                        $itemName = (string)($obj->fields['name'] ?? '');
                        if (!empty($obj->fields['locations_id'])) {
                            $locName = Dropdown::getDropdownName('glpi_locations', (int)$obj->fields['locations_id']);
                            if ($locName && $locName !== '&nbsp;') {
                                $itemLocation = trim(strip_tags($locName));
                            }
                        }
                        $usedUnits += self::requiredUnits($DB, $itype, $obj);
                    }
                }

                $typeName = $itype !== '' && class_exists($itype) ? $itype::getTypeName(1) : $itype;
                $line = sprintf('U%s [%s] %s: %s', $pos, $orient . ($hpos !== '' ? '/' . $hpos : ''), $typeName, $itemName);
                if ($itemLocation !== '') {
                    $line .= ' @ ' . $itemLocation;
                }
                $result['items'][] = $line;
                $result['item_rows'][] = [
                    'position'    => $pos,
                    'orientation' => $orient . ($hpos !== '' ? '/' . $hpos : ''),
                    'type'        => $typeName,
                    'name'        => $itemName,
                    'location'    => $itemLocation,
                ];
            }
            $result['used_units'] = (string)$usedUnits;
        } catch (Throwable $e) {
            // ignore
        }

        return $result;
    }

    private static function requiredUnits(DBmysql $DB, string $itemtype, CommonDBTM $item): int
    {
        $modelTable = null;
        $modelId = 0;
        if ($itemtype === 'Computer' && !empty($item->fields['computermodels_id'])) {
            $modelTable = 'glpi_computermodels';
            $modelId = (int)$item->fields['computermodels_id'];
        } elseif ($itemtype === 'NetworkEquipment' && !empty($item->fields['networkequipmentmodels_id'])) {
            $modelTable = 'glpi_networkequipmentmodels';
            $modelId = (int)$item->fields['networkequipmentmodels_id'];
        }

        if (!$modelTable || $modelId <= 0 || !$DB->tableExists($modelTable)) {
            return 1;
        }

        try {
            $mIter = $DB->request([
                'SELECT' => ['required_units'],
                'FROM'   => $modelTable,
                'WHERE'  => ['id' => $modelId],
                'LIMIT'  => 1,
            ]);
            foreach ($mIter as $mRow) {
                return max(1, (int)($mRow['required_units'] ?? 1));
            }
        } catch (Throwable $e) {
            // ignore
        }

        return 1;
    }
}
