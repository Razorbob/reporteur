<?php

include('../../../inc/includes.php');

/** @var array $CFG_GLPI */
global $CFG_GLPI;

Session::checkLoginUser();

require_once(__DIR__ . '/../inc/templateprocessor.class.php');

$errorMessage = '';
$infoMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Detect the most common cause of "missing token": PHP silently dropped
        // the POST body because it exceeded post_max_size (then $_POST and $_FILES are empty).
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        $postMaxSize = (function (): int {
            $val = trim((string) ini_get('post_max_size'));
            if ($val === '') {
                return 0;
            }
            $unit = strtolower(substr($val, -1));
            $num = (int) $val;
            return match ($unit) {
                'g' => $num * 1024 * 1024 * 1024,
                'm' => $num * 1024 * 1024,
                'k' => $num * 1024,
                default => (int) $val,
            };
        })();
        if ($postMaxSize > 0 && $contentLength > $postMaxSize) {
            throw new RuntimeException(sprintf(
                'Uploaded data (%d bytes) exceeds PHP post_max_size (%d bytes). Increase post_max_size and upload_max_filesize in php.ini.',
                $contentLength,
                $postMaxSize
            ));
        }
        if (empty($_POST) && $contentLength > 0) {
            throw new RuntimeException('POST body was dropped by PHP (likely exceeded post_max_size or upload_max_filesize). Check php.ini limits.');
        }

        // GLPI's shared CSRF token store ($_SESSION['glpicsrftokens']) is consumed by
        // many other widgets/AJAX calls during the page lifecycle, which can evict our
        // form token before we get to validate it. Use a dedicated session slot instead.
        $submittedToken = (string) ($_POST['_alpreport_token'] ?? '');
        $expectedToken = (string) ($_SESSION['plugin_alpreport_csrf'] ?? '');
        if (
            $expectedToken === ''
            || $submittedToken === ''
            || !hash_equals($expectedToken, $submittedToken)
        ) {
            throw new RuntimeException('Invalid or expired security token. Please reload the page and try again.');
        }

        // Delete-template action (separate flow from generate).
        $action = (string)($_POST['alpreport_action'] ?? 'generate');
        if ($action === 'delete_template') {
            $toDelete = trim((string)($_POST['existing_template'] ?? ''));
            if ($toDelete === '') {
                throw new RuntimeException('No template selected for deletion.');
            }
            PluginAlpreportTemplateProcessor::deleteTemplate($toDelete);
            $infoMessage = 'Template deleted: ' . basename($toDelete);
        } else {
            $itemType = $_POST['itemtype'] ?? 'Computer';
        $itemId = (int)($_POST['items_id'] ?? 0);
        if ($itemId <= 0) {
            throw new RuntimeException('Please pick an asset.');
        }

        $templateName = trim((string)($_POST['existing_template'] ?? ''));
        if (!empty($_FILES['template_file']['name'] ?? '')) {
            $templateName = PluginAlpreportTemplateProcessor::saveUploadedTemplate($_FILES['template_file']);
        }

        if ($templateName === '') {
            throw new RuntimeException('Upload a template or choose an existing one.');
        }

        $templatePath = PluginAlpreportTemplateProcessor::getTemplatesDir() . '/' . basename($templateName);
        if (!is_file($templatePath)) {
            throw new RuntimeException('Template file not found on disk: ' . basename($templateName));
        }
        if (!is_readable($templatePath)) {
            throw new RuntimeException('Template file is not readable. Check file permissions.');
        }

        $asset = PluginAlpreportTemplateProcessor::resolveAsset($itemType, $itemId);
        $map = PluginAlpreportTemplateProcessor::buildPlaceholderMap($asset);
        $blockMap = PluginAlpreportTemplateProcessor::buildBlockMap($asset);
        
        // DEBUG MODE: Output collected data instead of generating DOCX
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: inline; filename="debug_data_' . date('Ymd_Hi') . '.txt"');
        
        echo "=== DEBUG OUTPUT - COLLECTED DATA MAPS ===\n\n";
        echo "Generated at: " . date('Y-m-d H:i:s') . "\n";
        echo "Template: " . basename($templatePath) . "\n";
        echo "Item Type: " . $itemType . "\n";
        echo "Item ID: " . $itemId . "\n\n";
        
        echo "=== PLACEHOLDER MAP ===\n";
        echo "Count: " . count($map) . " placeholders\n\n";
        foreach ($map as $key => $value) {
            echo str_pad($key, 40) . " => " . (is_string($value) ? $value : json_encode($value, JSON_PRETTY_PRINT)) . "\n";
        }
        
        echo "\n\n=== BLOCK MAP ===\n";
        echo "Count: " . count($blockMap) . " blocks\n\n";
        foreach ($blockMap as $blockKey => $blockData) {
            echo "Block: " . $blockKey . "\n";
            if (is_array($blockData)) {
                echo json_encode($blockData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
            } else {
                echo var_export($blockData, true) . "\n";
            }
            echo "\n";
        }
        
        echo "\n=== END DEBUG OUTPUT ===\n";
        exit;
        
        /* COMMENTED OUT - DOCX GENERATION AND DOWNLOAD
        $generatedPath = PluginAlpreportTemplateProcessor::renderDocx($templatePath, $map, $blockMap);

        if (!is_file($generatedPath) || filesize($generatedPath) === 0) {
            @unlink($generatedPath);
            throw new RuntimeException('Generated document is empty or missing.');
        }

        $downloadName = trim((string)($_POST['download_name'] ?? ''));
        if ($downloadName === '') {
            // Default: <assetname>_<YYYYMMDD_HHMM>.docx
            $assetName = (string)($asset->fields['name'] ?? '');
            if ($assetName === '') {
                $assetName = strtolower($itemType) . '_' . $itemId;
            }
            $downloadName = $assetName . '_' . date('Ymd_Hi') . '.docx';
        }
        if (strtolower(pathinfo($downloadName, PATHINFO_EXTENSION)) !== 'docx') {
            $downloadName .= '.docx';
        }

        $downloadName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $downloadName);
        // Collapse runs of underscores and trim leading/trailing underscores.
        $downloadName = trim(preg_replace('/_+/', '_', $downloadName), '_');
        if ($downloadName === '' || $downloadName === '.docx') {
            $downloadName = 'asset_report_' . date('Ymd_Hi') . '.docx';
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Description: File Transfer');
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . filesize($generatedPath));
        header('Cache-Control: no-store');

        $streamed = readfile($generatedPath);
        @unlink($generatedPath);
        if ($streamed === false) {
            // Headers are already sent so we can't render a normal error page;
            // log it and exit. The user will see a partial / failed download.
            if (class_exists('Toolbox') && method_exists('Toolbox', 'logInFile')) {
                Toolbox::logInFile('alpreport', 'readfile() failed for generated DOCX', true);
            }
        }
        exit;
        END COMMENTED OUT - DOCX GENERATION AND DOWNLOAD */
        } // end else (generate)
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
        if (class_exists('Toolbox') && method_exists('Toolbox', 'logInFile')) {
            Toolbox::logInFile(
                'alpreport',
                $e->getMessage() . "\n" . $e->getTraceAsString(),
                true
            );
        }
    }
}

