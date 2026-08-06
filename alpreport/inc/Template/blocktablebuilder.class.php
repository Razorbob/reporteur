<?php

class PluginAlpreportBlockTableBuilder
{
    /**
     * Build full-table placeholder data for block-table templating.
     *
     * @return array<string,array{headers:string[],rows:array<int,string[]>}>
     */
    public static function build(PluginAlpreportTemplateData $data): array
    {
        $blocks = [];

        if (!empty($data->software)) {
            $swRows = [];
            foreach ($data->software as $sw) {
                $swRows[] = [
                    (string)($sw['name'] ?? ''),
                    (string)($sw['version'] ?? ''),
                    (string)($sw['serial'] ?? ''),
                ];
            }
            $blocks['{{software}}'] = [
                'headers' => ['Software', 'Version', 'License Serial'],
                'rows'    => $swRows,
            ];
        }

        if (!empty($data->networkPorts['rows'] ?? [])) {
            $portRows = [];
            foreach ($data->networkPorts['rows'] as $row) {
                $portRows[] = [
                    (string)($row['logical'] ?? ''),
                    (string)($row['name'] ?? ''),
                    (string)($row['type'] ?? ''),
                    (string)($row['mac'] ?? ''),
                    (string)($row['ip'] ?? ''),
                    (string)($row['vlan'] ?? ''),
                ];
            }
            $blocks['{{network_ports}}'] = [
                'headers' => ['#', 'Name', 'Type', 'MAC', 'IP', 'VLAN'],
                'rows'    => $portRows,
            ];
        }

        $item = $data->asset;
        if (class_exists('Rack') && $item instanceof Rack && !empty($data->rackData['item_rows'] ?? [])) {
            $rackRows = [];
            foreach ($data->rackData['item_rows'] as $row) {
                $rackRows[] = [
                    'U' . (string)($row['position'] ?? ''),
                    (string)($row['orientation'] ?? ''),
                    (string)($row['type'] ?? ''),
                    (string)($row['name'] ?? ''),
                    (string)($row['location'] ?? ''),
                ];
            }
            $blocks['{{rack_items}}'] = [
                'headers' => ['Position', 'Orientation', 'Type', 'Name', 'Location'],
                'rows'    => $rackRows,
            ];
        }

        return $blocks;
    }
}
