<?php

class PluginAlpreportTemplateDataCollector
{
    /**
     * Collect all data needed by the three template modes.
     *
     * @param string[] $knownDeviceTypes
     * @param callable(string,int):(?string) $resolveForeignKey
     */
    public static function collect(
        CommonDBTM $item,
        array $knownDeviceTypes,
        callable $resolveForeignKey
    ): PluginAlpreportTemplateData
    {
        $data = new PluginAlpreportTemplateData($item);

        $data->components = PluginAlpreportComponentCollector::collectComponents($item, $knownDeviceTypes, $resolveForeignKey);
        $data->monitors = PluginAlpreportMonitorCollector::collectMonitors($item, $resolveForeignKey);
        $data->networkPorts = PluginAlpreportNetworkCollector::collectNetworkPorts($item);
        $data->primaryNetwork = PluginAlpreportNetworkCollector::collectPrimaryNetwork($item);
        $data->software = PluginAlpreportSoftwareCollector::collectSoftware($item);
        $data->rackMount = PluginAlpreportRackCollector::collectRackMount($item);
        $data->rackData = (class_exists('Rack') && $item instanceof Rack)
            ? PluginAlpreportRackCollector::collectRackData($item)
            : [];
        $data->operatingSystem = PluginAlpreportAssetMetadataCollector::collectOperatingSystem($item);
        $data->groups = PluginAlpreportAssetMetadataCollector::collectGroups($item, $resolveForeignKey);
        $data->domains = PluginAlpreportAssetMetadataCollector::collectDomains($item, $resolveForeignKey);

        return $data;
    }
}