$templates = PluginAlpreportTemplateProcessor::listTemplates();
$itemtypes = PluginAlpreportTemplateProcessor::SUPPORTED_ITEMTYPES;

Html::header('Alp Report', $_SERVER['PHP_SELF'], 'tools', 'PluginAlpreportMenu');

echo "<div class='center'>";
echo "<h2>Asset DOCX Generator</h2>";

if ($errorMessage !== '') {
    echo "<div class='center b' style='color:#b00020; margin-bottom:12px;'>"
        . Html::entities_deep($errorMessage)
        . "</div>";
}
if ($infoMessage !== '') {
    echo "<div class='center b' style='color:#0a7c34; margin-bottom:12px;'>"
        . Html::entities_deep($infoMessage)
        . "</div>";
}

echo "<form method='post' enctype='multipart/form-data' class='tab_cadre_fixe' style='max-width:820px;padding:16px;'>";

// Plugin-scoped CSRF token (kept in a dedicated session slot to avoid being evicted
// by GLPI's shared token store, which is heavily churned by widgets and AJAX dropdowns).
if (empty($_SESSION['plugin_alpreport_csrf'])) {
    $_SESSION['plugin_alpreport_csrf'] = bin2hex(random_bytes(32));
}
$alpreportCsrfToken = $_SESSION['plugin_alpreport_csrf'];
echo "<input type='hidden' name='_alpreport_token' value='" . htmlspecialchars($alpreportCsrfToken, ENT_QUOTES) . "'>";

