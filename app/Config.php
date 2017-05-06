<?php

namespace App;

use Symfony\Component\Yaml\Yaml;

class Config
{
    public $base_url;
    public $host;
    public $port;
    public $storage_dir;

    public function __construct($path)
    {
        $content = Yaml::parse(file_get_contents($path));
        $this->base_url = $content['base_url'];
        $this->host = $content['host']??'';
        $this->port = $content['port']??1337;
        $this->storage_dir = __DIR__ . '/../storage/';
    }
}