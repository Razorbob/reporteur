<?php

include('../../../inc/includes.php');

Session::checkLoginUser();

$tmp = tempnam(sys_get_temp_dir(), 'alpreport_sample_');
if ($tmp === false) {
    Html::displayErrorAndDie('Could not create temporary file.');
}
$docxPath = $tmp . '.docx';
@rename($tmp, $docxPath);

$contentTypes = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
</Types>
XML;

$rootRels = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>
XML;

$documentRels = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>
XML;

$lines = [
    ['heading', 'Asset Information Sheet'],
    ['kv', 'Asset type:',     '{{asset_type}}'],
    ['kv', 'Name:',           '{{asset_name}}'],
    ['kv', 'ID:',             '{{asset_id}}'],
    ['kv', 'Serial:',         '{{asset_serial}}'],
    ['kv', 'Inventory #:',    '{{asset_otherserial}}'],
    ['kv', 'UUID:',           '{{asset_uuid}}'],
    ['kv', 'Manufacturer:',   '{{asset_manufacturer}}'],
    ['kv', 'Model:',          '{{asset_model}}'],
    ['kv', 'Hardware type:',  '{{asset_type}}'],
    ['kv', 'Operating system:', '{{asset_os}}'],
    ['kv', 'State:',          '{{asset_state}}'],
    ['kv', 'Location:',       '{{asset_location}}'],
    ['kv', 'Entity:',         '{{asset_entity}}'],
    ['kv', 'Group:',          '{{asset_group}}'],
    ['kv', 'Domain:',         '{{asset_domain}}'],
    ['kv', 'Network:',        '{{asset_network}}'],
    ['kv', 'IP:',             '{{asset_ip}}'],
    ['kv', 'MAC:',            '{{asset_mac}}'],
    ['kv', 'Contact:',        '{{asset_contact}}'],
    ['kv', 'Contact #:',      '{{asset_contact_num}}'],
    ['kv', 'Comment:',        '{{asset_comment}}'],
    ['kv', 'Created:',        '{{asset_date_creation}}'],
    ['kv', 'Last updated:',   '{{asset_date_mod}}'],
    ['blank'],
    ['heading', 'Operating System'],
    ['kv', 'Name:',          '{{asset_os}}'],
    ['kv', 'Version:',       '{{asset_os_version}}'],
    ['kv', 'Service pack:',  '{{asset_os_servicepack}}'],
    ['kv', 'Kernel:',        '{{asset_os_kernel}}'],
    ['kv', 'Architecture:',  '{{asset_os_architecture}}'],
    ['kv', 'Edition:',       '{{asset_os_edition}}'],
    ['kv', 'Product key:',   '{{asset_os_serial}}'],
    ['blank'],
    ['heading', 'Assigned User'],
    ['kv', 'Name:',  '{{asset_user_name}}'],
    ['kv', 'Login:', '{{asset_user_login}}'],
    ['kv', 'Email:', '{{asset_user_email}}'],
    ['kv', 'Phone:', '{{asset_user_phone}}'],
    ['blank'],
    ['heading', 'Components'],
    ['kv', 'Processors:',          '{{components_processor}}'],
    ['kv', 'Processor serials:',   '{{components_processor_serial}}'],
    ['kv', 'Memory:',              '{{components_memory}}'],
    ['kv', 'Memory serials:',      '{{components_memory_serial}}'],
    ['kv', 'Hard drives:',         '{{components_harddrive}}'],
    ['kv', 'Hard drive serials:',  '{{components_harddrive_serial}}'],
    ['kv', 'Network cards:',       '{{components_networkcard}}'],
    ['kv', 'Network card serials:', '{{components_networkcard_serial}}'],
    ['kv', 'Graphic cards:',       '{{components_graphiccard}}'],
    ['kv', 'Sound cards:',         '{{components_soundcard}}'],
    ['kv', 'Motherboard:',         '{{components_motherboard}}'],
    ['kv', 'Power supply:',        '{{components_powersupply}}'],
    ['kv', 'Drives:',              '{{components_drive}}'],
    ['kv', 'PCI cards:',           '{{components_pci}}'],
    ['kv', 'Cases:',               '{{components_case}}'],
    ['kv', 'Battery:',             '{{components_battery}}'],
    ['kv', 'Firmware:',            '{{components_firmware}}'],
    ['kv', 'Simcards:',            '{{components_simcard}}'],
    ['kv', 'Sensors:',             '{{components_sensor}}'],
    ['kv', 'Generic:',             '{{components_generic}}'],
    ['kv', 'Cameras:',             '{{components_camera}}'],
    ['blank'],
    ['kv', 'All components:', '{{components}}'],
    ['blank'],
    ['heading', 'Network Ports'],
    ['kv', 'Port count:', '{{network_ports_count}}'],
    ['kv', 'Ports:',      '{{network_ports}}'],
    ['blank'],
    ['heading', 'Rack Mount'],
    ['kv', 'Rack:',         '{{asset_rack}}'],
    ['kv', 'Position (U):', '{{asset_rack_position}}'],
    ['kv', 'Orientation:',  '{{asset_rack_orientation}}'],
    ['kv', 'Rack location:', '{{asset_rack_location}}'],
    ['blank'],
    ['heading', 'Rack Information (when asset is a Rack)'],
    ['kv', 'Size (units):',     '{{rack_size}}'],
    ['kv', 'Used units:',       '{{rack_used_units}}'],
    ['kv', 'Datacenter room:',  '{{rack_room}}'],
    ['kv', 'Room position:',    '{{rack_room_position}}'],
    ['kv', 'Max power (W):',    '{{rack_max_power}}'],
    ['kv', 'Measured power:',   '{{rack_mesured_power}}'],
    ['kv', 'Max weight (kg):',  '{{rack_max_weight}}'],
    ['kv', 'Measured weight:',  '{{rack_mesured_weight}}'],
    ['kv', 'Mounted items:',    '{{rack_items_count}}'],
    ['kv', 'Mounted list:',     '{{rack_items}}'],
    ['blank'],
    ['heading', 'Software Inventory'],
    ['kv', 'Software count:',  '{{software_count}}'],
    ['kv', 'Installed software:', '{{software}}'],
    ['kv', 'License serials:',    '{{software_serial}}'],
    ['blank'],
    ['kv', 'Generated at:',   '{{generated_at}}'],
];

