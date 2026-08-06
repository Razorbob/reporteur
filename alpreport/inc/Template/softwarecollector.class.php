<?php

class PluginAlpreportSoftwareCollector
{
    /**
     * Software installed on the asset via glpi_items_softwareversions.
     *
     * @return array<int,array{name:string,version:string,serial:string}>
     */
    public static function collectSoftware(CommonDBTM $item): array
    {
        global $DB;

        $list = [];

        if (!isset($DB) || !($DB instanceof DBmysql)) {
            return $list;
        }

        if (!$DB->tableExists('glpi_items_softwareversions') || !$DB->tableExists('glpi_softwareversions') || !$DB->tableExists('glpi_softwares')) {
            return $list;
        }

        try {
            $serialBySoftware = self::licenseSerialsBySoftware($item);

            $iter = $DB->request([
                'SELECT' => [
                    'glpi_softwares.id AS sid',
                    'glpi_softwares.name AS sname',
                    'glpi_softwareversions.name AS sversion',
                ],
                'FROM'   => 'glpi_items_softwareversions',
                'INNER JOIN' => [
                    'glpi_softwareversions' => [
                        'ON' => [
                            'glpi_items_softwareversions' => 'softwareversions_id',
                            'glpi_softwareversions'       => 'id',
                        ],
                    ],
                    'glpi_softwares' => [
                        'ON' => [
                            'glpi_softwareversions' => 'softwares_id',
                            'glpi_softwares'        => 'id',
                        ],
                    ],
                ],
                'WHERE' => [
                    'glpi_items_softwareversions.itemtype' => $item::getType(),
                    'glpi_items_softwareversions.items_id' => (int)$item->getID(),
                    'glpi_items_softwareversions.is_deleted' => 0,
                ],
                'ORDER' => 'glpi_softwares.name ASC',
            ]);

            foreach ($iter as $row) {
                $sid = (int)$row['sid'];
                $serial = isset($serialBySoftware[$sid])
                    ? implode('/', array_unique($serialBySoftware[$sid]))
                    : '';
                $list[] = [
                    'name'    => trim((string)$row['sname']),
                    'version' => trim((string)$row['sversion']),
                    'serial'  => $serial,
                ];
            }
        } catch (Throwable $e) {
            // ignore
        }

        return $list;
    }

    /**
     * @return array<int,string[]>
     */
    private static function licenseSerialsBySoftware(CommonDBTM $item): array
    {
        global $DB;

        $serialBySoftware = [];

        if (!isset($DB) || !($DB instanceof DBmysql)) {
            return $serialBySoftware;
        }
        if (!$DB->tableExists('glpi_items_softwarelicenses') || !$DB->tableExists('glpi_softwarelicenses')) {
            return $serialBySoftware;
        }

        try {
            $licIter = $DB->request([
                'SELECT' => [
                    'glpi_softwarelicenses.softwares_id AS sid',
                    'glpi_softwarelicenses.serial AS serial',
                    'glpi_softwarelicenses.otherserial AS otherserial',
                    'glpi_softwarelicenses.name AS lname',
                ],
                'FROM'       => 'glpi_items_softwarelicenses',
                'INNER JOIN' => [
                    'glpi_softwarelicenses' => [
                        'ON' => [
                            'glpi_items_softwarelicenses' => 'softwarelicenses_id',
                            'glpi_softwarelicenses'       => 'id',
                        ],
                    ],
                ],
                'WHERE' => [
                    'glpi_items_softwarelicenses.itemtype' => $item::getType(),
                    'glpi_items_softwarelicenses.items_id' => (int)$item->getID(),
                    'glpi_items_softwarelicenses.is_deleted' => 0,
                ],
            ]);
            foreach ($licIter as $licRow) {
                $sid = (int)$licRow['sid'];
                $serial = trim((string)($licRow['serial'] ?? ''));
                if ($serial === '') {
                    $serial = trim((string)($licRow['otherserial'] ?? ''));
                }
                if ($serial !== '') {
                    $serialBySoftware[$sid][] = $serial;
                }
            }
        } catch (Throwable $e) {
            // ignore
        }

        return $serialBySoftware;
    }
}
