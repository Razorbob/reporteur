<?php

include('../../../inc/includes.php');

/** @var array $CFG_GLPI */
global $CFG_GLPI;

Session::checkLoginUser();

require_once __DIR__ . '/../inc/xlsximporter.class.php';

$formUrl = ($CFG_GLPI['root_doc'] ?? '') . '/plugins/alpimport/front/form.php';

if (empty($_SESSION['plugin_alpimport_csrf'])) {
    $_SESSION['plugin_alpimport_csrf'] = bin2hex(random_bytes(32));
}

$errorMessage = '';
$infoMessage = '';
$inspectionResult = null;
$importStats = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
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

        $submittedToken = (string) ($_POST['_alpimport_token'] ?? '');
        $expectedToken = (string) ($_SESSION['plugin_alpimport_csrf'] ?? '');
        if (
            $expectedToken === ''
            || $submittedToken === ''
            || !hash_equals($expectedToken, $submittedToken)
        ) {
            throw new RuntimeException('Invalid or expired security token. Please reload the page and try again.');
        }

        $selectedFile = trim((string) ($_POST['existing_file'] ?? ''));
        $fileLabel = '';

        if (!empty($_FILES['xlsx_file']['name'] ?? '')) {
            $selectedFile = PluginAlpimportXlsxImporter::saveUploadedWorkbook($_FILES['xlsx_file']);
            $fileLabel = basename($selectedFile);
        }

        if ($selectedFile === '') {
            throw new RuntimeException('Upload an .xlsx file or choose an existing one.');
        }

        $importStats = PluginAlpimportXlsxImporter::importStoredWorkbook($selectedFile);
        $inspectionResult = PluginAlpimportXlsxImporter::inspectStoredWorkbook($selectedFile);
        $fileLabel = $fileLabel === '' ? basename($selectedFile) : $fileLabel;
        $infoMessage = 'Workbook imported: ' . $fileLabel
            . ' (processed rows: ' . (int) ($importStats['processed_rows'] ?? 0)
            . ', created: ' . (int) ($importStats['created_computers'] ?? 0)
            . ', updated: ' . (int) ($importStats['updated_computers'] ?? 0) . ')';
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
        if (class_exists('Toolbox') && method_exists('Toolbox', 'logInFile')) {
            Toolbox::logInFile(
                'alpimport',
                $e->getMessage() . "\n" . $e->getTraceAsString(),
                true
            );
        }
    }
}

$existingFiles = PluginAlpimportXlsxImporter::listImports();

Html::header('Alp Import', $_SERVER['PHP_SELF'], 'tools', 'PluginAlpimportMenu');

echo "<div class='center'>";
echo "<h2>XLSX Import</h2>";

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

echo "<form method='post' enctype='multipart/form-data' class='tab_cadre_fixe' style='max-width:980px;padding:16px;'>";

$csrfToken = $_SESSION['plugin_alpimport_csrf'];
echo "<input type='hidden' name='_alpimport_token' value='" . htmlspecialchars($csrfToken, ENT_QUOTES) . "'>";
echo "<input type='hidden' name='_glpi_csrf_token' value='" . htmlspecialchars(Session::getNewCSRFToken(), ENT_QUOTES) . "'>";

echo "<table class='tab_cadre_fixe'>";
echo "<tr><th colspan='2'>Import workbook</th></tr>";
echo "<tr><td>Upload XLS/XLSX file</td><td><input type='file' name='xlsx_file' accept='.xlsx,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel'></td></tr>";
echo "<tr><td>Or choose existing file</td><td>";
if (empty($existingFiles)) {
    echo "<em>No uploaded workbooks yet.</em>";
    echo "<input type='hidden' name='existing_file' value=''>";
} else {
    echo "<select name='existing_file' class='form-select' style='width:420px;'>";
    echo "<option value=''>-- Select workbook --</option>";
    foreach ($existingFiles as $file) {
        $safeFile = htmlspecialchars($file, ENT_QUOTES);
        echo "<option value='$safeFile'>$safeFile</option>";
    }
    echo "</select>";
}
echo "</td></tr>";
echo "<tr><td colspan='2' class='center' style='padding:12px;'>";
echo "<button type='submit' name='alpimport_action' value='import' class='btn btn-primary'>Import XLSX</button>";
echo "</td></tr>";
echo "</table>";
echo "</form>";

