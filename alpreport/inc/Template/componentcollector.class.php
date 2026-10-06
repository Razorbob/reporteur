<?php

class PluginAlpreportComponentCollector
{
    /**
     * Components grouped by device class.
     *
     * @param string[] $knownDeviceTypes
     * @param callable(string,int):(?string) $resolveForeignKey
     * @return array<string,array{lines:string[],serials:string[],rows:array<int,array<string,string>>}>
     */
    public static function collectComponents(
        CommonDBTM $item,
        array $knownDeviceTypes,
        callable $resolveForeignKey
    ): array {
        global $DB;

        $grouped = [];

        if (!isset($DB) || !($DB instanceof DBmysql)) {
            return $grouped;
        }

        $itemtype = $item::getType();
        $id = (int)$item->getID();
        $networkPortRowsForComponents = null;

        $deviceTypes = $knownDeviceTypes;
        if (class_exists('CommonDevice') && method_exists('CommonDevice', 'getDeviceTypes')) {
            $discovered = CommonDevice::getDeviceTypes();
            if (is_array($discovered) && !empty($discovered)) {
                $deviceTypes = array_values(array_unique(array_merge($deviceTypes, $discovered)));
            }
        }

        foreach ($deviceTypes as $deviceType) {
            if (strpos($deviceType, 'Item_') === 0) {
                $deviceType = substr($deviceType, 5);
            }
            $linkClass = 'Item_' . $deviceType;
            if (!class_exists($linkClass) || !class_exists($deviceType)) {
                continue;
            }

            $linkTable = function_exists('getTableForItemType') ? getTableForItemType($linkClass) : null;
            $deviceTable = function_exists('getTableForItemType') ? getTableForItemType($deviceType) : null;
            $deviceFk = function_exists('getForeignKeyFieldForItemType')
                ? getForeignKeyFieldForItemType($deviceType)
                : strtolower($deviceType) . 's_id';

            if (!$linkTable || !$deviceTable) {
                continue;
            }

            try {
                $iter = $DB->request([
                    'SELECT' => ['*'],
                    'FROM'   => $linkTable,
                    'WHERE'  => [
                        'itemtype' => $itemtype,
                        'items_id' => $id,
                    ],
                ]);

                foreach ($iter as $linkRow) {
                    $deviceId = (int)($linkRow[$deviceFk] ?? 0);
                    if ($deviceId <= 0) {
                        continue;
                    }

                    $name = Dropdown::getDropdownName($deviceTable, $deviceId);
                    $name = ($name && $name !== '&nbsp;') ? trim(strip_tags($name)) : ('#' . $deviceId);

                    $devRow = self::deviceRow($DB, $deviceTable, $deviceId);
                    $serial = self::resolveComponentSerial($deviceType, $linkRow, $devRow);
                    $comment = $deviceType === 'DeviceGraphicCard'
                        ? self::resolveGraphicCardComment($linkRow, $devRow)
                        : '';
                    $otherserial = trim((string)($linkRow['otherserial'] ?? ''));
                    $manufacturer = self::manufacturer($devRow);
                    $frequency = self::resolveComponentFrequency($linkRow, $devRow);

                    $extras = [];
                    if ($serial !== '') {
                        $extras[] = 'serial=' . $serial;
                    }
                    if ($otherserial !== '') {
                        $extras[] = 'otherserial=' . $otherserial;
                    }
                    foreach (['busID', 'capacity'] as $extraKey) {
                        $extraValue = trim((string)($linkRow[$extraKey] ?? ''));
                        if ($extraValue !== '') {
                            $extras[] = $extraKey . '=' . $extraValue;
                        }
                    }
                    if ($frequency !== '') {
                        $extras[] = 'frequency=' . $frequency;
                    }

                    if (!isset($grouped[$deviceType])) {
                        $grouped[$deviceType] = ['lines' => [], 'serials' => [], 'rows' => []];
                    }

                    $grouped[$deviceType]['lines'][] = $extras
                        ? $name . ' (' . implode(', ', $extras) . ')'
                        : $name;
                    if ($serial !== '') {
                        $grouped[$deviceType]['serials'][] = $serial;
                    }

                    $row = [
                        'name'         => $name,
                        'manufacturer' => $manufacturer,
                        'serial'       => $serial,
                        'comment'      => $comment,
                        'otherserial'  => $otherserial,
                        'capacity'     => (string)($linkRow['capacity'] ?? ''),
                        'frequency'    => $frequency,
                        'busID'        => (string)($linkRow['busID'] ?? ''),
                        'link_id'      => (string)($linkRow['id'] ?? ''),
                    ];

                    if ($deviceType === 'DeviceProcessor') {
                        self::addProcessorColumns($row, $linkRow, $devRow, $name);
                    } elseif ($deviceType === 'DeviceMemory') {
                        self::addMemoryColumns($row, $linkRow, $devRow, $name);
                    } elseif ($deviceType === 'DeviceHardDrive') {
                        self::addHardDriveColumns($row, $linkRow, $devRow, $name);
                    } elseif ($deviceType === 'DeviceNetworkCard') {
                        if (!is_array($networkPortRowsForComponents)) {
                            $networkPortRowsForComponents = PluginAlpreportNetworkCollector::collectNetworkPorts($item)['rows'] ?? [];
                        }
                        $networkCardRowIndex = count($grouped[$deviceType]['rows']);
                        self::addNetworkCardColumns(
                            $row,
                            $linkRow,
                            $devRow,
                            $name,
                            $networkPortRowsForComponents[$networkCardRowIndex] ?? []
                        );
                    } elseif ($deviceType === 'DeviceGraphicCard') {
                        self::addGraphicCardColumns($row, $linkRow, $devRow, $name, $resolveForeignKey);
                    }

                    $grouped[$deviceType]['rows'][] = $row;
                }
            } catch (Throwable $e) {
                continue;
            }
        }

        return $grouped;
    }

