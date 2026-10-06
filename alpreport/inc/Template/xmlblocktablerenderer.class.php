<?php

class PluginAlpreportXmlBlockTableRenderer
{
    /**
     * Replace full-table placeholders like {{software}} with generated OOXML tables.
     *
     * @param array<string,array{headers:string[],rows:array<int,string[]>}> $blockMap
     * @param array<string,string> $placeholderMap
     */
    public static function render(string $xml, array $blockMap, array &$placeholderMap): string
    {
        if (empty($blockMap)) {
            return $xml;
        }

        foreach ($blockMap as $blockKey => $blockData) {
            $needle = preg_quote($blockKey, '/');
            $pattern = '/<w:p\b[^>]*>(?:(?!<\/w:p>).)*?' . $needle . '(?:(?!<\/w:p>).)*?<\/w:p>/su';

            $xml = preg_replace_callback(
                $pattern,
                static function (array $m) use ($blockKey, $blockData): string {
                    $paragraphXml = $m[0];

                    $rPrXml = '';
                    $runPattern = '/<w:r\b[^>]*>(?:(?!<\/w:r>).)*?'
                        . preg_quote($blockKey, '/')
                        . '(?:(?!<\/w:r>).)*?<\/w:r>/su';
                    if (preg_match($runPattern, $paragraphXml, $rm)) {
                        if (preg_match('/<w:rPr\b[^>]*>.*?<\/w:rPr>|<w:rPr\b[^>]*\/>/s', $rm[0], $pm)) {
                            $rPrXml = $pm[0];
                        }
                    }

                    return self::buildTableXml(
                        $blockData['headers'] ?? [],
                        $blockData['rows'] ?? [],
                        $rPrXml
                    );
                },
                $xml,
                1
            );

            if (!array_key_exists($blockKey, $placeholderMap)) {
                $placeholderMap[$blockKey] = '';
            }
        }

        return $xml;
    }

    /**
     * @param string[]   $headers
     * @param string[][] $rows
     */
    public static function buildTableXml(array $headers, array $rows, string $rPrXml = ''): string
    {
        $colCount = max(1, count($headers));

        $tblPr = '<w:tblPr>'
            . '<w:tblW w:w="9216" w:type="dxa"/>'
            . '<w:tblLayout w:type="fixed"/>'
            . '<w:tblCellMar>'
            . '<w:top w:w="0" w:type="dxa"/>'
            . '<w:left w:w="71" w:type="dxa"/>'
            . '<w:bottom w:w="0" w:type="dxa"/>'
            . '<w:right w:w="71" w:type="dxa"/>'
            . '</w:tblCellMar>'
            . '</w:tblPr>';

        $tblGrid = '<w:tblGrid>' . str_repeat('<w:gridCol w:w="' . (int)floor(9216 / $colCount) . '"/>', $colCount) . '</w:tblGrid>';

        $baseInner = '';
        if ($rPrXml !== '' && preg_match('/<w:rPr\b[^>]*>(.*?)<\/w:rPr>/s', $rPrXml, $m)) {
            $baseInner = $m[1];
        } elseif (preg_match('/<w:rPr\b[^>]*\/>/', $rPrXml) === 1) {
            $baseInner = '';
        }
        $bodyRPr = $baseInner !== '' ? '<w:rPr>' . $baseInner . '</w:rPr>' : '';

        $headerInner = $baseInner;
        if (strpos($headerInner, '<w:b/>') === false && strpos($headerInner, '<w:b ') === false) {
            $headerInner .= '<w:b/>';
        }
        $headerRPr = '<w:rPr>' . $headerInner . '</w:rPr>';

        $renderCell = static function (string $text, string $cellRPr, bool $isHeader): string {
            $escaped = htmlspecialchars(
                preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '',
                ENT_XML1 | ENT_COMPAT,
                'UTF-8'
            );
            if (strpos($escaped, "\n") !== false) {
                $escaped = str_replace(
                    ["\r\n", "\n"],
                    ['</w:t><w:br/><w:t xml:space="preserve">', '</w:t><w:br/><w:t xml:space="preserve">'],
                    $escaped
                );
            }

            $borders = '<w:tcBorders>'
                . '<w:top w:val="single" w:sz="12" w:space="0" w:color="000000"/>'
                . '<w:left w:val="single" w:sz="12" w:space="0" w:color="000000"/>'
                . '<w:bottom w:val="single" w:sz="12" w:space="0" w:color="000000"/>'
                . '<w:right w:val="single" w:sz="12" w:space="0" w:color="000000"/>'
                . '</w:tcBorders>';

            $shading = $isHeader
                ? '<w:shd w:val="clear" w:color="auto" w:fill="E6E6E6"/>'
                : '';

            $tcPr = '<w:tcPr>' . $borders . $shading . '</w:tcPr>';

            return '<w:tc>' . $tcPr
                . '<w:p><w:r>' . $cellRPr . '<w:t xml:space="preserve">' . $escaped . '</w:t></w:r></w:p>'
                . '</w:tc>';
        };

        $headerRow = '<w:tr><w:trPr><w:tblHeader/></w:trPr>';
        foreach ($headers as $h) {
            $headerRow .= $renderCell((string)$h, $headerRPr, true);
        }
        $headerRow .= '</w:tr>';

        $bodyRows = '';
        foreach ($rows as $row) {
            $bodyRows .= '<w:tr>';
            for ($i = 0; $i < $colCount; $i++) {
                $bodyRows .= $renderCell((string)($row[$i] ?? ''), $bodyRPr, false);
            }
            $bodyRows .= '</w:tr>';
        }

        return '<w:tbl>' . $tblPr . $tblGrid . $headerRow . $bodyRows . '</w:tbl>';
    }
}