echo "<div style='max-width:980px;margin:14px auto;text-align:left;'>";
echo "<h3>Importer status</h3>";
echo "<ul>";
echo "<li>Uploads are stored in <code>&lt;glpi-files&gt;/_plugins/alpimport/imports/</code>.</li>";
echo "<li>Current import target is <b>Computer</b> assets.</li>";
echo "<li>IP-like columns are imported as additional interface IP addresses.</li>";
echo "<li>Native import requires <code>.xlsx</code>; selecting <code>.xls</code> shows a conversion warning.</li>";
echo "</ul>";

if (is_array($importStats)) {
    echo "<h3>Import summary</h3>";
    echo "<table class='tab_cadre_fixehov' style='max-width:700px;'>";
    $rows = [
        'Processed rows' => (int) ($importStats['processed_rows'] ?? 0),
        'Skipped rows' => (int) ($importStats['skipped_rows'] ?? 0),
        'Computers created' => (int) ($importStats['created_computers'] ?? 0),
        'Computers updated' => (int) ($importStats['updated_computers'] ?? 0),
        'Groups created' => (int) ($importStats['created_groups'] ?? 0),
        'Group links created' => (int) ($importStats['linked_groups'] ?? 0),
        'Network ports created' => (int) ($importStats['created_networkports'] ?? 0),
        'Network names created' => (int) ($importStats['created_networknames'] ?? 0),
        'IP addresses created' => (int) ($importStats['created_ipaddresses'] ?? 0),
        'Software created' => (int) ($importStats['created_software'] ?? 0),
        'Software links created' => (int) ($importStats['linked_software'] ?? 0),
        'OS links created' => (int) ($importStats['created_os'] ?? 0),
        'OS links updated' => (int) ($importStats['updated_os'] ?? 0),
    ];
    foreach ($rows as $label => $value) {
        echo "<tr><td>" . Html::entities_deep($label) . "</td><td>" . $value . "</td></tr>";
    }
    echo "</table>";
}

if (is_array($inspectionResult)) {
    $sheetCount = (int) ($inspectionResult['sheet_count'] ?? 0);
    echo "<h3>Workbook preview</h3>";
    echo "<p><b>File:</b> " . Html::entities_deep((string) ($inspectionResult['file_name'] ?? '')) . "<br>";
    echo "<b>Sheets:</b> " . $sheetCount . "</p>";

    foreach (($inspectionResult['sheets'] ?? []) as $sheet) {
        $name = (string) ($sheet['name'] ?? '');
        $rowCount = (int) ($sheet['row_count'] ?? 0);
        $headers = $sheet['headers'] ?? [];
        $previewRows = $sheet['preview_rows'] ?? [];

        echo "<div style='border:1px solid #ddd;border-radius:4px;padding:10px;margin:10px 0;'>";
        echo "<h4 style='margin:0 0 6px 0;'>" . Html::entities_deep($name) . "</h4>";
        echo "<div><b>Rows:</b> " . $rowCount . "</div>";
        if (!empty($headers)) {
            echo "<div><b>Headers:</b> " . Html::entities_deep(implode(', ', $headers)) . "</div>";
        } else {
            echo "<div><b>Headers:</b> <em>none detected</em></div>";
        }

        if (!empty($previewRows)) {
            echo "<div style='margin-top:8px;'><b>Preview:</b></div>";
            echo "<table class='tab_cadre_fixehov' style='margin-top:6px;'>";
            foreach ($previewRows as $row) {
                echo "<tr>";
                foreach ($row as $cell) {
                    echo "<td>" . Html::entities_deep((string) $cell) . "</td>";
                }
                echo "</tr>";
            }
            echo "</table>";
        }
        echo "</div>";
    }
}

echo "</div>";
echo "</div>";

Html::footer();
