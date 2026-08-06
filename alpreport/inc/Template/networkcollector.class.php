<?php

class PluginAlpreportNetworkCollector
{
    /**
     * @return array{ip:string,mac:string}
     */
    public static function collectPrimaryNetwork(CommonDBTM $item): array
    {
        global $DB;

        $result = ['ip' => '', 'mac' => ''];

        if (!isset($DB) || !($DB instanceof DBmysql)) {
            return $result;
        }

        $itemtype = $item::getType();
        $id = (int)$item->getID();

        try {
            $portIter = $DB->request([
                'SELECT' => ['mac', 'id'],
                'FROM'   => 'glpi_networkports',
                'WHERE'  => [
                    'itemtype' => $itemtype,
                    'items_id' => $id,
                ],
                'ORDER' => 'id ASC',
                'LIMIT' => 1,
            ]);

            foreach ($portIter as $portRow) {
                $result['mac'] = (string)($portRow['mac'] ?? '');

                $ipIter = $DB->request([
                    'SELECT' => ['glpi_ipaddresses.name AS ip'],
                    'FROM'   => 'glpi_ipaddresses',
                    'INNER JOIN' => [
                        'glpi_networknames' => [
                            'ON' => [
                                'glpi_ipaddresses' => 'items_id',
                                'glpi_networknames' => 'id',
                                ['AND' => ['glpi_ipaddresses.itemtype' => 'NetworkName']],
                            ],
                        ],
                    ],
                    'WHERE' => [
                        'glpi_networknames.itemtype' => 'NetworkPort',
                        'glpi_networknames.items_id' => (int)$portRow['id'],
                    ],
                    'LIMIT' => 1,
                ]);

                foreach ($ipIter as $ipRow) {
                    $result['ip'] = (string)($ipRow['ip'] ?? '');
                }
            }
        } catch (Throwable $e) {
            // Ignore and return whatever we have.
        }

        return $result;
    }

    /**
     * @return array{lines:string[],rows:array<int,array<string,string>>}
     */
    public static function collectNetworkPorts(CommonDBTM $item): array
    {
        global $DB;

        $result = ['lines' => [], 'rows' => []];

        if (!isset($DB) || !($DB instanceof DBmysql) || !$DB->tableExists('glpi_networkports')) {
            return $result;
        }

        $itemtype = $item::getType();
        $id = (int)$item->getID();

        try {
            $portIter = $DB->request([
                'FROM'  => 'glpi_networkports',
                'WHERE' => [
                    'itemtype'   => $itemtype,
                    'items_id'   => $id,
                    'is_deleted' => 0,
                ],
                'ORDER' => ['logical_number ASC', 'id ASC'],
            ]);

            foreach ($portIter as $port) {
                $portId   = (int)($port['id'] ?? 0);
                $name     = trim((string)($port['name'] ?? ''));
                $mac      = trim((string)($port['mac'] ?? ''));
                $logical  = $port['logical_number'] ?? '';
                $instType = (string)($port['instantiation_type'] ?? '');

                $shortType = $instType !== '' ? preg_replace('/^NetworkPort/', '', $instType) : '';

                $ip = '';
                if ($DB->tableExists('glpi_networknames') && $DB->tableExists('glpi_ipaddresses')) {
                    try {
                        $ipIter = $DB->request([
                            'SELECT' => ['glpi_ipaddresses.name AS ip'],
                            'FROM'   => 'glpi_ipaddresses',
                            'INNER JOIN' => [
                                'glpi_networknames' => [
                                    'ON' => [
                                        'glpi_ipaddresses'  => 'items_id',
                                        'glpi_networknames' => 'id',
                                        ['AND' => ['glpi_ipaddresses.itemtype' => 'NetworkName']],
                                    ],
                                ],
                            ],
                            'WHERE' => [
                                'glpi_networknames.itemtype' => 'NetworkPort',
                                'glpi_networknames.items_id' => $portId,
                            ],
                        ]);
                        $ips = [];
                        foreach ($ipIter as $ipRow) {
                            $ipVal = trim((string)($ipRow['ip'] ?? ''));
                            if ($ipVal !== '') {
                                $ips[] = $ipVal;
                            }
                        }
                        $ip = implode('/', $ips);
                    } catch (Throwable $e) {
                        // ignore
                    }
                }

                $vlan = '';
                if ($DB->tableExists('glpi_networkports_vlans') && $DB->tableExists('glpi_vlans')) {
                    try {
                        $vlanIter = $DB->request([
                            'SELECT' => ['glpi_vlans.name AS vname', 'glpi_vlans.tag AS vtag'],
                            'FROM'   => 'glpi_networkports_vlans',
                            'INNER JOIN' => [
                                'glpi_vlans' => [
                                    'ON' => [
                                        'glpi_networkports_vlans' => 'vlans_id',
                                        'glpi_vlans'              => 'id',
                                    ],
                                ],
                            ],
                            'WHERE' => ['glpi_networkports_vlans.networkports_id' => $portId],
                        ]);
                        $vlans = [];
                        foreach ($vlanIter as $vRow) {
                            $vname = trim((string)($vRow['vname'] ?? ''));
                            $vtag  = trim((string)($vRow['vtag'] ?? ''));
                            $vlans[] = $vname !== '' ? ($vtag !== '' ? $vname . '(' . $vtag . ')' : $vname) : $vtag;
                        }
                        $vlan = implode(',', array_filter($vlans));
                    } catch (Throwable $e) {
                        // ignore
                    }
                }

                $speed = '';
                if ($DB->tableExists('glpi_networkportethernets')) {
                    try {
                        $speedIter = $DB->request([
                            'SELECT' => ['speed'],
                            'FROM'   => 'glpi_networkportethernets',
                            'WHERE'  => ['networkports_id' => $portId],
                            'LIMIT'  => 1,
                        ]);
                        foreach ($speedIter as $speedRow) {
                            $speed = trim((string)($speedRow['speed'] ?? ''));
                        }
                    } catch (Throwable $e) {
                        // ignore
                    }
                }

                $parts = [];
                if ($logical !== '' && $logical !== null) {
                    $parts[] = '#' . $logical;
                }
                if ($name !== '') {
                    $parts[] = $name;
                }
                if ($shortType !== '') {
                    $parts[] = '[' . $shortType . ']';
                }
                if ($mac !== '') {
                    $parts[] = 'MAC=' . $mac;
                }
                if ($ip !== '') {
                    $parts[] = 'IP=' . $ip;
                }
                if ($vlan !== '') {
                    $parts[] = 'VLAN=' . $vlan;
                }

                if (!empty($parts)) {
                    $result['lines'][] = implode(' ', $parts);
                }
                $result['rows'][] = [
                    'logical' => (string)$logical,
                    'name'    => $name,
                    'type'    => $shortType,
                    'mac'     => $mac,
                    'ip'      => $ip,
                    'vlan'    => $vlan,
                    'speed'   => $speed,
                ];
            }
        } catch (Throwable $e) {
            // ignore
        }

        return $result;
    }
}
