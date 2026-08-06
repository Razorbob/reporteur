<?php

require_once __DIR__ . '/Template/bootstrap.php';

class PluginAlpreportTemplateProcessor
{
    public const SUPPORTED_ITEMTYPES = ['Computer', 'NetworkEquipment', 'Rack'];

    /**
     * Device classes we always emit placeholder slots for, so the template never
     * shows literal {{components_xxx}} when the asset has no such component.
     */
    private const KNOWN_DEVICE_TYPES = [
        'DeviceProcessor', 'DeviceMemory', 'DeviceHardDrive', 'DeviceNetworkCard',
        'DeviceGraphicCard', 'DeviceSoundCard', 'DeviceMotherboard', 'DevicePowerSupply',
        'DeviceDrive', 'DeviceControl', 'DeviceCase', 'DevicePci', 'DeviceSimcard',
        'DeviceSensor', 'DeviceBattery', 'DeviceFirmware', 'DeviceGeneric', 'DeviceCamera',
    ];

    public static function getTemplatesDir()
    {
        $base = defined('GLPI_PLUGIN_DOC_DIR')
            ? GLPI_PLUGIN_DOC_DIR
            : (defined('GLPI_VAR_DIR') ? GLPI_VAR_DIR . '/_plugins' : GLPI_ROOT . '/files/_plugins');

        $dir = $base . '/alpreport/templates';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    public static function listTemplates()
    {
        $dir = self::getTemplatesDir();
        $files = glob($dir . '/*.docx') ?: [];
        sort($files);
        return array_map('basename', $files);
    }

    /**
     * Delete a stored template. The name is sanitized (basename only) and must
     * resolve to a regular file inside the templates directory.
     */
    public static function deleteTemplate(string $name): void
    {
        $name = basename($name);
        if ($name === '' || strpos($name, '..') !== false) {
            throw new RuntimeException('Invalid template name.');
        }
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'docx') {
            throw new RuntimeException('Only .docx templates can be deleted.');
        }
        $path = self::getTemplatesDir() . '/' . $name;
        if (!is_file($path)) {
            throw new RuntimeException('Template not found: ' . $name);
        }
        // Ensure the resolved path is still inside the templates directory.
        $real = realpath($path);
        $base = realpath(self::getTemplatesDir());
        if ($real === false || $base === false || strncmp($real, $base, strlen($base)) !== 0) {
            throw new RuntimeException('Refusing to delete file outside templates directory.');
        }
        if (!@unlink($path)) {
            throw new RuntimeException('Could not delete template (permissions?).');
        }
    }

