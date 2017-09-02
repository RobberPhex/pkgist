<?php

namespace App;

use Amp\Artax\Client;
use Amp\Artax\DefaultClient;
use Amp\Artax\Response;
use Amp\Coroutine;
use Amp\File;
use Amp\File\Handle;
use Amp\Redis\Client as RedisClient;
use Amp\Redis\Redis;
use Symfony\Component\Yaml\Yaml;

class App
{
    protected $storage_path;
    protected $config;
    private $url = 'https://packagist.org/';

    private $providers_url;

    /** @var  Client */
    private $client;

    /** @var  Redis */
    private $redisClient;

    public function __construct($path, $storage_path)
    {
        $this->config = Yaml::parse(file_get_contents($path));
        $this->storage_path = $storage_path;
    }

    public function process()
    {
        $this->client = new DefaultClient();
        $this->client->setOption(Client::OP_TRANSFER_TIMEOUT, 100 * 1000);
        $this->redisClient = new RedisClient('tcp://localhost:6379');

        /** @var Response $response */
        $response = yield $this->client->request($this->url . 'packages.json');

        $body = yield $response->getBody();
        $root_provider = \GuzzleHttp\json_decode($body, true);

        $this->providers_url = $root_provider['providers-url'];

        $promises = [];
        foreach ($root_provider['provider-includes'] as $url => &$sha256_arr) {
            $sha256 = $sha256_arr['sha256'];
            $promises[$sha256] = new Coroutine($this->processProviders($url, $sha256));
        }
        $sha256_map = yield $promises;

        foreach ($root_provider['provider-includes'] as $url => &$sha256_arr) {
            $sha256 = $sha256_arr['sha256'];
            $new_sha256 = $sha256_map[$sha256];
            $sha256_arr['sha256'] = $new_sha256;
        }

        $new_content = \GuzzleHttp\json_encode($root_provider, JSON_PRETTY_PRINT);
        $path = $this->storage_path . '/packages.json';

        yield from self::file_put_contents($path, $new_content);

        return $new_content;
    }

    public function processProviders($url, $sha256)
    {
        $cache_path = $this->storage_path . "/cache/" . substr($sha256, 0, 2) . '/' . $sha256;
        $new_sha256 = yield from self::file_get_contents($cache_path);
        if ($new_sha256)
            return $new_sha256;

        $o_url = $url;
        $url = str_replace('%hash%', $sha256, $url);
        $url = $this->url . $url;
        /** @var Response $response */
        $response = yield $this->client->request($url);

        $body = yield $response->getBody();
        $providers = \GuzzleHttp\json_decode($body, true);

        $total = count($providers['providers']);
        $i = 0;
        foreach ($providers['providers'] as $pkg_name => &$sha256_arr) {
            $i++;
            if ($i % 100 == 0)
                echo "$i/$total" . PHP_EOL;
            $sha256 = $sha256_arr['sha256'];
            $new_sha256 = yield from $this->processProvider($pkg_name, $sha256);
            $sha256_arr['sha256'] = $new_sha256;
        }

        $new_content = \GuzzleHttp\json_encode($providers, JSON_PRETTY_PRINT);
        $new_sha256 = hash('sha256', $new_content);
        $o_url = str_replace('%hash%', $new_sha256, $o_url);
        $path = $this->storage_path . "/" . $o_url;

        yield from self::file_put_contents($path, $new_content);
        yield from self::file_put_contents($cache_path, $new_sha256);
        gc_collect_cycles();

        return $new_sha256;
    }

    public function processProvider($pkg_name, $sha256)
    {
        $cache_path = $this->storage_path . "/cache/" . substr($sha256, 0, 2) . '/' . $sha256;
        $new_sha256 = yield from self::file_get_contents($cache_path);
        if ($new_sha256)
            return $new_sha256;

        $url = $this->providers_url;
        $url = str_replace('%package%', $pkg_name, $url);
        $url = str_replace('%hash%', $sha256, $url);
        $url = $this->url . $url;

        /** @var Response $response */
        $response = yield $this->client->request($url);

        $body = yield $response->getBody();
        $packages = \GuzzleHttp\json_decode($body, true);

        foreach ($packages['packages'] as $pkg_name => &$versions) {
            foreach ($versions as $version => &$version_data) {
                if (isset($version_data['dist'])) {
                    $reference = $version_data['dist']['reference'];
                    if (empty($reference))
                        $reference = hash('sha256', $version_data['dist']['url']);

                    yield $this->redisClient->hSet($pkg_name, $reference, $version_data['dist']['url']);

                    $version_data['dist']['url'] = $this->config['base_url'] . '/file/' . $pkg_name . '/' . $reference . '.' . $version_data['dist']['type'];
                }
            }
        }
        $new_content = \GuzzleHttp\json_encode($packages, JSON_PRETTY_PRINT);
        $new_sha256 = hash('sha256', $new_content);
        $path = $this->storage_path . "/p/$pkg_name\$$new_sha256.json";

        yield from self::file_put_contents($path, $new_content);
        yield from self::file_put_contents($cache_path, $new_sha256);
        gc_collect_cycles();

        return $new_sha256;
    }

    static public function file_put_contents($path, $content)
    {
        $dir = dirname($path);
        if (!is_dir($dir))
            yield File\mkdir($dir, 0777, true);
        /** @var Handle $handle */
        $handle = yield File\open($path, 'w+');
        yield $handle->write($content);
        yield $handle->close();
    }

    static public function file_get_contents($path)
    {
        $is_exist = yield File\exists($path);
        if (!$is_exist)
            return false;
        $handle = yield File\open($path, 'r');
        /** @var Handle $handle */
        return yield $handle->read();
    }

}