// Also include GLPI's standard token in case any framework layer expects it.
echo "<input type='hidden' name='_glpi_csrf_token' value='" . htmlspecialchars(Session::getNewCSRFToken(), ENT_QUOTES) . "'>";

echo "<table class='tab_cadre_fixe'>";
echo "<tr><th colspan='2'>Generate from Template</th></tr>";

// Itemtype + items_id picker.
// To avoid relying on /ajax/dropdownAllItems.php (which has its own access checks
// and IDOR/entity-restriction quirks), we render one items dropdown per supported
// itemtype and toggle visibility from the itemtype selector using plain JS.
echo "<tr><td>Asset</td><td>";

$rand = mt_rand();
$selectedItemtype = $_POST['itemtype'] ?? 'Computer';
if (!in_array($selectedItemtype, $itemtypes, true)) {
    $selectedItemtype = 'Computer';
}

// Itemtype select.
$itemtypeOptions = [];
foreach ($itemtypes as $type) {
    if ($obj = getItemForItemtype($type)) {
        $itemtypeOptions[$type] = $obj->getTypeName(1);
    }
}
$selectId = 'alpreport_itemtype_' . $rand;
echo "<select id='" . htmlspecialchars($selectId, ENT_QUOTES) . "' name='itemtype' class='form-select' style='width:300px;display:inline-block;'>";
foreach ($itemtypeOptions as $value => $label) {
    $selAttr = $value === $selectedItemtype ? ' selected' : '';
    echo "<option value='" . htmlspecialchars($value, ENT_QUOTES) . "'$selAttr>" . htmlspecialchars($label) . "</option>";
}
echo "</select>";

echo "<br><br>";

// One items_id picker per itemtype, hidden when not active.
foreach ($itemtypes as $type) {
    $wrapperId = 'alpreport_items_wrapper_' . $type . '_' . $rand;
    $isActive = ($type === $selectedItemtype);
    echo "<span id='" . htmlspecialchars($wrapperId, ENT_QUOTES) . "' style='" . ($isActive ? '' : 'display:none;') . "'>";
    Dropdown::show($type, [
        'name'    => $isActive ? 'items_id' : ('items_id_' . $type),
        'rand'    => $rand + crc32($type) % 100000,
        'width'   => '300px',
        'entity'  => $_SESSION['glpiactiveentities'] ?? -1,
    ]);
    echo "</span>";
}

// JS to toggle visibility + rename the active items_id field so only one is submitted.
$itemtypesJson = json_encode($itemtypes);
$randJson = json_encode($rand);
echo Html::scriptBlock(<<<JS
(function () {
    var itemtypes = $itemtypesJson;
    var rand = $randJson;
    var sel = document.getElementById('$selectId');
    function refresh() {
        var current = sel.value;
        itemtypes.forEach(function (t) {
            var wrapper = document.getElementById('alpreport_items_wrapper_' + t + '_' + rand);
            if (!wrapper) { return; }
            var active = (t === current);
            wrapper.style.display = active ? '' : 'none';
            // The inner select has either name="items_id" or name="items_id_<type>".
            var innerSelect = wrapper.querySelector('select');
            if (innerSelect) {
                innerSelect.name = active ? 'items_id' : ('items_id_' + t);
            }
        });
    }
    sel.addEventListener('change', refresh);
    refresh();
})();
JS
);
echo "</td></tr>";

echo "<tr><td>Upload DOCX template</td><td><input type='file' name='template_file' accept='.docx'></td></tr>";

