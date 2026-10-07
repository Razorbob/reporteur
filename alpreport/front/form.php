<?php

include('../../../inc/includes.php');

/** @var array $CFG_GLPI */
global $CFG_GLPI;

Session::checkLoginUser();

require_once(__DIR__ . '/../inc/templateprocessor.class.php');

$formUrl = ($CFG_GLPI['root_doc'] ?? '') . '/plugins/alpreport/front/form.php';

if (empty($_SESSION['plugin_alpreport_csrf'])) {
    $_SESSION['plugin_alpreport_csrf'] = bin2hex(random_bytes(32));
}

$errorMessage = '';
$infoMessage = trim((string)($_GET['deleted_template'] ?? '')) !== ''
    ? 'Template deleted: ' . basename((string)$_GET['deleted_template'])
    : '';

if (isset($_GET['delete_template'])) {
    try {
        $submittedToken = (string)($_GET['_alpreport_token'] ?? '');
        $expectedToken = (string)($_SESSION['plugin_alpreport_csrf'] ?? '');
        if (
            $expectedToken === ''
            || $submittedToken === ''
            || !hash_equals($expectedToken, $submittedToken)
        ) {
            throw new RuntimeException('Invalid or expired security token. Please reload the page and try again.');
        }

        $toDelete = trim((string)$_GET['delete_template']);
        if ($toDelete === '') {
            throw new RuntimeException('No template selected for deletion.');
        }

        PluginAlpreportTemplateProcessor::deleteTemplate($toDelete);
        $_SESSION['plugin_alpreport_csrf'] = bin2hex(random_bytes(32));

        $redirectUrl = $formUrl . '?deleted_template=' . rawurlencode(basename($toDelete));
        header('Location: ' . $redirectUrl);
        exit;
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

        $itemType = $_POST['itemtype'] ?? 'Computer';
        $bulk = !empty($_POST['bulk_mode']);
        $itemId = (int)($_POST['items_id'] ?? 0);
        $bulkItems = [];
        if ($bulk) {
            foreach ((array)($_POST['bulk_items'] ?? []) as $entry) {
                if (!is_string($entry) || !preg_match('/^([A-Za-z]+):(\d+)$/', $entry, $bm)) {
                    continue;
                }
                if (in_array($bm[1], PluginAlpreportTemplateProcessor::SUPPORTED_ASSET_TYPES, true)) {
                    $bulkItems[$entry] = [$bm[1], (int)$bm[2]];
                }
            }
            if (empty($bulkItems)) {
                throw new RuntimeException('Please pick at least one asset.');
            }
        } elseif ($itemId <= 0) {
            throw new RuntimeException('Please pick an asset.');
        }

        $templateName = trim((string)($_POST['existing_template'] ?? ''));
        if (!empty($_FILES['template_file']['name'] ?? '')) {
            $templateName = PluginAlpreportTemplateProcessor::saveUploadedTemplate($_FILES['template_file']);
            header('X-Alpreport-Template: ' . rawurlencode($templateName));
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

        $sanitizeName = static function (string $name, string $ext, string $fallback): string {
            if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== $ext) {
                $name .= '.' . $ext;
            }
            $name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
            $name = trim(preg_replace('/_+/', '_', $name), '_');
            return ($name === '' || $name === '.' . $ext) ? $fallback : $name;
        };

        $buildReport = static function (string $itemType, int $id) use ($templatePath): array {
            $asset = PluginAlpreportTemplateProcessor::resolveAsset($itemType, $id);
            $generatedPath = PluginAlpreportTemplateProcessor::render(
                $templatePath,
                PluginAlpreportTemplateProcessor::buildPlaceholderMap($asset),
                PluginAlpreportTemplateProcessor::buildBlockMap($asset),
                PluginAlpreportTemplateProcessor::buildComponentRowData($asset)
            );
            if (!is_file($generatedPath) || filesize($generatedPath) === 0) {
                @unlink($generatedPath);
                throw new RuntimeException('Generated document is empty or missing.');
            }
            $assetName = (string)($asset->fields['name'] ?? '');
            if ($assetName === '') {
                $assetName = strtolower($itemType) . '_' . $id;
            }
            return [$generatedPath, $assetName];
        };

        if ($bulk) {
            $zipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'alpreport_' . bin2hex(random_bytes(8)) . '.zip';
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create ZIP archive.');
            }
            $tmpFiles = [];
            $usedNames = [];
            $failed = [];
            foreach ($bulkItems as [$bulkType, $id]) {
                try {
                    [$path, $assetName] = $buildReport($bulkType, $id);
                    $tmpFiles[] = $path;
                    $base = $sanitizeName($assetName . '_' . date('Ymd_Hi'), 'docx', 'asset_' . $id . '.docx');
                    $entry = $base;
                    for ($n = 2; isset($usedNames[$entry]); $n++) {
                        $entry = preg_replace('/\.docx$/', '', $base) . '_' . $n . '.docx';
                    }
                    $usedNames[$entry] = true;
                    $zip->addFile($path, $entry);
                } catch (Throwable $e) {
                    $failed[] = '#' . $id . ': ' . $e->getMessage();
                    if (class_exists('Toolbox') && method_exists('Toolbox', 'logInFile')) {
                        Toolbox::logInFile('alpreport', 'Bulk item #' . $id . ': ' . $e->getMessage(), true);
                    }
                }
            }
            if (empty($tmpFiles)) {
                $zip->close();
                @unlink($zipPath);
                throw new RuntimeException('No report could be generated. ' . implode('; ', $failed));
            }
            if (!empty($failed)) {
                $zip->addFromString('errors.txt', implode("\n", $failed));
            }
            $closed = $zip->close();
            foreach ($tmpFiles as $tmp) {
                @unlink($tmp);
            }
            if (!$closed || !is_file($zipPath)) {
                @unlink($zipPath);
                throw new RuntimeException('Failed to finalize ZIP archive.');
            }

            $generatedPath = $zipPath;
            $downloadName = $sanitizeName(
                trim((string)($_POST['download_name'] ?? '')) !== ''
                    ? preg_replace('/\.zip$/i', '', trim((string)$_POST['download_name']))
                    : 'alpreport_assets_' . date('Ymd_Hi'),
                'zip',
                'alpreport_' . date('Ymd_Hi') . '.zip'
            );
            $contentType = 'application/zip';
            if (!empty($failed)) {
                header('X-Alpreport-Failed: ' . count($failed));
            }
        } else {
            [$generatedPath, $assetName] = $buildReport($itemType, $itemId);
            $downloadName = trim((string)($_POST['download_name'] ?? ''));
            if ($downloadName === '') {
                $downloadName = $assetName . '_' . date('Ymd_Hi') . '.docx';
            }
            $downloadName = $sanitizeName($downloadName, 'docx', 'asset_report_' . date('Ymd_Hi') . '.docx');
            $contentType = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Description: File Transfer');
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Access-Control-Expose-Headers: Content-Disposition, X-Alpreport-Template, X-Alpreport-Failed');
        header('Content-Length: ' . filesize($generatedPath));
        header('Cache-Control: no-store');

        $streamed = readfile($generatedPath);
        @unlink($generatedPath);
        if ($streamed === false) {
            // Headers are already sent so we can't render a normal error page;
            // log it and exit. The user will see a partial / failed download.
            if (class_exists('Toolbox') && method_exists('Toolbox', 'logInFile')) {
                Toolbox::logInFile('alpreport', 'readfile() failed for generated report', true);
            }
        }
        exit;
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
        if (class_exists('Toolbox') && method_exists('Toolbox', 'logInFile')) {
            Toolbox::logInFile(
                'alpreport',
                $e->getMessage() . "\n" . $e->getTraceAsString(),
                true
            );
        }
        if (strcasecmp((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') === 0) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => $errorMessage]);
            exit;
        }
    }
}

$templates = PluginAlpreportTemplateProcessor::listTemplates();
$assetTypes = PluginAlpreportTemplateProcessor::SUPPORTED_ASSET_TYPES;

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
$alpreportCsrfToken = $_SESSION['plugin_alpreport_csrf'];
echo "<input type='hidden' name='_alpreport_token' value='" . htmlspecialchars($alpreportCsrfToken, ENT_QUOTES) . "'>";

// Also include GLPI's standard token in case any framework layer expects it.
echo "<input type='hidden' name='_glpi_csrf_token' value='" . htmlspecialchars(Session::getNewCSRFToken(), ENT_QUOTES) . "'>";

echo "<table class='tab_cadre_fixe'>";
echo "<tr><th colspan='2'>Generate from Template</th></tr>";

// Asset type + items_id picker.
// To avoid relying on /ajax/dropdownAllItems.php (which has its own access checks
// and IDOR/entity-restriction quirks), we render one items dropdown per supported
// asset type and toggle visibility from the asset type selector using plain JS.
echo "<tr><td>Asset</td><td>";

$rand = mt_rand();
$selectedAssetType = $_POST['itemtype'] ?? 'Computer';
if (!in_array($selectedAssetType, $assetTypes, true)) {
    $selectedAssetType = 'Computer';
}

// Asset type select. The posted field remains "itemtype" because GLPI APIs use itemtype values.
$assetTypeOptions = [];
foreach ($assetTypes as $type) {
    if ($obj = getItemForItemtype($type)) {
        $assetTypeOptions[$type] = $obj->getTypeName(1);
    }
}
$selectId = 'alpreport_itemtype_' . $rand;
echo "<select id='" . htmlspecialchars($selectId, ENT_QUOTES) . "' name='itemtype' class='form-select' style='width:300px;display:inline-block;'>";
foreach ($assetTypeOptions as $value => $label) {
    $selAttr = $value === $selectedAssetType ? ' selected' : '';
    echo "<option value='" . htmlspecialchars($value, ENT_QUOTES) . "'$selAttr>" . htmlspecialchars($label) . "</option>";
}
echo "</select>";

echo "&nbsp;&nbsp;<label style='cursor:pointer;'><input type='checkbox' name='bulk_mode' value='1' id='alpreport_bulk_" . $rand . "'> Bulk mode (multiple assets &rarr; ZIP)</label>";

echo "<br><br>";

// One items_id picker per asset type, hidden when not active.
foreach ($assetTypes as $type) {
    $wrapperId = 'alpreport_items_wrapper_' . $type . '_' . $rand;
    $isActive = ($type === $selectedAssetType);
    echo "<span id='" . htmlspecialchars($wrapperId, ENT_QUOTES) . "' style='" . ($isActive ? '' : 'display:none;') . "'>";
    Dropdown::show($type, [
        'name'    => $isActive ? 'items_id' : ('items_id_' . $type),
        'rand'    => $rand + crc32($type) % 100000,
        'width'   => '300px',
        'entity'  => $_SESSION['glpiactiveentities'] ?? -1,
    ]);
    echo "</span>";
}

// Bulk panel: unified double list (available / selected) across all asset types.
$assetsUrl = htmlspecialchars(($CFG_GLPI['root_doc'] ?? '') . '/plugins/alpreport/front/assets.php', ENT_QUOTES);
$listStyle = 'width:100%;height:220px;border:1px solid #ccc;border-radius:4px;padding:4px;';
echo "<div id='alpreport_bulk_panel_" . $rand . "' data-url='" . $assetsUrl . "' style='display:none;max-width:700px;'>";
echo "<input type='text' id='alpreport_bulk_search_" . $rand . "' class='form-control' placeholder='Search assets (name or ID) in all types...' style='margin-bottom:6px;'>";
echo "<div style='display:flex;gap:8px;align-items:stretch;'>";
echo "<div style='flex:1;'><div><b>Available</b> <small id='alpreport_bulk_count_a_" . $rand . "'></small></div><select multiple id='alpreport_bulk_avail_" . $rand . "' style='" . $listStyle . "'></select></div>";
echo "<div style='display:flex;flex-direction:column;justify-content:center;gap:6px;'>"
    . "<button type='button' class='btn btn-secondary' id='alpreport_bulk_add_" . $rand . "' title='Add selected'>&rarr;</button>"
    . "<button type='button' class='btn btn-secondary' id='alpreport_bulk_addall_" . $rand . "' title='Add all shown'>&raquo;</button>"
    . "<button type='button' class='btn btn-secondary' id='alpreport_bulk_rem_" . $rand . "' title='Remove selected'>&larr;</button>"
    . "<button type='button' class='btn btn-secondary' id='alpreport_bulk_remall_" . $rand . "' title='Remove all'>&laquo;</button>"
    . "</div>";
echo "<div style='flex:1;'><div><b>Selected</b> <small id='alpreport_bulk_count_s_" . $rand . "'></small></div><select multiple id='alpreport_bulk_sel_" . $rand . "' style='" . $listStyle . "'></select></div>";
echo "</div></div>";

// JS to toggle visibility + rename the active items_id field so only one is submitted.
$assetTypesJson = json_encode($assetTypes);
$randJson = json_encode($rand);
echo Html::scriptBlock(<<<JS
(function () {
    var assetTypes = $assetTypesJson;
    var rand = $randJson;
    var sel = document.getElementById('$selectId');
    var bulk = document.getElementById('alpreport_bulk_' + rand);
    var panel = document.getElementById('alpreport_bulk_panel_' + rand);
    var search = document.getElementById('alpreport_bulk_search_' + rand);
    var avail = document.getElementById('alpreport_bulk_avail_' + rand);
    var chosen = document.getElementById('alpreport_bulk_sel_' + rand);
    var cntA = document.getElementById('alpreport_bulk_count_a_' + rand);
    var cntS = document.getElementById('alpreport_bulk_count_s_' + rand);
    var timer = null;
    var loaded = false;

    function counts() {
        cntA.textContent = '(' + avail.options.length + ')';
        cntS.textContent = '(' + chosen.options.length + ')';
    }
    function chosenKeys() {
        var k = {};
        Array.prototype.forEach.call(chosen.options, function (o) { k[o.value] = true; });
        return k;
    }
    function addOption(select, value, label) {
        var o = document.createElement('option');
        o.value = value; o.textContent = label;
        // keep lists sorted by label
        var before = null;
        Array.prototype.some.call(select.options, function (x) {
            if (x.textContent.toLowerCase() > label.toLowerCase()) { before = x; return true; }
            return false;
        });
        select.insertBefore(o, before);
    }
    function move(from, to, onlySelected) {
        Array.prototype.slice.call(from.options).forEach(function (o) {
            if (onlySelected && !o.selected) { return; }
            addOption(to, o.value, o.textContent);
            from.removeChild(o);
        });
        counts();
    }
    function load() {
        var url = panel.getAttribute('data-url') + '?q=' + encodeURIComponent(search.value);
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (items) {
                var taken = chosenKeys();
                avail.innerHTML = '';
                items.forEach(function (it) {
                    if (!taken[it.key]) { addOption(avail, it.key, it.label); }
                });
                counts();
            });
    }
    document.getElementById('alpreport_bulk_add_' + rand).addEventListener('click', function () { move(avail, chosen, true); });
    document.getElementById('alpreport_bulk_addall_' + rand).addEventListener('click', function () { move(avail, chosen, false); });
    document.getElementById('alpreport_bulk_rem_' + rand).addEventListener('click', function () { move(chosen, avail, true); });
    document.getElementById('alpreport_bulk_remall_' + rand).addEventListener('click', function () { move(chosen, avail, false); });
    avail.addEventListener('dblclick', function () { move(avail, chosen, true); });
    chosen.addEventListener('dblclick', function () { move(chosen, avail, true); });
    search.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(load, 250); });
    search.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); clearTimeout(timer); load(); } });
    // Expose picked entries to the submit handler.
    panel.alpreportGetSelected = function () {
        return Array.prototype.map.call(chosen.options, function (o) { return o.value; });
    };

    function refresh() {
        var current = sel.value;
        var isBulk = bulk.checked;
        panel.style.display = isBulk ? '' : 'none';
        sel.style.display = isBulk ? 'none' : 'inline-block';
        if (isBulk && !loaded) { loaded = true; load(); }
        assetTypes.forEach(function (t) {
            var active = (t === current);
            var wrapper = document.getElementById('alpreport_items_wrapper_' + t + '_' + rand);
            if (wrapper) {
                wrapper.style.display = (active && !isBulk) ? '' : 'none';
                var innerSelect = wrapper.querySelector('select');
                if (innerSelect) {
                    innerSelect.name = (active && !isBulk) ? 'items_id' : ('items_id_' + t);
                }
            }
        });
    }
    sel.addEventListener('change', refresh);
    bulk.addEventListener('change', refresh);
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
        $deleteUrl = htmlspecialchars(
            $formUrl
                . '?delete_template=' . rawurlencode($template)
                . '&_alpreport_token=' . rawurlencode($alpreportCsrfToken),
            ENT_QUOTES
        );
        echo "<a href='$deleteUrl' "
            . "title='Delete this template' "
            . "onclick=\"return confirm('Delete template \\'" . addslashes($template) . "\\'?');\" "
            . "style='background:#fff;border:1px solid #c00;color:#c00;border-radius:50%;"
            . "width:22px;height:22px;line-height:18px;padding:0;font-weight:bold;cursor:pointer;"
            . "display:inline-flex;align-items:center;justify-content:center;text-decoration:none;'>"
            . "&times;</a>";
        echo "</label>";
    }
    echo "</div>";
}
echo "</td></tr>";

