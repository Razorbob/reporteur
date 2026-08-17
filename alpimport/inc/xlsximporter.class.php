<?php

class PluginAlpimportXlsxImporter
{
    private const MAX_PREVIEW_ROWS = 5;
    private const MAIN_SHEET_NAME = 'Geräte, Netzwerke und IP-Adress';

    public static function getImportsDir(): string
    {
        $base = defined('GLPI_PLUGIN_DOC_DIR')
            ? GLPI_PLUGIN_DOC_DIR
            : (defined('GLPI_VAR_DIR') ? GLPI_VAR_DIR . '/_plugins' : GLPI_ROOT . '/files/_plugins');

        $dir = $base . '/alpimport/imports';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create import directory: ' . $dir);
        }

        return $dir;
    }

    /**
     * @return string[]
     */
    public static function listImports(): array
    {
        $files = [];
        foreach (['xlsx', 'xls'] as $ext) {
            $files = array_merge($files, glob(self::getImportsDir() . '/*.' . $ext) ?: []);
            $files = array_merge($files, glob(dirname(__DIR__) . '/*.' . $ext) ?: []);
        }
        $files = array_unique(array_map('basename', $files));
        rsort($files);
        return array_values($files);
    }

    public static function saveUploadedWorkbook(array $file): string
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
            throw new RuntimeException('XLSX upload failed: ' . ($messages[$error] ?? 'unknown error'));
        }

        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Uploaded file is not a valid upload.');
        }

        $name = (string) ($file['name'] ?? 'import.xlsx');
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new RuntimeException('Only .xlsx files are supported.');
        }

        self::assertXlsxArchive($file['tmp_name']);

        $safeBaseName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($name));
        $targetName = date('Ymd_His') . '_' . $safeBaseName;
        $targetPath = self::getImportsDir() . '/' . $targetName;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new RuntimeException('Could not store uploaded workbook (permissions?).');
        }

        return $targetName;
    }

    public static function inspectStoredWorkbook(string $storedFile): array
    {
        $path = self::getStoredWorkbookPath($storedFile);
        return self::inspectWorkbookFile($path);
    }

    public static function importStoredWorkbook(string $storedFile): array
    {
        global $DB;

        if (!isset($DB) || !($DB instanceof DBmysql)) {
            throw new RuntimeException('Database connection is not available.');
        }

        $path = self::getStoredWorkbookPath($storedFile);
        $workbookData = self::readWorkbookData($path);
        $sheet = self::getMainSheetData($workbookData);
        if (empty($sheet['rows'])) {
            throw new RuntimeException('Main worksheet is empty.');
        }

        $entityId = (int) ($_SESSION['glpiactive_entity'] ?? 0);
        $stats = [
            'processed_rows' => 0,
            'created_computers' => 0,
            'updated_computers' => 0,
            'created_groups' => 0,
            'linked_groups' => 0,
            'created_networkports' => 0,
            'created_networknames' => 0,
            'created_ipaddresses' => 0,
            'created_software' => 0,
            'linked_software' => 0,
            'created_os' => 0,
            'updated_os' => 0,
            'skipped_rows' => 0,
        ];

        $headers = $sheet['headers'];
        foreach ($sheet['rows'] as $row) {
            $rowData = self::rowToAssoc($headers, $row);
            $name = trim((string) ($rowData[2] ?? ''));
            $hostname = trim((string) ($rowData[3] ?? ''));

            if ($name === '' && $hostname === '') {
                $stats['skipped_rows']++;
                continue;
            }
            if ($name === '') {
                $name = $hostname;
            }

            $stats['processed_rows']++;

            $typeCode = trim((string) ($rowData[0] ?? ''));
            $inventoryNumber = trim((string) ($rowData[1] ?? ''));
            $modelName = trim((string) ($rowData[89] ?? ''));
            $notes = trim((string) ($rowData[92] ?? ''));
            $groupStw = trim((string) ($rowData[12] ?? ''));
            $groupOrt = trim((string) ($rowData[13] ?? ''));
            if (mb_strtolower($groupOrt) === 'keine angabe') {
                $groupOrt = '';
            }
            $groupName = trim($groupStw . ($groupOrt !== '' ? ' | ' . $groupOrt : ''));

            $computerTypeId = 0;
            if ($typeCode !== '') {
                $computerTypeId = self::ensureDropdownId('glpi_computertypes', $typeCode);
            }
            $computerModelId = 0;
            if ($modelName !== '') {
                $computerModelId = self::ensureDropdownId('glpi_computermodels', $modelName);
            }

            $commentParts = [];
            if ($notes !== '') {
                $commentParts[] = $notes;
            }
            $comment = implode("\n", $commentParts);

            [$computerId, $isCreated] = self::upsertComputer(
                $entityId,
                $name,
                $inventoryNumber,
                $comment,
                $computerTypeId,
                $computerModelId
            );
            if ($isCreated) {
                $stats['created_computers']++;
            } else {
                $stats['updated_computers']++;
            }

            if ($groupName !== '') {
                $beforeCreatedGroups = self::$createdGroupsCounter;
                $groupId = self::ensureGroupId($entityId, $groupName);
                if (self::$createdGroupsCounter > $beforeCreatedGroups) {
                    $stats['created_groups']++;
                }
                if (self::ensureGroupLink($computerId, $groupId)) {
                    $stats['linked_groups']++;
                }
            }

            $networkNameLabel = $hostname !== '' ? $hostname : $name;
            [$networkPortId, $portCreated] = self::ensurePrimaryNetworkPort($entityId, $computerId);
            if ($portCreated) {
                $stats['created_networkports']++;
            }
            [$networkNameId, $nameCreated] = self::ensurePrimaryNetworkName($entityId, $networkPortId, $networkNameLabel);
            if ($nameCreated) {
                $stats['created_networknames']++;
            }

            $ipSet = self::extractIpAddressesFromRow($rowData);
            foreach ($ipSet as $ip) {
                if (self::ensureIpAddress($entityId, $networkNameId, $ip)) {
                    $stats['created_ipaddresses']++;
                }
            }

            $osName = trim((string) ($rowData[90] ?? ''));
            $osVersion = trim((string) ($rowData[91] ?? ''));
            if ($osName !== '') {
                $osId = self::ensureDropdownId('glpi_operatingsystems', $osName);
                $osVersionId = $osVersion !== '' ? self::ensureDropdownId('glpi_operatingsystemversions', $osVersion) : 0;
                $wasCreated = self::upsertOperatingSystemLink($entityId, $computerId, $osId, $osVersionId);
                if ($wasCreated) {
                    $stats['created_os']++;
                } else {
                    $stats['updated_os']++;
                }
            }

            for ($col = 93; $col <= 104; $col++) {
                $softwareRaw = trim((string) ($rowData[$col] ?? ''));
                if ($softwareRaw === '') {
                    continue;
                }

                [$softwareName, $versionName] = self::splitSoftwareNameAndVersion($softwareRaw);
                if ($softwareName === '') {
                    continue;
                }

                $beforeCreatedSoftware = self::$createdSoftwareCounter;
                $softwareId = self::ensureSoftwareId($softwareName);
                if (self::$createdSoftwareCounter > $beforeCreatedSoftware) {
                    $stats['created_software']++;
                }
                $softwareVersionId = self::ensureSoftwareVersionId($softwareId, $versionName);
                if (self::ensureSoftwareLink($entityId, $computerId, $softwareVersionId)) {
                    $stats['linked_software']++;
                }
            }
        }

        return $stats;
    }

    private static int $createdGroupsCounter = 0;
    private static int $createdSoftwareCounter = 0;

    private static function getStoredWorkbookPath(string $storedFile): string
    {
        $storedFile = basename(trim($storedFile));
        if ($storedFile === '' || strpos($storedFile, '..') !== false) {
            throw new RuntimeException('Invalid workbook file name.');
        }
        $ext = strtolower(pathinfo($storedFile, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xls'], true)) {
            throw new RuntimeException('Only .xlsx/.xls files can be imported.');
        }

        $path = self::getImportsDir() . '/' . $storedFile;
        if (!is_file($path)) {
            $fallbackPath = dirname(__DIR__) . '/' . $storedFile;
            if (is_file($fallbackPath)) {
                $path = $fallbackPath;
            } else {
                throw new RuntimeException('Workbook not found: ' . $storedFile);
            }
        }
        if (!is_readable($path)) {
            throw new RuntimeException('Workbook is not readable: ' . $storedFile);
        }
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'xls') {
            throw new RuntimeException('The selected file is .xls. Please convert it to .xlsx before import.');
        }
        return $path;
    }

    private static function assertXlsxArchive(string $path): void
    {
        $zip = new ZipArchive();
        $status = $zip->open($path, ZipArchive::RDONLY);
        if ($status !== true) {
            throw new RuntimeException('Uploaded file is not a valid XLSX ZIP archive.');
        }

        $isWorkbook = $zip->locateName('xl/workbook.xml') !== false
            && $zip->locateName('[Content_Types].xml') !== false;
        $zip->close();

        if (!$isWorkbook) {
            throw new RuntimeException('Uploaded file does not contain a valid XLSX workbook structure.');
        }
    }

    private static function inspectWorkbookFile(string $path): array
    {
        $workbookData = self::readWorkbookData($path);

        $sheets = [];
        foreach ($workbookData['sheets'] as $sheet) {
            $previewRows = [];
            foreach (array_slice($sheet['rows'], 0, self::MAX_PREVIEW_ROWS) as $row) {
                $previewRows[] = $row;
            }
            $sheets[] = [
                'name'         => $sheet['name'],
                'row_count'    => (int) $sheet['row_count'],
                'headers'      => $sheet['headers'],
                'preview_rows' => $previewRows,
            ];
        }

        return [
            'file_name'   => basename($path),
            'sheet_count' => count($sheets),
            'sheets'      => $sheets,
        ];
    }

    /**
     * @return array{file_name:string,sheets:array<int,array{name:string,row_count:int,headers:array<int,string>,rows:array<int,array<int,string>>}>}
     */
    private static function readWorkbookData(string $path): array
    {
        $zip = new ZipArchive();
        $status = $zip->open($path, ZipArchive::RDONLY);
        if ($status !== true) {
            throw new RuntimeException('Could not open workbook ZIP archive.');
        }

        try {
            $sharedStrings = self::readSharedStrings($zip);
            $sheetMap = self::readSheetsMap($zip);

            $sheets = [];
            foreach ($sheetMap as $sheetDef) {
                $sheetXml = $zip->getFromName($sheetDef['path']);
                if (!is_string($sheetXml) || $sheetXml === '') {
                    $sheets[] = [
                        'name'         => $sheetDef['name'],
                        'row_count'    => 0,
                        'headers'      => [],
                        'rows'         => [],
                    ];
                    continue;
                }

                $sheets[] = self::readSheetXml($sheetDef['name'], $sheetXml, $sharedStrings);
            }

            return [
                'file_name'   => basename($path),
                'sheets'      => $sheets,
            ];
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array<int,string>
     */
    private static function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if (!is_string($xml) || $xml === '') {
            return [];
        }

        $doc = self::loadXml($xml, 'Invalid sharedStrings.xml in workbook.');
        $strings = [];
        foreach ($doc->si as $si) {
            if (isset($si->t)) {
                $strings[] = (string) $si->t;
                continue;
            }

            $parts = [];
            foreach ($si->r as $run) {
                $parts[] = (string) ($run->t ?? '');
            }
            $strings[] = implode('', $parts);
        }

        return $strings;
    }

    /**
     * @return array<int,array{name:string,path:string}>
     */
    private static function readSheetsMap(ZipArchive $zip): array
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if (!is_string($workbookXml) || $workbookXml === '' || !is_string($relsXml) || $relsXml === '') {
            throw new RuntimeException('Workbook metadata is missing (xl/workbook.xml or workbook relationships).');
        }

        $workbook = self::loadXml($workbookXml, 'Invalid xl/workbook.xml.');
        $rels = self::loadXml($relsXml, 'Invalid xl/_rels/workbook.xml.rels.');

        $targetsByRid = [];
        foreach ($rels->Relationship as $rel) {
            $id = (string) ($rel['Id'] ?? '');
            $target = (string) ($rel['Target'] ?? '');
            if ($id !== '' && $target !== '') {
                $targetsByRid[$id] = self::normalizeWorksheetPath($target);
            }
        }

        $docRelsNs = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $sheets = $workbook->sheets?->sheet;
        if ($sheets === null) {
            return [];
        }

        $sheetMap = [];
        foreach ($sheets as $sheet) {
            $attrs = $sheet->attributes();
            $relAttrs = $sheet->attributes($docRelsNs, true);

            $name = (string) ($attrs['name'] ?? '');
            $rid = (string) ($relAttrs['id'] ?? '');
            if ($name === '' || $rid === '' || !isset($targetsByRid[$rid])) {
                continue;
            }

            $sheetMap[] = [
                'name' => $name,
                'path' => $targetsByRid[$rid],
            ];
        }

        return $sheetMap;
    }

    private static function normalizeWorksheetPath(string $target): string
    {
        $target = str_replace('\\', '/', $target);
        $target = preg_replace('~^\./+~', '', $target) ?? $target;
        while (str_starts_with($target, '../')) {
            $target = substr($target, 3);
        }
        if (!str_starts_with($target, 'xl/')) {
            $target = 'xl/' . ltrim($target, '/');
        }
        return $target;
    }

    /**
     * @param array<int,string> $sharedStrings
     * @return array{name:string,row_count:int,headers:array<int,string>,preview_rows:array<int,array<int,string>>}
     */
    private static function readSheetXml(string $name, string $sheetXml, array $sharedStrings): array
    {
        $sheet = self::loadXml($sheetXml, 'Invalid worksheet XML in workbook: ' . $name . '.');
        $rows = $sheet->sheetData?->row;
        if ($rows === null) {
            return [
                'name'         => $name,
                'row_count'    => 0,
                'headers'      => [],
                'rows'         => [],
            ];
        }

        $rowCount = 0;
        $headers = [];
        $dataRows = [];
        foreach ($rows as $row) {
            $values = [];
            foreach ($row->c as $cell) {
                $values[] = self::getCellValue($cell, $sharedStrings);
            }

            if ($rowCount === 0) {
                $headers = $values;
            } else {
                $dataRows[] = $values;
            }
            $rowCount++;
        }

        return [
            'name'         => $name,
            'row_count'    => $rowCount,
            'headers'      => $headers,
            'rows'         => $dataRows,
        ];
    }

    /**
     * @param array<int,string> $sharedStrings
     */
    private static function getCellValue(SimpleXMLElement $cell, array $sharedStrings): string
    {
        $type = (string) ($cell['t'] ?? '');

        if ($type === 's') {
            $idx = (int) ($cell->v ?? -1);
            return $sharedStrings[$idx] ?? '';
        }

        if ($type === 'inlineStr') {
            return (string) ($cell->is->t ?? '');
        }

        if (isset($cell->v)) {
            return (string) $cell->v;
        }

        return '';
    }

    private static function loadXml(string $xml, string $errorMessage): SimpleXMLElement
    {
        $doc = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        if ($doc === false) {
            throw new RuntimeException($errorMessage);
        }
        return $doc;
    }

    /**
     * @param array<int,array{name:string,row_count:int,headers:array<int,string>,rows:array<int,array<int,string>>}> $workbookData
     * @return array{name:string,row_count:int,headers:array<int,string>,rows:array<int,array<int,string>>}
     */
    private static function getMainSheetData(array $workbookData): array
    {
        foreach ($workbookData['sheets'] as $sheet) {
            if (($sheet['name'] ?? '') === self::MAIN_SHEET_NAME) {
                return $sheet;
            }
        }
        if (!empty($workbookData['sheets'])) {
            return $workbookData['sheets'][0];
        }
        return [
            'name' => self::MAIN_SHEET_NAME,
            'row_count' => 0,
            'headers' => [],
            'rows' => [],
        ];
    }

    /**
     * @param array<int,string> $headers
     * @param array<int,string> $row
     * @return array<int,string>
     */
    private static function rowToAssoc(array $headers, array $row): array
    {
        $max = max(count($headers), count($row));
        $result = [];
        for ($i = 0; $i < $max; $i++) {
            $result[$i] = trim((string) ($row[$i] ?? ''));
        }
        return $result;
    }

    /**
     * @param array<int,string> $rowData
     * @return string[]
     */
    private static function extractIpAddressesFromRow(array $rowData): array
    {
        $ips = [];
        foreach ($rowData as $value) {
            $ip = self::normalizeIp($value);
            if ($ip !== null) {
                $ips[$ip] = true;
            }
        }
        return array_keys($ips);
    }

    private static function normalizeIp(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || $value === '-') {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $value;
        }

        $digits = preg_replace('/\D+/', '', $value);
        if ($digits === null || $digits === '') {
            return null;
        }

        if (strlen($digits) === 12) {
            $parts = [
                (int) substr($digits, 0, 3),
                (int) substr($digits, 3, 3),
                (int) substr($digits, 6, 3),
                (int) substr($digits, 9, 3),
            ];
            foreach ($parts as $part) {
                if ($part < 0 || $part > 255) {
                    return null;
                }
            }
            return implode('.', $parts);
        }

        return null;
    }

    private static function ensureDropdownId(string $table, string $name): int
    {
        global $DB;

        $name = trim($name);
        if ($name === '') {
            return 0;
        }

        $iter = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => $table,
            'WHERE'  => ['name' => $name],
            'LIMIT'  => 1,
        ]);
        foreach ($iter as $row) {
            return (int) $row['id'];
        }

        $id = (int) $DB->insert($table, ['name' => $name]);
        return $id > 0 ? $id : 0;
    }

    /**
     * @return array{0:int,1:bool}
     */
    private static function upsertComputer(
        int $entityId,
        string $name,
        string $inventoryNumber,
        string $comment,
        int $computerTypeId,
        int $computerModelId
    ): array {
        global $DB;

        $existingId = 0;
        $iter = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_computers',
            'WHERE'  => [
                'name'       => $name,
                'entities_id' => $entityId,
            ],
            'LIMIT'  => 1,
        ]);
        foreach ($iter as $row) {
            $existingId = (int) $row['id'];
            break;
        }

        $payload = [
            'name' => $name,
            'otherserial' => $inventoryNumber,
            'comment' => $comment,
            'date_mod' => date('Y-m-d H:i:s'),
        ];
        if ($computerTypeId > 0) {
            $payload['computertypes_id'] = $computerTypeId;
        }
        if ($computerModelId > 0) {
            $payload['computermodels_id'] = $computerModelId;
        }

        if ($existingId > 0) {
            $DB->update('glpi_computers', $payload, ['id' => $existingId]);
            return [$existingId, false];
        }

        $payload['entities_id'] = $entityId;
        $payload['date_creation'] = date('Y-m-d H:i:s');
        $payload['is_deleted'] = 0;
        $payload['is_template'] = 0;
        $newId = (int) $DB->insert('glpi_computers', $payload);
        if ($newId <= 0) {
            throw new RuntimeException('Could not create computer: ' . $name);
        }
        return [$newId, true];
    }

    private static function ensureGroupId(int $entityId, string $groupName): int
    {
        global $DB;

        $iter = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_groups',
            'WHERE'  => ['name' => $groupName],
            'LIMIT'  => 1,
        ]);
        foreach ($iter as $row) {
            return (int) $row['id'];
        }

        $id = (int) $DB->insert('glpi_groups', [
            'name' => $groupName,
            'entities_id' => $entityId,
            'is_recursive' => 1,
        ]);
        if ($id > 0) {
            self::$createdGroupsCounter++;
        }
        return $id;
    }

    private static function ensureGroupLink(int $computerId, int $groupId): bool
    {
        global $DB;

        if ($groupId <= 0) {
            return false;
        }
        $iter = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_groups_items',
            'WHERE'  => [
                'itemtype'  => 'Computer',
                'items_id'  => $computerId,
                'groups_id' => $groupId,
            ],
            'LIMIT'  => 1,
        ]);
        foreach ($iter as $_) {
            return false;
        }

        $newId = (int) $DB->insert('glpi_groups_items', [
            'itemtype'  => 'Computer',
            'items_id'  => $computerId,
            'groups_id' => $groupId,
        ]);
        return $newId > 0;
    }

    /**
     * @return array{0:int,1:bool}
     */
    private static function ensurePrimaryNetworkPort(int $entityId, int $computerId): array
    {
        global $DB;

        $iter = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_networkports',
            'WHERE'  => [
                'itemtype' => 'Computer',
                'items_id' => $computerId,
            ],
            'ORDER' => 'id ASC',
            'LIMIT' => 1,
        ]);
        foreach ($iter as $row) {
            return [(int) $row['id'], false];
        }

        $newId = (int) $DB->insert('glpi_networkports', [
            'items_id' => $computerId,
            'itemtype' => 'Computer',
            'entities_id' => $entityId,
            'name' => 'eth0',
            'instantiation_type' => 'NetworkPortEthernet',
            'logical_number' => 1,
        ]);
        if ($newId <= 0) {
            throw new RuntimeException('Could not create network port for computer #' . $computerId);
        }

        if ($DB->tableExists('glpi_networkportethernets')) {
            $DB->insert('glpi_networkportethernets', [
                'networkports_id' => $newId,
                'speed' => 1000,
            ]);
        }

        return [$newId, true];
    }

    /**
     * @return array{0:int,1:bool}
     */
    private static function ensurePrimaryNetworkName(int $entityId, int $networkPortId, string $label): array
    {
        global $DB;

        $iter = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_networknames',
            'WHERE'  => [
                'itemtype' => 'NetworkPort',
                'items_id' => $networkPortId,
            ],
            'ORDER' => 'id ASC',
            'LIMIT' => 1,
        ]);
        foreach ($iter as $row) {
            $id = (int) $row['id'];
            if ($label !== '') {
                $DB->update('glpi_networknames', ['name' => $label], ['id' => $id]);
            }
            return [$id, false];
        }

        $newId = (int) $DB->insert('glpi_networknames', [
            'entities_id' => $entityId,
            'itemtype' => 'NetworkPort',
            'items_id' => $networkPortId,
            'name' => $label !== '' ? $label : ('computer-' . $networkPortId),
        ]);
        if ($newId <= 0) {
            throw new RuntimeException('Could not create network name for port #' . $networkPortId);
        }
        return [$newId, true];
    }

    private static function ensureIpAddress(int $entityId, int $networkNameId, string $ip): bool
    {
        global $DB;

        $iter = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_ipaddresses',
            'WHERE'  => [
                'itemtype' => 'NetworkName',
                'items_id' => $networkNameId,
                'name'     => $ip,
                'version'  => 4,
            ],
            'LIMIT'  => 1,
        ]);
        foreach ($iter as $_) {
            return false;
        }

        $id = (int) $DB->insert('glpi_ipaddresses', [
            'entities_id' => $entityId,
            'itemtype' => 'NetworkName',
            'items_id' => $networkNameId,
            'name' => $ip,
            'version' => 4,
        ]);
        return $id > 0;
    }

    private static function upsertOperatingSystemLink(int $entityId, int $computerId, int $osId, int $osVersionId): bool
    {
        global $DB;

        $iter = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_items_operatingsystems',
            'WHERE'  => [
                'itemtype' => 'Computer',
                'items_id' => $computerId,
            ],
            'ORDER' => 'id ASC',
            'LIMIT' => 1,
        ]);
        foreach ($iter as $row) {
            $payload = [
                'operatingsystems_id' => $osId,
                'date_mod' => date('Y-m-d H:i:s'),
            ];
            if ($osVersionId > 0) {
                $payload['operatingsystemversions_id'] = $osVersionId;
            }
            $DB->update('glpi_items_operatingsystems', $payload, ['id' => (int) $row['id']]);
            return false;
        }

        $payload = [
            'items_id' => $computerId,
            'itemtype' => 'Computer',
            'entities_id' => $entityId,
            'operatingsystems_id' => $osId,
            'date_creation' => date('Y-m-d H:i:s'),
            'date_mod' => date('Y-m-d H:i:s'),
        ];
        if ($osVersionId > 0) {
            $payload['operatingsystemversions_id'] = $osVersionId;
        }
        $id = (int) $DB->insert('glpi_items_operatingsystems', $payload);
        return $id > 0;
    }

    /**
     * @return array{0:string,1:string}
     */
    private static function splitSoftwareNameAndVersion(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return ['', 'imported'];
        }

        if (preg_match('/^(.+?)\\s+([vV]?\\d[\\w.\\-/]*)$/u', $raw, $m)) {
            return [trim($m[1]), trim($m[2])];
        }

        return [$raw, 'imported'];
    }

    private static function ensureSoftwareId(string $name): int
    {
        global $DB;

        $iter = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_softwares',
            'WHERE'  => ['name' => $name],
            'LIMIT'  => 1,
        ]);
        foreach ($iter as $row) {
            return (int) $row['id'];
        }

        $id = (int) $DB->insert('glpi_softwares', [
            'name' => $name,
            'entities_id' => 0,
            'is_recursive' => 1,
        ]);
        if ($id > 0) {
            self::$createdSoftwareCounter++;
        }
        return $id;
    }

    private static function ensureSoftwareVersionId(int $softwareId, string $versionName): int
    {
        global $DB;

        $versionName = trim($versionName) !== '' ? trim($versionName) : 'imported';
        $iter = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_softwareversions',
            'WHERE'  => [
                'softwares_id' => $softwareId,
                'name'         => $versionName,
            ],
            'LIMIT'  => 1,
        ]);
        foreach ($iter as $row) {
            return (int) $row['id'];
        }

        return (int) $DB->insert('glpi_softwareversions', [
            'softwares_id' => $softwareId,
            'name' => $versionName,
            'entities_id' => 0,
            'is_recursive' => 1,
        ]);
    }

    private static function ensureSoftwareLink(int $entityId, int $computerId, int $softwareVersionId): bool
    {
        global $DB;

        if ($softwareVersionId <= 0) {
            return false;
        }

        $iter = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_items_softwareversions',
            'WHERE'  => [
                'items_id' => $computerId,
                'itemtype' => 'Computer',
                'softwareversions_id' => $softwareVersionId,
            ],
            'LIMIT'  => 1,
        ]);
        foreach ($iter as $_) {
            return false;
        }

        $id = (int) $DB->insert('glpi_items_softwareversions', [
            'items_id' => $computerId,
            'itemtype' => 'Computer',
            'softwareversions_id' => $softwareVersionId,
            'entities_id' => $entityId,
            'is_deleted_item' => 0,
            'is_template_item' => 0,
            'is_deleted' => 0,
            'is_dynamic' => 0,
            'date_install' => date('Y-m-d H:i:s'),
        ]);
        return $id > 0;
    }
}
