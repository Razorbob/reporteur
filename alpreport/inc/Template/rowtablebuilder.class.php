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

        $printerPorts = self::printerPortRows($data->asset);
        if (!empty($printerPorts)) {
            $result['printer_ports'] = $printerPorts;
        }

        return $result;
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