    public static function saveUploadedTemplate(array $file)
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK) {
            $messages = [
                UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize in php.ini.',
                UPLOAD_ERR_FORM_SIZE  => 'File exceeds the form\'s MAX_FILE_SIZE.',
                UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
                UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary upload directory.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write upload to disk.',
                UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the upload.',
            ];
            throw new RuntimeException('Template upload failed: ' . ($messages[$error] ?? 'unknown error'));
        }

        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Uploaded template is not a valid upload.');
        }

        $name = $file['name'] ?? 'template.docx';
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($extension !== 'docx') {
            throw new RuntimeException('Only .docx templates are supported.');
        }

        // We deliberately don't validate the archive contents at upload time:
        // some legitimate Word files (templates, protected/encrypted variants)
        // don't probe cleanly with ZipArchive::open in RDONLY mode and would be
        // incorrectly rejected. render() validates the archive at generation
        // time and reports a precise error if it can't be opened.

        $safeBaseName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($name));
        $targetName = date('Ymd_His') . '_' . $safeBaseName;
        $targetPath = self::getTemplatesDir() . '/' . $targetName;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new RuntimeException('Could not store uploaded template (permissions on ' . dirname($targetPath) . '?).');
        }

        return $targetName;
    }

    /**
     * Build the placeholder => value map for the given asset.
     */
    public static function buildPlaceholderMap(CommonDBTM $item)
    {
        return PluginAlpreportPlainTextPlaceholderBuilder::build(
            self::collectTemplateData($item),
            self::KNOWN_DEVICE_TYPES,
            static function (string $fkField, int $id): ?string {
                return self::resolveForeignKey($fkField, $id);
            }
        );
    }

    /**
     * Build per-component row data for v2 template row duplication.
     *
     * @return array<string,array<int,array<string,string>>>
     */
    public static function buildComponentRowData(CommonDBTM $item): array
    {
        return PluginAlpreportRowTableBuilder::build(self::collectTemplateData($item));
    }

    /**
     * Build the per-placeholder Word table data map. These placeholders, when
     * found in a paragraph, replace the entire enclosing <w:p>...</w:p> with a
     * real Word table. The actual <w:tbl> XML is built at render time so each
     * cell can inherit the run properties (font, size, color, bold, ...) of
     * the original placeholder run.
     *
     * @return array<string,array{headers:string[],rows:array<int,string[]>}>
     */
    public static function buildBlockMap(CommonDBTM $item): array
    {
        return PluginAlpreportBlockTableBuilder::build(self::collectTemplateData($item));
    }

    private static function collectTemplateData(CommonDBTM $item): PluginAlpreportTemplateData
    {
        return PluginAlpreportTemplateDataCollector::collect(
            $item,
            self::KNOWN_DEVICE_TYPES,
            static function (string $fkField, int $id): ?string {
                return self::resolveForeignKey($fkField, $id);
            }
        );
    }

    /**
     * Render a DOCX template with placeholders replaced.
     * 
     * This is the main entry point for template rendering. It orchestrates the
     * complete pipeline: validation, XML extraction, placeholder normalization,
     * table generation, and text replacement.
     * 
     * Pipeline:
     * 1. Validate template file and open as ZIP archive
     * 2. Extract XML parts (document.xml, headers, footers)
     * 3. For each XML part:
     *    - Normalize XML (fix split placeholders)
     *    - Duplicate row-based component tables (v2 placeholders)
     *    - Render tables ({{table_name}} → full Word tables)
     *    - Render general placeholders ({{text}} → values)
     * 4. Save modified ZIP and return temp file path
     *
     * @param string $templatePath Path to the .docx template file
     * @param array<string,string> $placeholderMap Map of {{key}} => value
     * @param array<string,array{headers:string[],rows:array<int,string[]>}> $blockMap Map of {{key}} => table data
     * @param array<string,array<int,array<string,string>>> $componentRowData Per-prefix rows for v2 row duplication
     * @return string Path to generated temp .docx file
     * @throws RuntimeException on validation or rendering errors
     */
    public static function render($templatePath, array $placeholderMap, array $blockMap = [], array $componentRowData = [])
    {
        return PluginAlpreportDocxRenderer::render(
            (string)$templatePath,
            $placeholderMap,
            $blockMap,
            $componentRowData,
            static function (string $xml): string {
                return self::normalizeXml($xml);
            }
        );
    }

    public static function resolveAsset($itemType, $itemId)
    {
        $itemId = (int)$itemId;
        if (!is_string($itemType) || $itemType === '') {
            throw new RuntimeException('Missing asset type.');
        }
        if (!in_array($itemType, self::SUPPORTED_ITEMTYPES, true)) {
            throw new RuntimeException('Unsupported asset type: ' . $itemType);
        }
        if (!class_exists($itemType)) {
            throw new RuntimeException('Asset class not found: ' . $itemType);
        }
        if ($itemId <= 0) {
            throw new RuntimeException('Invalid asset id.');
        }

        $item = new $itemType();
        if (!($item instanceof CommonDBTM)) {
            throw new RuntimeException($itemType . ' is not a valid GLPI item class.');
        }
        if (!$item->getFromDB($itemId)) {
            throw new RuntimeException(sprintf('%s #%d not found.', $itemType, $itemId));
        }
        if (method_exists($item, 'canViewItem') && !$item->canViewItem()) {
            throw new RuntimeException('You do not have permission to view this asset.');
        }

        return $item;
    }

    /**
     * Normalize XML to fix Word's placeholder splitting.
     * 
     * Microsoft Word sometimes splits placeholder text across multiple <w:r> runs,
     * causing "{{asset" and "_name}}" to appear in separate tags. This function
     * detects such cases and strips the inner XML tags to make the placeholder
     * contiguous again. Also normalizes whitespace inside placeholders.
     * 
     * Example transformation:
     * Before: <w:r><w:t>{{asset</w:t></w:r><w:r><w:t>_name}}</w:t></w:r>
     * After:  {{asset_name}}
     * 
     * @param string $xml The Word document XML
     * @return string Normalized XML
     */
    private static function normalizeXml($xml)
    {
        // Fix split placeholders
        $xml = preg_replace_callback(
            '/\{\{[^{}]*?(?:<[^>]+>[^{}]*?)+\}\}/u',
            static function ($m) {
                $placeholder = preg_replace('/<[^>]+>/', '', $m[0]);
                return preg_replace('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/u', '{{$1}}', $placeholder);
            },
            $xml
        );

        // Normalize whitespace in all placeholders
        return preg_replace('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/u', '{{$1}}', $xml);
    }

    private static function resolveForeignKey($fkField, $id)
    {
        if ($id <= 0) {
            return null;
        }

        $itemtype = function_exists('getItemtypeForForeignKeyField')
            ? getItemtypeForForeignKeyField($fkField)
            : null;

        if (!$itemtype || !class_exists($itemtype)) {
            return null;
        }

        $table = function_exists('getTableForItemType')
            ? getTableForItemType($itemtype)
            : null;

        if (!$table) {
            return null;
        }

        $name = Dropdown::getDropdownName($table, $id);
        return ($name && $name !== '&nbsp;') ? trim(strip_tags($name)) : null;
    }

}