    private static function deviceRow(DBmysql $DB, string $deviceTable, int $deviceId): ?array
    {
        static $deviceRowCache = [];

        $cacheKey = $deviceTable . '#' . $deviceId;
        if (!isset($deviceRowCache[$cacheKey])) {
            try {
                $devIter = $DB->request([
                    'FROM'  => $deviceTable,
                    'WHERE' => ['id' => $deviceId],
                    'LIMIT' => 1,
                ]);
                $deviceRowCache[$cacheKey] = null;
                foreach ($devIter as $devRow) {
                    $deviceRowCache[$cacheKey] = $devRow;
                }
            } catch (Throwable $e) {
                $deviceRowCache[$cacheKey] = null;
            }
        }

        return is_array($deviceRowCache[$cacheKey]) ? $deviceRowCache[$cacheKey] : null;
    }

    private static function manufacturer(?array $devRow): string
    {
        if (!is_array($devRow) || empty($devRow['manufacturers_id'])) {
            return '';
        }

        $mname = Dropdown::getDropdownName('glpi_manufacturers', (int)$devRow['manufacturers_id']);
        return ($mname && $mname !== '&nbsp;') ? trim(strip_tags($mname)) : '';
    }

    private static function resolveComponentSerial(string $deviceType, array $linkRow, ?array $devRow): string
    {
        $serial = trim((string)($linkRow['serial'] ?? ''));
        if ($serial !== '') {
            return $serial;
        }

        return trim((string)($devRow['serial'] ?? ''));
    }

    private static function resolveGraphicCardComment(array $linkRow, ?array $devRow): string
    {
        foreach ([$linkRow, is_array($devRow) ? $devRow : []] as $row) {
            foreach (['comment', 'comments'] as $commentField) {
                $comment = trim(strip_tags((string)($row[$commentField] ?? '')));
                if ($comment !== '') {
                    return $comment;
                }
            }
        }

        return '';
    }

    private static function addProcessorColumns(array &$row, array $linkRow, ?array $devRow, string $name): void
    {
        $defaultCores = is_array($devRow) ? (string)($devRow['nbcores_default'] ?? '') : '';
        $defaultThreads = is_array($devRow) ? (string)($devRow['nbthreads_default'] ?? '') : '';
        $row['cores'] = (string)($linkRow['nbcores'] ?? $defaultCores);
        $row['threads'] = (string)($linkRow['nbthreads'] ?? $defaultThreads);
        $row['model'] = self::dropdownModel($devRow, 'deviceprocessormodels_id', 'glpi_deviceprocessormodels', $name);
    }

    private static function addMemoryColumns(array &$row, array $linkRow, ?array $devRow, string $name): void
    {
        $row['model'] = self::dropdownModel($devRow, 'devicememorymodels_id', 'glpi_devicememorymodels', $name);
        $row['size'] = (string)($linkRow['size'] ?? $linkRow['capacity'] ?? '');
        $row['type'] = '';

        $memoryTypeId = 0;
        if (!empty($linkRow['devicememorytypes_id']) && is_numeric($linkRow['devicememorytypes_id'])) {
            $memoryTypeId = (int)$linkRow['devicememorytypes_id'];
        } elseif (is_array($devRow) && !empty($devRow['devicememorytypes_id']) && is_numeric($devRow['devicememorytypes_id'])) {
            $memoryTypeId = (int)$devRow['devicememorytypes_id'];
        }
        if ($memoryTypeId > 0) {
            $typeName = Dropdown::getDropdownName('glpi_devicememorytypes', $memoryTypeId);
            if ($typeName && $typeName !== '&nbsp;') {
                $row['type'] = trim(strip_tags($typeName));
            }
        }
    }