echo "<tr><td>Download filename</td><td><input type='text' name='download_name' placeholder='&lt;assetname&gt;_YYYYMMDD_HHMM.docx (auto)' style='width:300px;'></td></tr>";

echo "<tr><td colspan='2' class='center' style='padding:12px;'>";
echo "<button type='submit' id='alpreport_submit' name='alpreport_action' value='generate' class='btn btn-primary'>Generate</button> ";
echo "&nbsp;<a class='btn btn-secondary' href='sample.php'>Download sample template</a>";
echo "<div id='alpreport_status' style='margin-top:10px;font-weight:bold;'></div>";
echo "</td></tr>";

echo "</table>";
echo "</form>";

// Submit via fetch so the page (and its reusable AJAX CSRF token) stays alive and
// several reports can be generated back to back without reloading.
echo Html::scriptBlock(<<<'JS'
(function () {
    var form = document.querySelector('form[enctype="multipart/form-data"]');
    var btn = document.getElementById('alpreport_submit');
    var status = document.getElementById('alpreport_status');
    if (!form) { return; }
    function setStatus(msg, ok) {
        status.textContent = msg;
        status.style.color = ok ? '#0a7c34' : '#b00020';
    }
    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var meta = document.querySelector('meta[property="glpi:csrf_token"]');
        var headers = { 'X-Requested-With': 'XMLHttpRequest' };
        if (meta) { headers['X-Glpi-Csrf-Token'] = meta.getAttribute('content'); }
        var fd = new FormData(form);
        var bulkPanel = document.querySelector('[id^="alpreport_bulk_panel_"]');
        if (form.querySelector('input[name=bulk_mode]').checked && bulkPanel && bulkPanel.alpreportGetSelected) {
            bulkPanel.alpreportGetSelected().forEach(function (k) { fd.append('bulk_items[]', k); });
        }
        var fileInput = form.querySelector('input[type=file]');
        btn.disabled = true;
        setStatus('Generating...', true);
        fetch(form.getAttribute('action') || window.location.href, {
            method: 'POST', body: fd, headers: headers, credentials: 'same-origin'
        }).then(function (resp) {
            if (!resp.ok) {
                return resp.text().then(function (txt) {
                    var msg = 'Request failed (' + resp.status + ').';
                    try { msg = JSON.parse(txt).error || msg; } catch (e) {}
                    throw new Error(msg);
                });
            }
            var cd = resp.headers.get('Content-Disposition') || '';
            var m = /filename="([^"]+)"/.exec(cd);
            var name = m ? m[1] : 'report.docx';
            var tpl = resp.headers.get('X-Alpreport-Template');
            var failed = resp.headers.get('X-Alpreport-Failed');
            return resp.blob().then(function (blob) {
                var a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = name;
                document.body.appendChild(a);
                a.click();
                setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
                if (tpl) {
                    tpl = decodeURIComponent(tpl);
                    // Uploaded template is now stored: use it from here on instead of re-uploading.
                    if (fileInput) { fileInput.value = ''; }
                    var hidden = form.querySelector('input[type=hidden][name=existing_template]');
                    if (hidden) {
                        hidden.value = tpl;
                    } else {
                        var list = document.getElementById('alpreport_template_list');
                        var radio = document.createElement('input');
                        radio.type = 'radio'; radio.name = 'existing_template'; radio.value = tpl; radio.checked = true;
                        var label = document.createElement('label');
                        label.style.cssText = 'display:flex;align-items:center;gap:8px;padding:6px 10px;border-top:1px solid #eee;';
                        var span = document.createElement('span');
                        span.style.cssText = 'flex:1;font-family:monospace;font-size:0.95em;';
                        span.textContent = tpl;
                        label.appendChild(radio); label.appendChild(span);
                        if (list) { list.appendChild(label); }
                    }
                }
                setStatus('Done: ' + name + (failed ? ' (' + failed + ' asset(s) failed, see errors.txt in ZIP)' : ''), !failed);
            });
        }).catch(function (err) {
            setStatus(err.message, false);
        }).then(function () {
            btn.disabled = false;
        });
    });
})();
JS
);

