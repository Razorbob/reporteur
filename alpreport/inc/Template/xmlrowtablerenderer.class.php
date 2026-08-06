<?php

class PluginAlpreportXmlRowTableRenderer
{
    /**
     * Expand row-based placeholders (components_* and monitors_*) into one row per item.
     *
     * @param array<string,array<int,array<string,string>>> $componentRowData
     */
    public static function render(string $xml, array $componentRowData): string
    {
        if (empty($componentRowData)) {
            return $xml;
        }

        return preg_replace_callback(
            '/<w:tr\b[^>]*>.*?<\/w:tr>/su',
            static function (array $m) use ($componentRowData): string {
                $rowXml = $m[0];

                if (!preg_match_all('/\{\{(components_[a-z0-9]+|monitors)_([a-z0-9_]+)\}\}/i', $rowXml, $phMatches, PREG_SET_ORDER)) {
                    return $rowXml;
                }

                $prefix = strtolower((string)$phMatches[0][1]);
                foreach ($phMatches as $ph) {
                    if (strtolower((string)$ph[1]) !== $prefix) {
                        return $rowXml;
                    }
                }

                $rows = $componentRowData[$prefix] ?? [];
                if (empty($rows)) {
                    return $rowXml;
                }

                $expandedRows = '';
                foreach ($rows as $itemRow) {
                    $expandedRows .= preg_replace_callback(
                        '/\{\{' . preg_quote($prefix, '/') . '_([a-z0-9_]+)\}\}/i',
                        static function (array $pm) use ($itemRow): string {
                            $column = strtolower((string)$pm[1]);
                            $value = (string)($itemRow[$column] ?? '');
                            return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
                        },
                        $rowXml
                    );
                }

                return $expandedRows;
            },
            $xml
        );
    }
}