    private static function addHardDriveColumns(array &$row, array $linkRow, ?array $devRow, string $name): void
    {
        $row['model'] = self::dropdownModel($devRow, 'deviceharddrivemodels_id', 'glpi_deviceharddrivemodels', $name);
        $row['size'] = (string)($linkRow['capacity'] ?? '');
        $row['type'] = '';

        if (!empty($linkRow['interface'])) {
            $interface = (string)$linkRow['interface'];
            if (stripos($interface, 'nvme') !== false) {
                $row['type'] = 'NVMe';
            } elseif (stripos($interface, 'ssd') !== false || stripos($name, 'ssd') !== false) {
                $row['type'] = 'SSD';
            } elseif (stripos($name, 'hdd') !== false) {
                $row['type'] = 'HDD';
            } else {
                $row['type'] = 'HDD';
            }
        } elseif (stripos($name, 'ssd') !== false || stripos($name, 'solid') !== false) {
            $row['type'] = 'SSD';
        } elseif (stripos($name, 'nvme') !== false) {
            $row['type'] = 'NVMe';
        } else {
            $row['type'] = 'HDD';
        }
    }

    private static function addNetworkCardColumns(array &$row, array $linkRow, ?array $devRow, string $name, array $portRow): void
    {
        $row['model'] = $name;
        $row['mac'] = (string)($linkRow['mac'] ?? '');
        $row['flow'] = '';

        if ($row['mac'] === '') {
            $row['mac'] = (string)($portRow['mac'] ?? '');
        }

        if (!empty($linkRow['bandwidth'])) {
            $row['flow'] = (string)$linkRow['bandwidth'];
        } elseif (is_array($devRow) && !empty($devRow['bandwidth'])) {
            $row['flow'] = (string)$devRow['bandwidth'];
        } elseif (!empty($portRow['speed'])) {
            $row['flow'] = (string)$portRow['speed'];
        } elseif (preg_match('/(\d+)\s*(mb|gb|mbit|gbit)/i', $name, $matches)) {
            $row['flow'] = $matches[1] . ' ' . strtoupper($matches[2]);
        }
    }

    private static function addGraphicCardColumns(
        array &$row,
        array $linkRow,
        ?array $devRow,
        string $name,
        callable $resolveForeignKey
    ): void {
        $row['model'] = self::dropdownModel($devRow, 'devicegraphiccardmodels_id', 'glpi_devicegraphiccardmodels', $name);
        $row['memory'] = (string)($linkRow['memory'] ?? $devRow['memory_default'] ?? '');
        $row['interface'] = trim((string)($linkRow['interface'] ?? $devRow['interface'] ?? ''));

        if ($row['interface'] === '' && !empty($devRow['interfacetypes_id']) && is_numeric($devRow['interfacetypes_id'])) {
            $resolvedInterface = $resolveForeignKey('interfacetypes_id', (int)$devRow['interfacetypes_id']);
            if ($resolvedInterface !== null) {
                $row['interface'] = $resolvedInterface;
            }
        }
        if ($row['interface'] === '') {
            if (preg_match('/\b(pcie|pci[-\s]?express|pci|agp|mxm)\b/i', $name, $matches)) {
                $row['interface'] = strtoupper(str_replace(' ', '', (string)$matches[1]));
            } elseif (!empty($linkRow['busID'])) {
                $row['interface'] = (string)$linkRow['busID'];
            }
        }
    }

    private static function dropdownModel(?array $devRow, string $field, string $table, string $fallback): string
    {
        if (is_array($devRow) && !empty($devRow[$field]) && is_numeric($devRow[$field])) {
            $modelName = Dropdown::getDropdownName($table, (int)$devRow[$field]);
            if ($modelName && $modelName !== '&nbsp;') {
                return trim(strip_tags($modelName));
            }
        }

        return $fallback;
    }

    private static function resolveComponentFrequency(array $linkRow, ?array $devRow = null): string
    {
        $frequency = trim((string)($linkRow['frequency'] ?? ''));
        if ($frequency !== '') {
            return $frequency;
        }

        if (!is_array($devRow)) {
            return '';
        }

        foreach (['frequency_default', 'frequence', 'frequence_default', 'frequency'] as $frequencyField) {
            $value = trim((string)($devRow[$frequencyField] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