echo "<div style='max-width:820px;margin:14px auto;text-align:left;'>";
echo "<h3>Available placeholders (v2 template)</h3>";
echo "<ul>";
echo "<li><b>Asset</b>: {{asset_name}}, {{asset_hostname}}, {{asset_serial}}, {{asset_otherserial}}, {{asset_uuid}}, {{asset_comment}}, {{asset_contact}}, {{asset_contact_num}}, {{asset_date_creation}}, {{asset_date_mod}}</li>";
echo "<li><b>Resolved dropdowns</b>: {{asset_location}}, {{asset_state}}, {{asset_manufacturer}}, {{asset_model}}, {{asset_type}}, {{asset_os}}, {{asset_group}}, {{asset_entity}}, {{asset_domain}}, {{asset_network}}</li>";
echo "<li><b>User</b>: {{asset_user_name}}, {{asset_user_login}}, {{asset_user_realname}}, {{asset_user_email}}, {{asset_user_phone}}</li>";
echo "<li><b>Network</b>: {{asset_ip}}, {{asset_mac}}</li>";
echo "<li><b>Network port columns</b>: {{network_ports_name}}, {{network_ports_port_number}}, {{network_ports_logical}}, {{network_ports_type}}, {{network_ports_mac}}, {{network_ports_ip}}, {{network_ports_vlan}}, {{network_ports_speed}}</li>";
echo "<li><b>Printer port columns</b>: {{printer_ports}}, {{printer_ports_available}} (Ja/Nein)</li>";
echo "<li><b>Processor columns</b>: {{components_processor_manufacturer}}, {{components_processor_model}}, {{components_processor_cores}}, {{components_processor_frequency}}, {{components_processor_threads}}</li>";
echo "<li><b>Memory columns</b>: {{components_memory_manufacturer}}, {{components_memory_model}}, {{components_memory_size}}, {{components_memory_frequency}}, {{components_memory_type}}</li>";
echo "<li><b>Hard drive columns</b>: {{components_harddrive_manufacturer}}, {{components_harddrive_model}}, {{components_harddrive_size}}, {{components_harddrive_type}}</li>";
echo "<li><b>Network card columns</b>: {{components_networkcard_manufacturer}}, {{components_networkcard_model}}, {{components_networkcard_mac}}, {{components_networkcard_flow}}</li>";
echo "<li><b>Network card + ports (one row per port)</b>: {{networkcard_ports_manufacturer}}, {{networkcard_ports_model}}, {{networkcard_ports_flow}}, {{networkcard_ports_port_number}}, {{networkcard_ports_port_name}}, {{networkcard_ports_port_type}}, {{networkcard_ports_port_mac}}, {{networkcard_ports_port_ip}}, {{networkcard_ports_port_vlan}}, {{networkcard_ports_port_speed}}</li>";
echo "<li><b>Graphic card columns</b>: {{components_graphiccard_manufacturer}}, {{components_graphiccard_model}}, {{components_graphiccard_memory}}, {{components_graphiccard_interface}}, {{components_graphiccard_comment}}</li>";
echo "<li><b>Monitor columns</b>: {{monitors_manufacturer}}, {{monitors_model}}, {{monitors_size}}, {{monitors_type}}, {{monitors_serial}} (alias: {{monitors_serial_number}}), {{monitors_count}}</li>";
echo "<li><b>Component counts</b>: {{components_processor_count}}, {{components_memory_count}}, {{components_harddrive_count}}, {{components_networkcard_count}}</li>";
echo "<li><b>General tables</b>: {{network_ports}}, {{software}}, {{rack_items}}</li>";
echo "<li><b>Any DB field</b>: {{field_<columnname>}} (e.g. {{field_contact}})</li>";
echo "<li><b>Any FK</b>: {{hardware_<fieldname>_id}} or {{hardware_<fieldname>}} (resolved name)</li>";
echo "<li><b>Misc</b>: {{generated_at}}</li>";
echo "</ul>";
echo "<p style='color:#666;font-size:0.9em;margin-top:10px;'><b>Note:</b> Component and monitor tables are row-based in v2. Put the placeholders in a single template row, and the renderer duplicates that row once per item.</p>";
echo "</div>";

echo "</div>";

Html::footer();
