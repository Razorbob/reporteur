<?php

class PluginAlpreportTemplateData
{
    public CommonDBTM $asset;

    /** @var array<string,array<string,mixed>> */
    public array $components = [];

    /** @var array<int,array<string,string>> */
    public array $monitors = [];

    /** @var array{lines:string[],rows:array<int,array<string,string>>} */
    public array $networkPorts = ['lines' => [], 'rows' => []];

    /** @var array{hostname:string,ip:string,mac:string} */
    public array $primaryNetwork = ['hostname' => '', 'ip' => '', 'mac' => ''];

    /** @var array<int,array{name:string,version:string,serial:string}> */
    public array $software = [];

    /** @var array{rack:string,position:string,orientation:string,location:string} */
    public array $rackMount = ['rack' => '', 'position' => '', 'orientation' => '', 'location' => ''];

    /** @var array<string,mixed> */
    public array $rackData = [];

    /** @var array{name:string,version:string,servicepack:string,kernel:string,architecture:string,edition:string,serial:string,license_id:string} */
    public array $operatingSystem = [
        'name' => '',
        'version' => '',
        'servicepack' => '',
        'kernel' => '',
        'architecture' => '',
        'edition' => '',
        'serial' => '',
        'license_id' => '',
    ];

    /** @var string[] */
    public array $groups = [];

    /** @var string[] */
    public array $domains = [];

    public function __construct(CommonDBTM $asset)
    {
        $this->asset = $asset;
    }
}
