<?php

class PluginAlpreportRowTableBuilder
{
    /**
     * Build row data for generic row-table placeholders (`components_*`, `monitors_*`).
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

        return $result;
    }
}
