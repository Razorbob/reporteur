<?php

class PluginAlpreportXmlRowTableRenderer
{
    /**
     * Expand row-based placeholders into one row per item.
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

                // Card columns may be written as components_networkcard_* inside a combined card/ports row.
                if (stripos($rowXml, '{{networkcard_ports_') !== false) {
                    $rowXml = preg_replace(
                        '/\{\{components_networkcard_(manufacturer|model|flow|mac)\}\}/i',
                        '{{networkcard_ports_$1}}',
                        $rowXml
                    );
                }

                if (!preg_match_all('/\{\{(?:(components_[a-z0-9]+|monitors|network_ports|networkcard_ports)_([a-z0-9_]+)|(printer_ports)(?:_([a-z0-9_]+))?)\}\}/i', $rowXml, $phMatches, PREG_SET_ORDER)) {
                    return $rowXml;
                }

                $prefix = strtolower((string)($phMatches[0][1] ?: $phMatches[0][3]));
                foreach ($phMatches as $ph) {
                    $matchPrefix = strtolower((string)($ph[1] ?: $ph[3]));
                    if ($matchPrefix !== $prefix) {
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
                        '/\{\{' . preg_quote($prefix, '/') . '(?:_([a-z0-9_]+))?\}\}/i',
                        static function (array $pm) use ($itemRow, $prefix): string {
                            $column = strtolower((string)($pm[1] ?? ''));
                            if ($column === '' && $prefix === 'printer_ports') {
                                $column = 'ports';
                            }
                            $value = (string)($itemRow[$column] ?? '');
                            $value = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';
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
