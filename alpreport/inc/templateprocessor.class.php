<?php

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
        // incorrectly rejected. renderDocx() validates the archive at generation
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
        $fields = $item->fields;
        $map = [];

        // Pre-seed all known placeholders with empty strings so unresolved ones
        // don't appear as literal {{xxx}} text in the rendered document.
        $defaultKeys = [
            'asset_type', 'asset_id', 'asset_name', 'asset_serial', 'asset_otherserial',
            'asset_comment', 'asset_contact', 'asset_contact_num', 'asset_uuid',
            'asset_date_mod', 'asset_date_creation', 'generated_at',
            'asset_location', 'asset_state', 'asset_manufacturer', 'asset_model',
            'asset_type', 'asset_os', 'asset_os_version', 'asset_os_servicepack',
            'asset_os_kernel', 'asset_os_architecture', 'asset_os_edition', 'asset_os_serial', 'asset_os_license_id',
            'asset_group', 'asset_entity', 'asset_network', 'asset_domain',
            'asset_user_name', 'asset_user_login', 'asset_user_realname',
            'asset_user_email', 'asset_user_phone',
            'asset_ip', 'asset_mac',
            'asset_firmware', 'asset_ram', 'asset_cpu',
            'components',
            'network_ports', 'network_ports_count',
            'asset_rack', 'asset_rack_position', 'asset_rack_orientation', 'asset_rack_location',
            'rack_size', 'rack_used_units', 'rack_room', 'rack_room_position',
            'rack_max_power', 'rack_mesured_power', 'rack_max_weight', 'rack_mesured_weight',
            'rack_items', 'rack_items_count',
        ];
        foreach ($defaultKeys as $key) {
            $map['{{' . $key . '}}'] = '';
        }
        foreach (self::KNOWN_DEVICE_TYPES as $deviceType) {
            $shortKey = strtolower(preg_replace('/^Device/', '', $deviceType));
            $map['{{components_' . $shortKey . '}}'] = '';
            $map['{{components_' . $shortKey . '_count}}'] = '0';
            $map['{{components_' . $shortKey . '_serial}}'] = '';
        }
        // Software inventory placeholders.
        $map['{{software}}']         = '';
        $map['{{software_serial}}']  = '';
        $map['{{software_count}}']   = '0';

        // Generic asset placeholders.
        $map['{{asset_type}}']          = $item::getTypeName(1);
        $map['{{asset_id}}']            = (string)($fields['id'] ?? '');
        $map['{{asset_name}}']          = (string)($fields['name'] ?? '');
        $map['{{asset_serial}}']        = (string)($fields['serial'] ?? '');
        $map['{{asset_otherserial}}']   = (string)($fields['otherserial'] ?? '');
        $map['{{asset_comment}}']       = (string)($fields['comment'] ?? '');
        $map['{{asset_contact}}']       = (string)($fields['contact'] ?? '');
        $map['{{asset_contact_num}}']   = (string)($fields['contact_num'] ?? '');
        $map['{{asset_uuid}}']          = (string)($fields['uuid'] ?? '');
        $map['{{asset_date_mod}}']      = (string)($fields['date_mod'] ?? '');
        $map['{{asset_date_creation}}'] = (string)($fields['date_creation'] ?? '');
        $map['{{generated_at}}']        = date('Y-m-d H:i:s');

        // Raw fields + auto-resolved foreign keys.
        foreach ($fields as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $map['{{field_' . $key . '}}'] = (string)$value;
            }

            if (
                is_string($key)
                && substr($key, -3) === '_id'
                && !empty($value)
                && is_numeric($value)
            ) {
                $resolved = self::resolveForeignKey($key, (int)$value);
                if ($resolved !== null) {
                    $map['{{hardware_' . $key . '}}'] = $resolved;
                    $shortKey = substr($key, 0, -3);
                    $map['{{hardware_' . $shortKey . '}}'] = $resolved;
                }
            }
        }

        // Friendly aliases for common dropdowns.
        $aliases = [
            'location'     => 'locations_id',
            'state'        => 'states_id',
            'manufacturer' => 'manufacturers_id',
            'model'        => 'computermodels_id',
            'type'         => 'computertypes_id',
            'os'           => 'operatingsystems_id',
            'group'        => 'groups_id',
            'entity'       => 'entities_id',
            'network'      => 'networks_id',
            'domain'       => 'domains_id',
        ];
        if ($item instanceof NetworkEquipment) {
            $aliases['model']    = 'networkequipmentmodels_id';
            $aliases['type']     = 'networkequipmenttypes_id';
            $aliases['firmware'] = 'networkequipmentfirmwares_id';
        }
        if (class_exists('Rack') && $item instanceof Rack) {
            $aliases['model'] = 'rackmodels_id';
            $aliases['type']  = 'racktypes_id';
        }

        foreach ($aliases as $alias => $fieldKey) {
            if (!empty($fields[$fieldKey]) && is_numeric($fields[$fieldKey])) {
                $resolved = self::resolveForeignKey($fieldKey, (int)$fields[$fieldKey]);
                if ($resolved !== null) {
                    $map['{{asset_' . $alias . '}}'] = $resolved;
                }
            }
        }

        // User info.
        if (!empty($fields['users_id'])) {
            $map['{{asset_user_name}}'] = getUserName((int)$fields['users_id']);
            $user = new User();
            if ($user->getFromDB((int)$fields['users_id'])) {
                $map['{{asset_user_login}}']    = (string)($user->fields['name'] ?? '');
                $map['{{asset_user_realname}}'] = trim(((string)($user->fields['realname'] ?? '')) . ' ' . ((string)($user->fields['firstname'] ?? '')));
                $email = method_exists($user, 'getDefaultEmail') ? (string)$user->getDefaultEmail() : '';
                $map['{{asset_user_email}}']    = $email;
                $map['{{asset_user_phone}}']    = (string)($user->fields['phone'] ?? '');
            }
        }

        // Network primary IP / MAC.
        $networkInfo = self::collectPrimaryNetwork($item);
        $map['{{asset_ip}}']  = $networkInfo['ip'];
        $map['{{asset_mac}}'] = $networkInfo['mac'];

        // Operating system (stored in pivot table glpi_items_operatingsystems).
        $osInfo = self::collectOperatingSystem($item);
        if ($osInfo['name'] !== '') {
            $map['{{asset_os}}']              = $osInfo['name'];
            $map['{{asset_os_version}}']      = $osInfo['version'];
            $map['{{asset_os_servicepack}}']  = $osInfo['servicepack'];
            $map['{{asset_os_kernel}}']       = $osInfo['kernel'];
            $map['{{asset_os_architecture}}'] = $osInfo['architecture'];
            $map['{{asset_os_edition}}']      = $osInfo['edition'];
            $map['{{asset_os_serial}}']       = $osInfo['serial'];
            $map['{{asset_os_license_id}}']   = $osInfo['license_id'];
        }

        // Groups (stored in pivot table glpi_groups_items, may also be in legacy fields).
        $groupNames = self::collectGroups($item);
        if (!empty($groupNames)) {
            $map['{{asset_group}}'] = implode(', ', $groupNames);
        }

        // Domains (stored in pivot table glpi_domains_items, may also be in legacy fields).
        $domainNames = self::collectDomains($item);
        if (!empty($domainNames)) {
            $map['{{asset_domain}}'] = implode(', ', $domainNames);
        }

        // Components / devices.
        $components = self::collectComponents($item);
        $map['{{components}}'] = self::flattenComponents($components, ' | ');
        foreach ($components as $deviceType => $list) {
            $shortKey = strtolower(preg_replace('/^Device/', '', $deviceType));
            $map['{{components_' . $shortKey . '}}'] = implode(' | ', $list['lines']);
            $map['{{components_' . $shortKey . '_count}}'] = (string)count($list['lines']);
            $map['{{components_' . $shortKey . '_serial}}'] = implode(', ', array_filter($list['serials']));
        }

        // Network ports list (for both Computer and NetworkEquipment).
        $portInfo = self::collectNetworkPorts($item);
        $map['{{network_ports}}']       = $portInfo['lines'] !== [] ? implode("\n", $portInfo['lines']) : '';
        $map['{{network_ports_count}}'] = (string)count($portInfo['lines']);

        // Rack mount info: where this asset is rack-mounted (for Computer / NetworkEquipment).
        $rackMount = self::collectRackMount($item);
        if ($rackMount['rack'] !== '') {
            $map['{{asset_rack}}']             = $rackMount['rack'];
            $map['{{asset_rack_position}}']    = $rackMount['position'];
            $map['{{asset_rack_orientation}}'] = $rackMount['orientation'];
            $map['{{asset_rack_location}}']    = $rackMount['location'];
        }

        // Rack-as-asset specific fields (when generating doc for a Rack itself).
        if (class_exists('Rack') && $item instanceof Rack) {
            $rackData = self::collectRackData($item);
            $map['{{rack_size}}']           = $rackData['size'];
            $map['{{rack_used_units}}']     = $rackData['used_units'];
            $map['{{rack_room}}']           = $rackData['room'];
            $map['{{rack_room_position}}']  = $rackData['room_position'];
            $map['{{rack_max_power}}']      = $rackData['max_power'];
            $map['{{rack_mesured_power}}']  = $rackData['mesured_power'];
            $map['{{rack_max_weight}}']     = $rackData['max_weight'];
            $map['{{rack_mesured_weight}}'] = $rackData['mesured_weight'];
            $map['{{rack_items}}']          = implode("\n", $rackData['items']);
            $map['{{rack_items_count}}']    = (string)count($rackData['items']);
        }

        // Software inventory.
        $software = self::collectSoftware($item);
        if (!empty($software)) {
            $softwareLines = [];
            $softwareSerials = [];
            foreach ($software as $entry) {
                $line = $entry['name'];
                if ($entry['version'] !== '') {
                    $line .= ' ' . $entry['version'];
                }
                if ($entry['serial'] !== '') {
                    $line .= ' [SN: ' . $entry['serial'] . ']';
                    $softwareSerials[] = $entry['name'] . '=' . $entry['serial'];
                }
                $softwareLines[] = $line;
            }
            $map['{{software}}']        = implode(' | ', $softwareLines);
            $map['{{software_serial}}'] = implode(', ', $softwareSerials);
            $map['{{software_count}}']  = (string)count($software);
        }

        return $map;
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
        $blocks = [];

        // Components: rows from each device type.
        $components = self::collectComponents($item);
        $compRows = [];
        foreach ($components as $deviceType => $data) {
            $shortType = preg_replace('/^Device/', '', $deviceType);
            foreach (($data['rows'] ?? []) as $row) {
                $compRows[] = [
                    $shortType,
                    (string)($row['name'] ?? ''),
                    (string)($row['manufacturer'] ?? ''),
                    (string)($row['serial'] ?? ''),
                    (string)($row['otherserial'] ?? ''),
                ];
            }
        }
        if (!empty($compRows)) {
            $blocks['{{components}}'] = [
                'headers' => ['Type', 'Name', 'Manufacturer', 'Serial', 'Inventory #'],
                'rows'    => $compRows,
            ];
        }

        // Software inventory.
        $software = self::collectSoftware($item);
        if (!empty($software)) {
            $swRows = [];
            foreach ($software as $sw) {
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

        // Network ports.
        $portInfo = self::collectNetworkPorts($item);
        if (!empty($portInfo['rows'] ?? [])) {
            $portRows = [];
            foreach ($portInfo['rows'] as $row) {
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

        // Rack items (when asset is a Rack).
        if (class_exists('Rack') && $item instanceof Rack) {
            $rackData = self::collectRackData($item);
            if (!empty($rackData['item_rows'] ?? [])) {
                $rackRows = [];
                foreach ($rackData['item_rows'] as $row) {
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
        }

        return $blocks;
    }

    /**
     * Build a minimal-but-styled Word table as raw OOXML.
     * Borders are inlined so the output renders correctly even when the host
     * document has no TableGrid style defined.
     *
     * The optional $rPrXml argument is the raw <w:rPr>...</w:rPr> XML extracted
     * from the placeholder's run; when supplied, every cell's text run receives
     * those properties so the table inherits the placeholder's font/size/color.
     *
     * @param string[]   $headers
     * @param string[][] $rows
     */
    private static function buildWordTable(array $headers, array $rows, string $rPrXml = ''): string
    {
        $colCount = max(1, count($headers));

        $border = '<w:top w:val="single" w:sz="4" w:space="0" w:color="888888"/>'
            . '<w:left w:val="single" w:sz="4" w:space="0" w:color="888888"/>'
            . '<w:bottom w:val="single" w:sz="4" w:space="0" w:color="888888"/>'
            . '<w:right w:val="single" w:sz="4" w:space="0" w:color="888888"/>'
            . '<w:insideH w:val="single" w:sz="4" w:space="0" w:color="888888"/>'
            . '<w:insideV w:val="single" w:sz="4" w:space="0" w:color="888888"/>';

        $tblPr = '<w:tblPr>'
            . '<w:tblW w:w="5000" w:type="pct"/>'
            . '<w:tblBorders>' . $border . '</w:tblBorders>'
            . '<w:tblLayout w:type="autofit"/>'
            . '</w:tblPr>';

        $tblGrid = '<w:tblGrid>' . str_repeat('<w:gridCol/>', $colCount) . '</w:tblGrid>';

        // Build a per-run rPr that combines the placeholder's properties with
        // an extra <w:b/> for the header row. Properties are merged by
        // appending <w:b/> to the inner content of the existing <w:rPr>.
        $baseInner = '';
        if ($rPrXml !== '' && preg_match('/<w:rPr\b[^>]*>(.*?)<\/w:rPr>/s', $rPrXml, $m)) {
            $baseInner = $m[1];
        } elseif (preg_match('/<w:rPr\b[^>]*\/>/', $rPrXml) === 1) {
            $baseInner = '';
        }
        $bodyRPr   = $baseInner !== '' ? '<w:rPr>' . $baseInner . '</w:rPr>' : '';
        // Header row: inherited props + bold (don't duplicate <w:b/> if already present).
        $headerInner = $baseInner;
        if (strpos($headerInner, '<w:b/>') === false && strpos($headerInner, '<w:b ') === false) {
            $headerInner .= '<w:b/>';
        }
        $headerRPr = '<w:rPr>' . $headerInner . '</w:rPr>';

        $renderCell = static function (string $text, string $cellRPr, bool $shaded): string {
            $escaped = htmlspecialchars($text, ENT_XML1 | ENT_COMPAT, 'UTF-8');
            // Convert embedded newlines to Word line breaks.
            if (strpos($escaped, "\n") !== false) {
                $escaped = str_replace(
                    ["\r\n", "\n"],
                    ['</w:t><w:br/><w:t xml:space="preserve">', '</w:t><w:br/><w:t xml:space="preserve">'],
                    $escaped
                );
            }
            $shading = $shaded
                ? '<w:tcPr><w:shd w:val="clear" w:color="auto" w:fill="E6E6E6"/></w:tcPr>'
                : '<w:tcPr/>';
            return '<w:tc>' . $shading
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

    /**
     * Render a DOCX file using the placeholder map and return a temp file path.
     *
     * @param array<string,string> $placeholderMap key (e.g. "{{asset_name}}") => replacement string
     * @param array<string,array{headers:string[],rows:array<int,string[]>}> $blockMap
     *        key => structured table data; the entire enclosing <w:p> is replaced
     *        with a Word table whose runs inherit the placeholder's <w:rPr>.
     */
    public static function renderDocx($templatePath, array $placeholderMap, array $blockMap = [])
    {
        if (!is_file($templatePath)) {
            throw new RuntimeException('Template not found: ' . $templatePath);
        }
        if (!is_readable($templatePath)) {
            throw new RuntimeException('Template is not readable: ' . $templatePath);
        }
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('PHP ZipArchive extension is required to render DOCX files.');
        }

        $templateSize = @filesize($templatePath);
        if ($templateSize === false || $templateSize <= 0) {
            throw new RuntimeException('Template file is empty or unreadable: ' . $templatePath);
        }

        // Read the original bytes and write them to a unique temp file.
        // tempnam + rename + copy was unreliable on some systems; this is simpler.
        $bytes = @file_get_contents($templatePath);
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('Could not read template bytes from ' . $templatePath);
        }

        $tempDir = sys_get_temp_dir();
        if (!is_dir($tempDir) || !is_writable($tempDir)) {
            throw new RuntimeException('System temp dir is not writable: ' . $tempDir);
        }
        $docxPath = $tempDir . DIRECTORY_SEPARATOR . 'alpreport_' . bin2hex(random_bytes(8)) . '.docx';

        if (@file_put_contents($docxPath, $bytes) !== strlen($bytes)) {
            @unlink($docxPath);
            throw new RuntimeException('Could not write template to temp file: ' . $docxPath);
        }

        $zip = new ZipArchive();
        $openResult = $zip->open($docxPath);
        if ($openResult !== true) {
            $head = bin2hex(substr($bytes, 0, 4));
            @unlink($docxPath);

            // A real .docx is a ZIP archive starting with PK\x03\x04 (504b0304).
            // Anything else is almost always a Word XML document, a Strict Open XML
            // file, or a plain text/HTML file saved with a .docx extension.
            $hint = '';
            if (strncmp($head, '504b', 4) !== 0) {
                $hint = ' The file does not start with the ZIP signature (50 4B 03 04),'
                    . ' so it is not a real .docx package. In Microsoft Word, use'
                    . ' "File > Save As > Word Document (*.docx)" — not "Word XML Document"'
                    . ' or "Strict Open XML" — then re-upload.';
            }

            throw new RuntimeException(
                'Could not open template as DOCX archive (ZipArchive error code ' . $openResult
                . ', file size ' . $templateSize . ' bytes, first 4 bytes 0x' . $head . ').'
                . $hint
            );
        }

        try {
            $targets = ['word/document.xml'];
            for ($i = 1; $i <= 20; $i++) {
                $headerName = 'word/header' . $i . '.xml';
                if ($zip->locateName($headerName) !== false) {
                    $targets[] = $headerName;
                }
                $footerName = 'word/footer' . $i . '.xml';
                if ($zip->locateName($footerName) !== false) {
                    $targets[] = $footerName;
                }
            }

            $touched = 0;
            foreach ($targets as $entry) {
                $xml = $zip->getFromName($entry);
                if ($xml === false) {
                    continue;
                }

                $xml = self::repairSplitPlaceholders($xml);

                // First, replace block placeholders: when a paragraph contains one of
                // these, the entire <w:p>...</w:p> is swapped out for a Word table
                // whose runs inherit the placeholder's <w:rPr> (font, size, color).
                if (!empty($blockMap)) {
                    foreach ($blockMap as $blockKey => $blockData) {
                        $needle = preg_quote($blockKey, '/');
                        $pattern = '/<w:p\b[^>]*>(?:(?!<\/w:p>).)*?' . $needle . '(?:(?!<\/w:p>).)*?<\/w:p>/su';

                        $xml = preg_replace_callback(
                            $pattern,
                            static function ($m) use ($blockKey, $blockData) {
                                $paragraphXml = $m[0];

                                // Locate the <w:r> that contains the placeholder and
                                // extract its <w:rPr> so the table inherits the
                                // placeholder's font / size / color / bold / italic.
                                $rPrXml = '';
                                $runPattern = '/<w:r\b[^>]*>(?:(?!<\/w:r>).)*?'
                                    . preg_quote($blockKey, '/')
                                    . '(?:(?!<\/w:r>).)*?<\/w:r>/su';
                                if (preg_match($runPattern, $paragraphXml, $rm)) {
                                    if (preg_match('/<w:rPr\b[^>]*>.*?<\/w:rPr>|<w:rPr\b[^>]*\/>/s', $rm[0], $pm)) {
                                        $rPrXml = $pm[0];
                                    }
                                }

                                return self::buildWordTable(
                                    $blockData['headers'] ?? [],
                                    $blockData['rows'] ?? [],
                                    $rPrXml
                                );
                            },
                            $xml,
                            1
                        );

                        // Also strip the placeholder key from the scalar map so any
                        // leftover occurrences (outside a paragraph context, unlikely)
                        // don't end up as a literal "{{components}}" string.
                        if (!array_key_exists($blockKey, $placeholderMap)) {
                            $placeholderMap[$blockKey] = '';
                        }
                    }
                }

                $search = [];
                $replace = [];
                foreach ($placeholderMap as $key => $value) {
                    $escaped = htmlspecialchars((string)$value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
                    // Convert embedded newlines to Word line breaks. This assumes the
                    // placeholder sits inside a <w:t> run (the normal case after
                    // repairSplitPlaceholders). For non-text contexts the inserted
                    // markup will appear as literal text — acceptable trade-off.
                    if (strpos($escaped, "\n") !== false) {
                        $escaped = str_replace(
                            ["\r\n", "\n"],
                            ['</w:t><w:br/><w:t xml:space="preserve">', '</w:t><w:br/><w:t xml:space="preserve">'],
                            $escaped
                        );
                    }
                    $search[]  = $key;
                    $replace[] = $escaped;
                }
                $newXml = str_replace($search, $replace, $xml);

                if (!$zip->addFromString($entry, $newXml)) {
                    throw new RuntimeException('Failed to write replaced content into DOCX entry: ' . $entry);
                }
                $touched++;
            }

            if ($touched === 0) {
                throw new RuntimeException('Template did not contain word/document.xml — not a valid DOCX.');
            }
        } catch (Throwable $e) {
            $zip->close();
            @unlink($docxPath);
            throw $e;
        }

        if (!$zip->close()) {
            @unlink($docxPath);
            throw new RuntimeException('Failed to finalize DOCX archive.');
        }

        return $docxPath;
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
     * If Word splits a placeholder across multiple <w:r> runs, this strips
     * the inner XML tags so the placeholder text becomes contiguous again.
     */
    private static function repairSplitPlaceholders($xml)
    {
        return preg_replace_callback(
            '/\{\{[^{}]*?(?:<[^>]+>[^{}]*?)+\}\}/u',
            static function ($m) {
                return preg_replace('/<[^>]+>/', '', $m[0]);
            },
            $xml
        );
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

    private static function collectPrimaryNetwork(CommonDBTM $item)
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
     * Components grouped by device class.
     * @return array<string,array{lines:string[],serials:string[]}>
     */
    private static function collectComponents(CommonDBTM $item)
    {
        global $DB;

        $grouped = [];

        if (!isset($DB) || !($DB instanceof DBmysql)) {
            return $grouped;
        }

        $itemtype = $item::getType();
        $id = (int)$item->getID();

        // Build a normalized device-type list. Note: Item_Devices::getDeviceTypes()
        // returns the LINK classes (Item_Device*), not the device classes themselves.
        // CommonDevice::getDeviceTypes() returns the DEVICE classes (Device*).
        $deviceTypes = self::KNOWN_DEVICE_TYPES;
        if (class_exists('CommonDevice') && method_exists('CommonDevice', 'getDeviceTypes')) {
            $discovered = CommonDevice::getDeviceTypes();
            if (is_array($discovered) && !empty($discovered)) {
                $deviceTypes = array_values(array_unique(array_merge($deviceTypes, $discovered)));
            }
        }

        foreach ($deviceTypes as $deviceType) {
            // Defensive: strip a stray Item_ prefix if a future GLPI version returns link classes.
            if (strpos($deviceType, 'Item_') === 0) {
                $deviceType = substr($deviceType, 5);
            }
            $linkClass = 'Item_' . $deviceType;
            if (!class_exists($linkClass) || !class_exists($deviceType)) {
                continue;
            }

            $linkTable = function_exists('getTableForItemType') ? getTableForItemType($linkClass) : null;
            $deviceTable = function_exists('getTableForItemType') ? getTableForItemType($deviceType) : null;
            $deviceFk = function_exists('getForeignKeyFieldForItemType')
                ? getForeignKeyFieldForItemType($deviceType)
                : strtolower($deviceType) . 's_id';

            if (!$linkTable || !$deviceTable) {
                continue;
            }

            try {
                $iter = $DB->request([
                    'SELECT' => ['*'],
                    'FROM'   => $linkTable,
                    'WHERE'  => [
                        'itemtype' => $itemtype,
                        'items_id' => $id,
                    ],
                ]);

                foreach ($iter as $linkRow) {
                    $deviceId = (int)($linkRow[$deviceFk] ?? 0);
                    if ($deviceId <= 0) {
                        continue;
                    }

                    $name = Dropdown::getDropdownName($deviceTable, $deviceId);
                    $name = ($name && $name !== '&nbsp;') ? trim(strip_tags($name)) : ('#' . $deviceId);

                    $serial = (string)($linkRow['serial'] ?? '');
                    $otherserial = (string)($linkRow['otherserial'] ?? '');

                    // Manufacturer (and a few extras) live on the device table itself.
                    $manufacturer = '';
                    static $deviceRowCache = [];
                    $cacheKey = $deviceTable . '#' . $deviceId;
                    if (!isset($deviceRowCache[$cacheKey])) {
                        try {
                            $devIter = $DB->request([
                                'FROM'  => $deviceTable,
                                'WHERE' => ['id' => $deviceId],
                                'LIMIT' => 1,
                            ]);
                            $deviceRowCache[$cacheKey] = null;
                            foreach ($devIter as $devRow) {
                                $deviceRowCache[$cacheKey] = $devRow;
                            }
                        } catch (Throwable $e) {
                            $deviceRowCache[$cacheKey] = null;
                        }
                    }
                    $devRow = $deviceRowCache[$cacheKey];
                    if (is_array($devRow) && !empty($devRow['manufacturers_id'])) {
                        $mname = Dropdown::getDropdownName('glpi_manufacturers', (int)$devRow['manufacturers_id']);
                        if ($mname && $mname !== '&nbsp;') {
                            $manufacturer = trim(strip_tags($mname));
                        }
                    }

                    $extras = [];
                    foreach (['serial', 'otherserial', 'busID', 'capacity', 'frequency'] as $extraKey) {
                        if (!empty($linkRow[$extraKey])) {
                            $extras[] = $extraKey . '=' . $linkRow[$extraKey];
                        }
                    }

                    $line = $extras
                        ? $name . ' (' . implode(', ', $extras) . ')'
                        : $name;

                    if (!isset($grouped[$deviceType])) {
                        $grouped[$deviceType] = ['lines' => [], 'serials' => [], 'rows' => []];
                    }
                    $grouped[$deviceType]['lines'][] = $line;
                    if ($serial !== '') {
                        $grouped[$deviceType]['serials'][] = $serial;
                    }
                    $grouped[$deviceType]['rows'][] = [
                        'name'         => $name,
                        'manufacturer' => $manufacturer,
                        'serial'       => $serial,
                        'otherserial'  => $otherserial,
                    ];
                }
            } catch (Throwable $e) {
                continue;
            }
        }

        return $grouped;
    }

    private static function flattenComponents(array $grouped, $separator)
    {
        $parts = [];
        foreach ($grouped as $deviceType => $data) {
            $shortType = preg_replace('/^Device/', '', $deviceType);
            $parts[] = $shortType . ': ' . implode(', ', $data['lines']);
        }
        return implode($separator, $parts);
    }

    /**
     * Operating system info via glpi_items_operatingsystems pivot.
     * @return array<string,string>
     */
    private static function collectOperatingSystem(CommonDBTM $item): array
    {
        global $DB;

        $info = [
            'name' => '', 'version' => '', 'servicepack' => '', 'kernel' => '',
            'architecture' => '', 'edition' => '', 'serial' => '', 'license_id' => '',
        ];

        if (!isset($DB) || !($DB instanceof DBmysql)) {
            return $info;
        }
        if (!$DB->tableExists('glpi_items_operatingsystems')) {
            return $info;
        }

        try {
            $iter = $DB->request([
                'FROM'  => 'glpi_items_operatingsystems',
                'WHERE' => [
                    'itemtype' => $item::getType(),
                    'items_id' => (int)$item->getID(),
                ],
                'ORDER' => 'id ASC',
                'LIMIT' => 1,
            ]);

            foreach ($iter as $row) {
                $info['serial']     = (string)($row['license_number'] ?? '');
                $info['license_id'] = (string)($row['licenseid'] ?? '');

                $resolveLookups = [
                    'name'         => ['operatingsystems_id', 'glpi_operatingsystems'],
                    'version'      => ['operatingsystemversions_id', 'glpi_operatingsystemversions'],
                    'servicepack'  => ['operatingsystemservicepacks_id', 'glpi_operatingsystemservicepacks'],
                    'kernel'       => ['operatingsystemkernelversions_id', 'glpi_operatingsystemkernelversions'],
                    'architecture' => ['operatingsystemarchitectures_id', 'glpi_operatingsystemarchitectures'],
                    'edition'      => ['operatingsystemeditions_id', 'glpi_operatingsystemeditions'],
                ];
                foreach ($resolveLookups as $key => [$field, $table]) {
                    if (!empty($row[$field]) && is_numeric($row[$field])) {
                        $name = Dropdown::getDropdownName($table, (int)$row[$field]);
                        if ($name && $name !== '&nbsp;') {
                            $info[$key] = trim(strip_tags($name));
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            // ignore
        }

        return $info;
    }

    /**
     * Group names assigned to the asset (pivot table glpi_groups_items + legacy field).
     * @return string[]
     */
    private static function collectGroups(CommonDBTM $item): array
    {
        global $DB;

        $names = [];

        if (isset($DB) && $DB instanceof DBmysql && $DB->tableExists('glpi_groups_items')) {
            try {
                $iter = $DB->request([
                    'SELECT' => ['groups_id'],
                    'FROM'   => 'glpi_groups_items',
                    'WHERE'  => [
                        'itemtype' => $item::getType(),
                        'items_id' => (int)$item->getID(),
                    ],
                ]);
                foreach ($iter as $row) {
                    $resolved = self::resolveForeignKey('groups_id', (int)$row['groups_id']);
                    if ($resolved !== null) {
                        $names[] = $resolved;
                    }
                }
            } catch (Throwable $e) {
                // ignore
            }
        }

        // Legacy direct fields.
        foreach (['groups_id', 'groups_id_tech'] as $fieldKey) {
            if (!empty($item->fields[$fieldKey]) && is_numeric($item->fields[$fieldKey])) {
                $resolved = self::resolveForeignKey('groups_id', (int)$item->fields[$fieldKey]);
                if ($resolved !== null && !in_array($resolved, $names, true)) {
                    $names[] = $resolved;
                }
            }
        }

        return $names;
    }

    /**
     * Domain names assigned to the asset (pivot table glpi_domains_items + legacy field).
     * @return string[]
     */
    private static function collectDomains(CommonDBTM $item): array
    {
        global $DB;

        $names = [];

        if (isset($DB) && $DB instanceof DBmysql && $DB->tableExists('glpi_domains_items')) {
            try {
                $iter = $DB->request([
                    'SELECT' => ['domains_id'],
                    'FROM'   => 'glpi_domains_items',
                    'WHERE'  => [
                        'itemtype' => $item::getType(),
                        'items_id' => (int)$item->getID(),
                    ],
                ]);
                foreach ($iter as $row) {
                    $resolved = self::resolveForeignKey('domains_id', (int)$row['domains_id']);
                    if ($resolved !== null) {
                        $names[] = $resolved;
                    }
                }
            } catch (Throwable $e) {
                // ignore
            }
        }

        if (!empty($item->fields['domains_id']) && is_numeric($item->fields['domains_id'])) {
            $resolved = self::resolveForeignKey('domains_id', (int)$item->fields['domains_id']);
            if ($resolved !== null && !in_array($resolved, $names, true)) {
                $names[] = $resolved;
            }
        }

        return $names;
    }

    /**
     * Software installed on the asset (via glpi_items_softwareversions pivot).
     * @return array<int,array{name:string,version:string,serial:string}>
     */
    private static function collectSoftware(CommonDBTM $item): array
    {
        global $DB;

        $list = [];

        if (!isset($DB) || !($DB instanceof DBmysql)) {
            return $list;
        }

        // GLPI 10/11: pivot table is glpi_items_softwareversions (item -> softwareversions_id -> softwares_id).
        if (!$DB->tableExists('glpi_items_softwareversions') || !$DB->tableExists('glpi_softwareversions') || !$DB->tableExists('glpi_softwares')) {
            return $list;
        }

        try {
            $iter = $DB->request([
                'SELECT' => [
                    'glpi_softwares.name AS sname',
                    'glpi_softwareversions.name AS sversion',
                    'glpi_items_softwareversions.id AS isv_id',
                ],
                'FROM'   => 'glpi_items_softwareversions',
                'INNER JOIN' => [
                    'glpi_softwareversions' => [
                        'ON' => [
                            'glpi_items_softwareversions' => 'softwareversions_id',
                            'glpi_softwareversions'       => 'id',
                        ],
                    ],
                    'glpi_softwares' => [
                        'ON' => [
                            'glpi_softwareversions' => 'softwares_id',
                            'glpi_softwares'        => 'id',
                        ],
                    ],
                ],
                'WHERE' => [
                    'glpi_items_softwareversions.itemtype' => $item::getType(),
                    'glpi_items_softwareversions.items_id' => (int)$item->getID(),
                    'glpi_items_softwareversions.is_deleted' => 0,
                ],
                'ORDER' => 'glpi_softwares.name ASC',
            ]);

            // Collect all linked license serials in one go.
            $serialBySoftware = [];
            if ($DB->tableExists('glpi_items_softwarelicenses') && $DB->tableExists('glpi_softwarelicenses')) {
                try {
                    $licIter = $DB->request([
                        'SELECT' => [
                            'glpi_softwarelicenses.softwares_id AS sid',
                            'glpi_softwarelicenses.serial AS serial',
                            'glpi_softwarelicenses.otherserial AS otherserial',
                            'glpi_softwarelicenses.name AS lname',
                        ],
                        'FROM'       => 'glpi_items_softwarelicenses',
                        'INNER JOIN' => [
                            'glpi_softwarelicenses' => [
                                'ON' => [
                                    'glpi_items_softwarelicenses' => 'softwarelicenses_id',
                                    'glpi_softwarelicenses'       => 'id',
                                ],
                            ],
                        ],
                        'WHERE' => [
                            'glpi_items_softwarelicenses.itemtype' => $item::getType(),
                            'glpi_items_softwarelicenses.items_id' => (int)$item->getID(),
                            'glpi_items_softwarelicenses.is_deleted' => 0,
                        ],
                    ]);
                    foreach ($licIter as $licRow) {
                        $sid = (int)$licRow['sid'];
                        $serial = trim((string)($licRow['serial'] ?? ''));
                        if ($serial === '') {
                            $serial = trim((string)($licRow['otherserial'] ?? ''));
                        }
                        if ($serial !== '') {
                            $serialBySoftware[$sid][] = $serial;
                        }
                    }
                } catch (Throwable $e) {
                    // ignore
                }
            }

            // Resolve software id -> name mapping again to attach serials.
            $iter2 = $DB->request([
                'SELECT' => [
                    'glpi_softwares.id AS sid',
                    'glpi_softwares.name AS sname',
                    'glpi_softwareversions.name AS sversion',
                ],
                'FROM'   => 'glpi_items_softwareversions',
                'INNER JOIN' => [
                    'glpi_softwareversions' => [
                        'ON' => [
                            'glpi_items_softwareversions' => 'softwareversions_id',
                            'glpi_softwareversions'       => 'id',
                        ],
                    ],
                    'glpi_softwares' => [
                        'ON' => [
                            'glpi_softwareversions' => 'softwares_id',
                            'glpi_softwares'        => 'id',
                        ],
                    ],
                ],
                'WHERE' => [
                    'glpi_items_softwareversions.itemtype' => $item::getType(),
                    'glpi_items_softwareversions.items_id' => (int)$item->getID(),
                    'glpi_items_softwareversions.is_deleted' => 0,
                ],
                'ORDER' => 'glpi_softwares.name ASC',
            ]);

            foreach ($iter2 as $row) {
                $sid = (int)$row['sid'];
                $serial = isset($serialBySoftware[$sid])
                    ? implode('/', array_unique($serialBySoftware[$sid]))
                    : '';
                $list[] = [
                    'name'    => trim((string)$row['sname']),
                    'version' => trim((string)$row['sversion']),
                    'serial'  => $serial,
                ];
            }
        } catch (Throwable $e) {
            // ignore
        }

        return $list;
    }

    /**
     * All network ports of the asset, with MAC, IP, type, VLAN, and remote port if any.
     * @return array{lines:string[],rows:array<int,array<string,string>>}
     */
    private static function collectNetworkPorts(CommonDBTM $item): array
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

                // Resolve IP via NetworkName pivot.
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

                // Resolve VLAN if mapping table exists.
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
                ];
            }
        } catch (Throwable $e) {
            // ignore
        }

        return $result;
    }

    /**
     * Where this asset is mounted in a rack (if at all).
     * @return array{rack:string,position:string,orientation:string,location:string}
     */
    private static function collectRackMount(CommonDBTM $item): array
    {
        global $DB;

        $result = ['rack' => '', 'position' => '', 'orientation' => '', 'location' => ''];

        if (!isset($DB) || !($DB instanceof DBmysql) || !$DB->tableExists('glpi_items_racks')) {
            return $result;
        }

        try {
            $iter = $DB->request([
                'FROM'  => 'glpi_items_racks',
                'WHERE' => [
                    'itemtype' => $item::getType(),
                    'items_id' => (int)$item->getID(),
                ],
                'ORDER' => 'id ASC',
                'LIMIT' => 1,
            ]);
            foreach ($iter as $row) {
                $rackId = (int)($row['racks_id'] ?? 0);
                if ($rackId > 0) {
                    $name = Dropdown::getDropdownName('glpi_racks', $rackId);
                    if ($name && $name !== '&nbsp;') {
                        $result['rack'] = trim(strip_tags($name));
                    }
                    // Resolve rack's own location.
                    if (class_exists('Rack')) {
                        try {
                            $rack = new Rack();
                            if ($rack->getFromDB($rackId) && !empty($rack->fields['locations_id'])) {
                                $locName = Dropdown::getDropdownName('glpi_locations', (int)$rack->fields['locations_id']);
                                if ($locName && $locName !== '&nbsp;') {
                                    $result['location'] = trim(strip_tags($locName));
                                }
                            }
                        } catch (Throwable $e) {
                            // ignore
                        }
                    }
                }
                $result['position']    = (string)($row['position'] ?? '');
                $orient                = (int)($row['orientation'] ?? 0);
                $result['orientation'] = $orient === 1 ? 'rear' : 'front';
            }
        } catch (Throwable $e) {
            // ignore
        }

        return $result;
    }

    /**
     * Rack-as-asset details: size, room, power/weight, and the list of mounted items.
     * @return array{size:string,used_units:string,room:string,room_position:string,max_power:string,mesured_power:string,max_weight:string,mesured_weight:string,items:string[],item_rows:array<int,array<string,string>>}
     */
    private static function collectRackData(CommonDBTM $item): array
    {
        global $DB;

        $fields = $item->fields;

        $result = [
            'size'           => (string)($fields['number_units'] ?? ''),
            'used_units'     => '',
            'room'           => '',
            'room_position'  => (string)($fields['position'] ?? ''),
            'max_power'      => (string)($fields['max_power'] ?? ''),
            'mesured_power'  => (string)($fields['mesured_power'] ?? ''),
            'max_weight'     => (string)($fields['max_weight'] ?? ''),
            'mesured_weight' => (string)($fields['mesured_weight'] ?? ''),
            'items'          => [],
            'item_rows'      => [],
        ];

        if (!empty($fields['dcrooms_id']) && is_numeric($fields['dcrooms_id'])) {
            $name = Dropdown::getDropdownName('glpi_dcrooms', (int)$fields['dcrooms_id']);
            if ($name && $name !== '&nbsp;') {
                $result['room'] = trim(strip_tags($name));
            }
        }

        if (!isset($DB) || !($DB instanceof DBmysql) || !$DB->tableExists('glpi_items_racks')) {
            return $result;
        }

        try {
            $iter = $DB->request([
                'FROM'  => 'glpi_items_racks',
                'WHERE' => ['racks_id' => (int)$item->getID()],
                'ORDER' => 'position DESC',
            ]);
            $usedUnits = 0;
            foreach ($iter as $row) {
                $itype  = (string)($row['itemtype'] ?? '');
                $iid    = (int)($row['items_id'] ?? 0);
                $pos    = (string)($row['position'] ?? '');
                $orient = ((int)($row['orientation'] ?? 0)) === 1 ? 'rear' : 'front';
                $hpos   = (string)($row['hpos'] ?? '');

                $itemName = '';
                $itemLocation = '';
                if ($itype !== '' && class_exists($itype) && $iid > 0) {
                    $obj = new $itype();
                    if ($obj instanceof CommonDBTM && $obj->getFromDB($iid)) {
                        $itemName = (string)($obj->fields['name'] ?? '');
                        if (!empty($obj->fields['locations_id'])) {
                            $locName = Dropdown::getDropdownName('glpi_locations', (int)$obj->fields['locations_id']);
                            if ($locName && $locName !== '&nbsp;') {
                                $itemLocation = trim(strip_tags($locName));
                            }
                        }
                        // Count units occupied if model has 'required_units'.
                        $modelTable = null;
                        $modelId = 0;
                        if ($itype === 'Computer' && !empty($obj->fields['computermodels_id'])) {
                            $modelTable = 'glpi_computermodels';
                            $modelId = (int)$obj->fields['computermodels_id'];
                        } elseif ($itype === 'NetworkEquipment' && !empty($obj->fields['networkequipmentmodels_id'])) {
                            $modelTable = 'glpi_networkequipmentmodels';
                            $modelId = (int)$obj->fields['networkequipmentmodels_id'];
                        }
                        if ($modelTable && $modelId > 0 && $DB->tableExists($modelTable)) {
                            try {
                                $mIter = $DB->request([
                                    'SELECT' => ['required_units'],
                                    'FROM'   => $modelTable,
                                    'WHERE'  => ['id' => $modelId],
                                    'LIMIT'  => 1,
                                ]);
                                foreach ($mIter as $mRow) {
                                    $usedUnits += max(1, (int)($mRow['required_units'] ?? 1));
                                }
                            } catch (Throwable $e) {
                                $usedUnits++;
                            }
                        } else {
                            $usedUnits++;
                        }
                    }
                }

                $typeName = $itype !== '' && class_exists($itype) ? $itype::getTypeName(1) : $itype;
                $line = sprintf('U%s [%s] %s: %s', $pos, $orient . ($hpos !== '' ? '/' . $hpos : ''), $typeName, $itemName);
                if ($itemLocation !== '') {
                    $line .= ' @ ' . $itemLocation;
                }
                $result['items'][] = $line;
                $result['item_rows'][] = [
                    'position'    => $pos,
                    'orientation' => $orient . ($hpos !== '' ? '/' . $hpos : ''),
                    'type'        => $typeName,
                    'name'        => $itemName,
                    'location'    => $itemLocation,
                ];
            }
            $result['used_units'] = (string)$usedUnits;
        } catch (Throwable $e) {
            // ignore
        }

        return $result;
    }
}
