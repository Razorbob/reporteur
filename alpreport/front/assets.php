<?php

include('../../../inc/includes.php');

/** @var DBmysql $DB */
global $DB;

Session::checkLoginUser();

require_once(__DIR__ . '/../inc/templateprocessor.class.php');

$query = trim((string)($_GET['q'] ?? ''));
$perType = 100;
$result = [];

foreach (PluginAlpreportTemplateProcessor::SUPPORTED_ASSET_TYPES as $type) {
    if (!class_exists($type) || !$type::canView()) {
        continue;
    }

    $table = getTableForItemType($type);
    $where = [
        'is_deleted'  => 0,
        'is_template' => 0,
    ] + getEntitiesRestrictCriteria($table);

    if ($query !== '') {
        $like = '%' . addcslashes($query, '%_\\') . '%';
        $where[] = ['OR' => array_filter([
            'name' => ['LIKE', $like],
            'id'   => ctype_digit($query) ? (int)$query : null,
        ], static fn($v) => $v !== null)];
    }

    $typeName = (new $type())->getTypeName(1);
    foreach ($DB->request([
        'SELECT' => ['id', 'name'],
        'FROM'   => $table,
        'WHERE'  => $where,
        'ORDER'  => 'name ASC',
        'LIMIT'  => $perType,
    ]) as $row) {
        $name = trim((string)$row['name']) !== '' ? (string)$row['name'] : '#' . $row['id'];
        $result[] = [
            'key'   => $type . ':' . (int)$row['id'],
            'label' => $name . ' (' . $typeName . ')',
        ];
    }
}

usort($result, static fn(array $a, array $b): int => strcasecmp($a['label'], $b['label']));

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode($result);
exit;
