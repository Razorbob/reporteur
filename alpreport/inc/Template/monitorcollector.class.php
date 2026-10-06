<?php

class PluginAlpreportMonitorCollector
{
    /**
     * @param callable(string,int):(?string) $resolveForeignKey
     * @return array<int,array<string,string>>
     */
    public static function collectMonitors(CommonDBTM $item, callable $resolveForeignKey): array
    {
        global $DB;

        $monitors = [];

        if (!isset($DB) || !($DB instanceof DBmysql)) {
            return $monitors;
        }

        if ($item::getType() !== 'Computer') {
            return $monitors;
        }

        if (!$DB->tableExists('glpi_assets_assets_peripheralassets') || !$DB->tableExists('glpi_monitors')) {
            return $monitors;
        }

        try {
            $iter = $DB->request([
                'SELECT' => [
                    'glpi_monitors.id',
                    'glpi_monitors.name',
                    'glpi_monitors.serial',
                    'glpi_monitors.otherserial',
                    'glpi_monitors.size',
                    'glpi_monitors.manufacturers_id',
                    'glpi_monitors.monitormodels_id',
                    'glpi_monitors.monitortypes_id',
                    'glpi_monitors.comment',
                ],
                'FROM'   => 'glpi_assets_assets_peripheralassets',
                'INNER JOIN' => [
                    'glpi_monitors' => [
                        'ON' => [
                            'glpi_assets_assets_peripheralassets' => 'items_id_peripheral',
                            'glpi_monitors'                       => 'id',
                        ],
                    ],
                ],
                'WHERE' => [
                    'glpi_assets_assets_peripheralassets.itemtype_asset'      => 'Computer',
                    'glpi_assets_assets_peripheralassets.items_id_asset'      => (int)$item->getID(),
                    'glpi_assets_assets_peripheralassets.itemtype_peripheral' => 'Monitor',
                    'glpi_assets_assets_peripheralassets.is_deleted'          => 0,
                    'glpi_monitors.is_deleted'                                => 0,
                    'glpi_monitors.is_template'                               => 0,
                ],
                'ORDER' => 'glpi_monitors.name ASC',
            ]);

            foreach ($iter as $row) {
                $manufacturer = '';
                $manufacturerId = (int)($row['manufacturers_id'] ?? 0);
                if ($manufacturerId > 0) {
                    $manufacturer = $resolveForeignKey('manufacturers_id', $manufacturerId) ?? '';
                }

                $model = '';
                $modelId = (int)($row['monitormodels_id'] ?? 0);
                if ($modelId > 0) {
                    $model = $resolveForeignKey('monitormodels_id', $modelId) ?? '';
                }

                $type = '';
                $typeId = (int)($row['monitortypes_id'] ?? 0);
                if ($typeId > 0) {
                    $type = $resolveForeignKey('monitortypes_id', $typeId) ?? '';
                }

                $size = trim((string)($row['size'] ?? ''));
                if ($size !== '') {
                    $size .= '"';
                }

                $serial = trim((string)($row['serial'] ?? ''));
                $monitors[] = [
                    'name'          => trim((string)($row['name'] ?? '')),
                    'manufacturer'  => $manufacturer,
                    'model'         => $model,
                    'type'          => $type,
                    'size'          => $size,
                    'serial'        => $serial,
                    'serial_number' => $serial,
                    'otherserial'   => trim((string)($row['otherserial'] ?? '')),
                    'comment'       => trim((string)($row['comment'] ?? '')),
                ];
            }
        } catch (Throwable $e) {
            // ignore
        }

        return $monitors;
    }
}
