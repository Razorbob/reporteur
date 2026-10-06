<?php

class PluginAlpreportRowTableBuilder
{
    /**
     * Build row data for generic row-table placeholders (`components_*`, `monitors_*`, `network_ports_*`).
     *
     * @return array<string,array<int,array<string,string>>>
     */
    public static function build(PluginAlpreportTemplateData $data): array
    {
        $result = [];

        foreach ($data->components as $deviceType => $list) {
            $shortKey = strtolower((string)preg_replace('/^Device/', '', (string)$deviceType));
            $rows = $list['rows'] ?? [];
            if (!empty($rows)) {
                $result['components_' . $shortKey] = $rows;
            }
        }

        if (!empty($data->monitors)) {
            $result['monitors'] = $data->monitors;
        }

        if (!empty($data->networkPorts['rows'] ?? [])) {
            $result['network_ports'] = $data->networkPorts['rows'];
        }

        $cardPorts = self::networkCardPortRows(
            $data->components['DeviceNetworkCard']['rows'] ?? [],
            $data->networkPorts['rows'] ?? []
        );
        if (!empty($cardPorts)) {
            $result['networkcard_ports'] = $cardPorts;
        }

        $printerPorts = self::printerPortRows($data->asset);
        if (!empty($printerPorts)) {
            $result['printer_ports'] = $printerPorts;
        }

        return $result;
    }

    /**
     * One row per port, each carrying its network card's columns. Ports are matched to a card by
     * the explicit GLPI link, then by MAC; leftover ports go to the sole card (or a card-less row).
     *
     * @param array<int,array<string,string>> $cards
     * @param array<int,array<string,string>> $ports
     * @return array<int,array<string,string>>
     */
    private static function networkCardPortRows(array $cards, array $ports): array
    {
        if (empty($cards)) {
            return [];
        }

        $assigned = array_fill(0, count($cards), []);
        $leftover = [];
        foreach ($ports as $port) {
            $target = null;
            foreach ($cards as $i => $card) {
                if (($port['card_link'] ?? '0') !== '0' && ($port['card_link'] ?? '') === ($card['link_id'] ?? null)) {
                    $target = $i;
                    break;
                }
            }
            if ($target === null && ($port['mac'] ?? '') !== '') {
                foreach ($cards as $i => $card) {
                    if (strcasecmp((string)($card['mac'] ?? ''), $port['mac']) === 0) {
                        $target = $i;
                        break;
                    }
                }
            }
            if ($target === null && count($cards) === 1) {
                $target = 0;
            }
            if ($target === null) {
                $leftover[] = $port;
            } else {
                $assigned[$target][] = $port;
            }
        }

        $portKeys = ['logical', 'port_number', 'port_name', 'port_type', 'port_mac', 'port_ip', 'port_vlan', 'port_speed'];
        $makeRow = static function (array $card, array $port) use ($portKeys): array {
            $row = [
                'manufacturer' => (string)($card['manufacturer'] ?? ''),
                'model'        => (string)($card['model'] ?? ''),
                'flow'         => (string)($card['flow'] ?? ''),
                'mac'          => (string)($card['mac'] ?? ''),
            ];
            foreach ($portKeys as $k) {
                $row[$k] = '';
            }
            if ($port) {
                $row['logical'] = (string)($port['logical'] ?? '');
                $row['port_number'] = (string)($port['port_number'] ?? '');
                $row['port_name'] = (string)($port['name'] ?? '');
                $row['port_type'] = (string)($port['type'] ?? '');
                $row['port_mac'] = (string)($port['mac'] ?? '');
                $row['port_ip'] = (string)($port['ip'] ?? '');
                $row['port_vlan'] = (string)($port['vlan'] ?? '');
                $row['port_speed'] = (string)($port['speed'] ?? '');
            }
            return $row;
        };

        $rows = [];
        foreach ($cards as $i => $card) {
            if (empty($assigned[$i])) {
                $rows[] = $makeRow($card, []);
                continue;
            }
            foreach ($assigned[$i] as $port) {
                $rows[] = $makeRow($card, $port);
            }
        }
        foreach ($leftover as $port) {
            $rows[] = $makeRow([], $port);
        }

        return $rows;
    }

    /**
     * @return array<int,array{ports:string,available:string}>
     */
    private static function printerPortRows(CommonDBTM $item): array
    {
        if (!class_exists('Printer') || !($item instanceof Printer)) {
            return [];
        }

        $ports = [
            'Serial'   => 'have_serial',
            'Parallel' => 'have_parallel',
            'USB'      => 'have_usb',
            'Wi-Fi'    => 'have_wifi',
            'Ethernet' => 'have_ethernet',
        ];

        $rows = [];
        foreach ($ports as $label => $field) {
            $rows[] = [
                'ports'     => $label,
                'available' => !empty($item->fields[$field]) ? 'Ja' : 'Nein',
            ];
        }

        return $rows;
    }
}
