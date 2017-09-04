<?php

namespace App;

use Amp\Artax\Client;
use Amp\Artax\DefaultClient;
use Amp\Artax\Response;
use Amp\Coroutine;
use Amp\File;
use Amp\File\Handle;
use Amp\Process\Process;
use Amp\Redis\Client as RedisClient;
use Amp\Redis\Redis;
use DateTime;
use DateTimeZone;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Yaml\Yaml;
use function GuzzleHttp\json_encode;

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

    /** @var  LoggerInterface */
    private $logger;

    public function __construct($path, $storage_path)
    {
        $this->config = Yaml::parse(file_get_contents($path));
        $this->storage_path = $storage_path;

        $this->logger = new Logger('name');
        $this->logger->pushHandler(new StreamHandler('/var/log/pkgist.log', Logger::WARNING));

        $this->client = new DefaultClient();
        $this->client->setOption(Client::OP_TRANSFER_TIMEOUT, 100 * 1000);
        $this->redisClient = new RedisClient($this->config['redis']);
    }

    public function process()
    {
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

        $root_provider['notify'] = 'https://packagist.org/downloads/%package%';
        $root_provider['notify-batch'] = 'https://packagist.org/downloads/';
        $root_provider['search'] = 'https://packagist.org/search.json?q=%query%&type=%type%';

        $date = (new DateTime('now', new DateTimeZone('Asia/Shanghai')))->format(DateTime::ISO8601);
        $root_provider['sync-time'] = $date;
        $new_content = json_encode($root_provider);
        $path = $this->storage_path . '/packages.json';

        yield from self::file_put_contents($path, $new_content);
    }

    public function processProviders($url, $sha256)
    {
        $this->logger->debug("processing $url with sha256 $sha256");
        $new_sha256 = yield $this->redisClient->hGet('hashmap', $sha256);
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
        $output = new ConsoleOutput();
        $progress = new ProgressBar($output, $total);

        foreach ($providers['providers'] as $pkg_name => &$sha256_arr) {
            $provider_sha256 = $sha256_arr['sha256'];
            $new_sha256 = yield from $this->processProvider($pkg_name, $provider_sha256);
            $sha256_arr['sha256'] = $new_sha256;

            $progress->advance();
        }

        $new_content = json_encode($providers);
        $new_sha256 = hash('sha256', $new_content);
        $o_url = str_replace('%hash%', $new_sha256, $o_url);
        $path = $this->storage_path . "/" . $o_url;

        yield from self::file_put_contents($path, $new_content);
        yield $this->redisClient->hSet('hashmap', $sha256, $new_sha256);

        $this->logger->debug("processed $url with new sha256 $new_sha256");
        return $new_sha256;
    }

    public function processProvider($pkg_name, $sha256)
    {
        $this->logger->debug("processing $pkg_name with sha256 $sha256");
        $new_sha256 = yield $this->redisClient->hGet('hashmap', $sha256);
        if ($new_sha256)
            return $new_sha256;
        $cache = true;

        $url = $this->providers_url;
        $url = str_replace('%package%', $pkg_name, $url);
        $url = str_replace('%hash%', $sha256, $url);
        $url = $this->url . $url;

        /** @var Response $response */
        $response = yield $this->client->request($url);

        $body = yield $response->getBody();
        $packages = \GuzzleHttp\json_decode($body, true);

        foreach ($packages['packages'] as $sub_pkg_name => &$versions) {
            foreach ($versions as $version => &$version_data) {
                if (isset($version_data['dist'])) {
                    $reference = $version_data['dist']['reference'];
                    if (empty($reference))
                        $reference = hash('sha256', $version_data['dist']['url']);

                    yield $this->redisClient->hSet('file', "$sub_pkg_name/$reference", $version_data['dist']['url']);

                    $version_data['dist']['url'] = $this->config['base_url'] . '/file/' . $sub_pkg_name . '/' . $reference . '.' . $version_data['dist']['type'];
                } else if (isset($version_data['source'])) {
                    if ($version_data['source']['type'] == 'git') {
                        $dir = "/tmp/" . hash('sha256', $version_data['source']['url']);

                        if (!is_dir($dir)) {
                            $cmd = "git clone " . $version_data['source']['url'] . " $dir";
                        } else {
                            $cmd = "git --git-dir=$dir/.git/ --work-tree=$dir fetch";
                        }
                        $process = new Process($cmd, null, ['GIT_ASKPASS' => 'echo']);
                        $process->start();
                        $process->getStdin()->close();
                        $code = yield $process->join();
                        if ($code != 0) {
                            $this->logger->error("$cmd error with code $code");
                            $this->logger->error(yield $process->getStderr()->read());
                            continue;
                        }

                        $cmd = "git --git-dir=$dir/.git/ --work-tree=$dir checkout -f " . $version_data['source']['reference'];
                        $process = new Process($cmd, null, ['GIT_ASKPASS' => 'echo']);
                        $process->start();
                        $process->getStdin()->close();
                        $code = yield $process->join();
                        if ($code != 0) {
                            $this->logger->error("$cmd error with code $code");
                            $this->logger->error(yield $process->getStderr()->read());
                            continue;
                        }

                        $args = [
                            'dir' => $this->storage_path . '/file/' . $sub_pkg_name,
                            'file' => $version_data['source']['reference'],
                            'format' => 'zip',
                            'working-dir' => $dir
                        ];
                        $cmd = 'composer archive';
                        foreach ($args as $name => $value) {
                            $cmd .= " --$name=$value";
                        }
                        $process = new Process($cmd);
                        $process->start();
                        $process->getStdin()->close();
                        $code = yield $process->join();
                        if ($code != 0) {
                            $this->logger->error("$cmd error with code $code");
                            $this->logger->error(yield $process->getStderr()->read());
                            continue;
                        }
                        $version_data['dist'] = [
                            'type' => 'zip',
                            'url' => $this->config['base_url'] . '/file/' . $sub_pkg_name . '/' . $version_data['source']['reference'] . '.zip',
                            'reference' => $version_data['source']['reference']
                        ];
                        $this->logger->debug("$version@$sub_pkg_name tared!");
                    } else {
                        $this->logger->error("$version@$sub_pkg_name is " . $version_data['source']['type'] . " project!");
                    }
                } else {
                    $this->logger->error("$version@$sub_pkg_name hasn't dist and source!!");
                    $cache = false;
                }
            }
        }
        $new_content = json_encode($packages, JSON_PRETTY_PRINT);
        $new_sha256 = hash('sha256', $new_content);
        $path = $this->storage_path . "/p/$pkg_name\$$new_sha256.json";

        yield from self::file_put_contents($path, $new_content);
        if ($cache)
            yield $this->redisClient->hSet('hashmap', $sha256, $new_sha256);

        $this->logger->debug("processed $pkg_name with new sha256 $new_sha256");
        return $new_sha256;
    }

    static public function file_put_contents($path, $content)
    {
        $dir = dirname($path);
        if (!is_dir($dir))
            yield File\mkdir($dir, 0777, true);
        /** @var Handle $handle */
        $handle = yield File\open($path . '.gz', 'w+');
        yield $handle->write(gzcompress($content, 9));
        yield $handle->close();
    }

    static public function file_get_contents($path)
    {
        $is_exist = yield File\exists($path);
        if (!$is_exist)
            return false;
        $handle = yield File\open($path . '.gz', 'r');
        /** @var Handle $handle */
        return gzuncompress(yield $handle->read());
    }
}