$body = '';
foreach ($lines as $line) {
    if ($line[0] === 'heading') {
        $title = htmlspecialchars($line[1], ENT_XML1, 'UTF-8');
        $body .= '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr>'
            . '<w:r><w:rPr><w:b/><w:sz w:val="32"/></w:rPr><w:t xml:space="preserve">' . $title . '</w:t></w:r></w:p>';
    } elseif ($line[0] === 'blank') {
        $body .= '<w:p></w:p>';
    } else {
        $label = htmlspecialchars($line[1], ENT_XML1, 'UTF-8');
        $value = htmlspecialchars($line[2], ENT_XML1, 'UTF-8');
        $body .= '<w:p>'
            . '<w:r><w:rPr><w:b/></w:rPr><w:t xml:space="preserve">' . $label . ' </w:t></w:r>'
            . '<w:r><w:t xml:space="preserve">' . $value . '</w:t></w:r>'
            . '</w:p>';
    }
}

$documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
    . '<w:body>' . $body
    . '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="720" w:footer="720" w:gutter="0"/></w:sectPr>'
    . '</w:body></w:document>';

$zip = new ZipArchive();
if ($zip->open($docxPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    Html::displayErrorAndDie('Could not build sample DOCX.');
}
$zip->addFromString('[Content_Types].xml', $contentTypes);
$zip->addFromString('_rels/.rels', $rootRels);
$zip->addFromString('word/_rels/document.xml.rels', $documentRels);
$zip->addFromString('word/document.xml', $documentXml);
$zip->close();

// Discard any output (BOMs, warnings, GLPI bootstrap output) that might have been
// buffered, otherwise it gets prepended to the binary DOCX and corrupts the file.
while (ob_get_level() > 0) {
    ob_end_clean();
}

if (!is_file($docxPath) || filesize($docxPath) === 0) {
    @unlink($docxPath);
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Failed to build sample DOCX (empty file).';
    exit;
}

// Sanity-check: a real DOCX must start with the ZIP signature "PK\x03\x04".
$fp = fopen($docxPath, 'rb');
$magic = $fp ? fread($fp, 4) : '';
if ($fp) {
    fclose($fp);
}
if ($magic !== "PK\x03\x04") {
    @unlink($docxPath);
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Failed to build sample DOCX (bad ZIP signature: 0x' . bin2hex($magic) . ').';
    exit;
}

header('Content-Description: File Transfer');
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="alpreport_sample_template.docx"');
header('Content-Transfer-Encoding: binary');
header('Content-Length: ' . filesize($docxPath));
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: public');
header('Expires: 0');
readfile($docxPath);
@unlink($docxPath);
exit;