echo "<tr><td>Or choose existing template</td><td>";
if (empty($templates)) {
    echo "<em>No saved templates yet.</em>";
    // Hidden field so existing_template still gets submitted as empty.
    echo "<input type='hidden' name='existing_template' value=''>";
} else {
    echo "<div id='alpreport_template_list' style='border:1px solid #ddd;border-radius:4px;max-width:500px;'>";
    foreach ($templates as $idx => $template) {
        $tplEsc = htmlspecialchars($template, ENT_QUOTES);
        $rowId  = 'alpreport_tpl_' . $idx;
        echo "<label for='$rowId' style='display:flex;align-items:center;gap:8px;padding:6px 10px;"
            . ($idx > 0 ? 'border-top:1px solid #eee;' : '')
            . "cursor:pointer;'>";
        echo "<input type='radio' id='$rowId' name='existing_template' value='$tplEsc'"
            . ($idx === 0 ? "" : "") . ">";
        echo "<span style='flex:1;font-family:monospace;font-size:0.95em;'>" . htmlspecialchars($template) . "</span>";
        echo "<button type='submit' name='alpreport_action' value='delete_template' "
            . "title='Delete this template' "
            . "onclick=\"this.form.querySelector('#$rowId').checked=true;"
            . "return confirm('Delete template \\'" . addslashes($template) . "\\'?');\" "
            . "style='background:#fff;border:1px solid #c00;color:#c00;border-radius:50%;"
            . "width:22px;height:22px;line-height:18px;padding:0;font-weight:bold;cursor:pointer;'>"
            . "&times;</button>";
        echo "</label>";
    }
    echo "</div>";
}
echo "</td></tr>";

echo "<tr><td>Download filename</td><td><input type='text' name='download_name' placeholder='&lt;assetname&gt;_YYYYMMDD_HHMM.docx (auto)' style='width:300px;'></td></tr>";

echo "<tr><td colspan='2' class='center' style='padding:12px;'>";
echo "<button type='submit' name='alpreport_action' value='generate' class='btn btn-primary'>Generate DOCX</button> ";
echo "&nbsp;<a class='btn btn-secondary' href='sample.php'>Download sample template</a>";
echo "</td></tr>";

echo "</table>";
echo "</form>";

echo "<div style='max-width:820px;margin:14px auto;text-align:left;'>";
echo "<h3>Available placeholders</h3>";
echo "<ul>";
echo "<li><b>Asset</b>: {{asset_name}}, {{asset_serial}}, {{asset_otherserial}}, {{asset_uuid}}, {{asset_comment}}, {{asset_contact}}, {{asset_contact_num}}, {{asset_date_creation}}, {{asset_date_mod}}</li>";
echo "<li><b>Resolved dropdowns</b>: {{asset_location}}, {{asset_state}}, {{asset_manufacturer}}, {{asset_model}}, {{asset_type}}, {{asset_os}}, {{asset_group}}, {{asset_entity}}, {{asset_domain}}, {{asset_network}}</li>";
echo "<li><b>User</b>: {{asset_user_name}}, {{asset_user_login}}, {{asset_user_realname}}, {{asset_user_email}}, {{asset_user_phone}}</li>";
echo "<li><b>Network</b>: {{asset_ip}}, {{asset_mac}}</li>";
echo "<li><b>Components (all)</b>: {{components}}</li>";
echo "<li><b>Components per type</b>: {{components_processor}}, {{components_memory}}, {{components_harddrive}}, {{components_networkcard}}, {{components_graphiccard}}, {{components_soundcard}}, {{components_motherboard}}, {{components_powersupply}}, {{components_drive}}, {{components_control}}, {{components_case}}, {{components_pci}}, {{components_simcard}}, {{components_sensor}}, {{components_battery}}, {{components_firmware}}, {{components_generic}}, {{components_camera}}</li>";
echo "<li><b>Component counts</b>: {{components_processor_count}}, {{components_memory_count}}, ...</li>";
echo "<li><b>Connected peripherals (tables)</b>: {{monitors}}</li>";
echo "<li><b>Any DB field</b>: {{field_<columnname>}} (e.g. {{field_contact}})</li>";
echo "<li><b>Any FK</b>: {{hardware_<fieldname>_id}} or {{hardware_<fieldname>}} (resolved name)</li>";
echo "<li><b>Misc</b>: {{generated_at}}</li>";
echo "</ul>";
echo "</div>";

echo "</div>";

Html::footer();
