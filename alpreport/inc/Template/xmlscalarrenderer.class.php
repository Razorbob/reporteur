<?php

class PluginAlpreportXmlScalarRenderer
{
    /**
     * Replace scalar placeholders like {{asset_name}}.
     *
     * @param array<string,string> $placeholderMap
     */
    public static function render(string $xml, array $placeholderMap): string
    {
        $search = [];
        $replace = [];

        foreach ($placeholderMap as $key => $value) {
            $escaped = htmlspecialchars((string)$value, ENT_XML1 | ENT_COMPAT, 'UTF-8');

            if (strpos($escaped, "\n") !== false) {
                $escaped = str_replace(
                    ["\r\n", "\n"],
                    ['</w:t><w:br/><w:t xml:space="preserve">', '</w:t><w:br/><w:t xml:space="preserve">'],
                    $escaped
                );
            }

            $search[] = $key;
            $replace[] = $escaped;
        }

        return str_replace($search, $replace, $xml);
    }
}
