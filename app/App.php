<?php

namespace App;

use Amp\Artax\Client;
use Amp\Artax\DefaultClient;
use Amp\Artax\Response;
use Amp\Coroutine;
use Amp\Delayed;
use Amp\File;
use Amp\Parallel\Sync\Lock;
use Amp\Parallel\Sync\Mutex;
use Amp\Process\Process;
use Amp\Redis\Client as RedisClient;
use Amp\Redis\Redis;
use DateTime;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;
use function GuzzleHttp\{
    json_decode, json_encode
};

class App
{
    protected $storage_path;
    protected $config;
    private $url = 'https://packagist.org/';

    private $providers_url;

    /** @var Mutex */
    private $mutex;

    /** @var  Client */
    private $client;

    /** @var  Redis */
    private $redisClient;

    /** @var  LoggerInterface */
    private $logger;

    public function __construct($path, $storage_path)
    {
        $this->mutex = new SimpleMutex();

        $this->config = Yaml::parse(file_get_contents($path));
        $this->storage_path = $storage_path;

        $this->logger = new Logger('pkgist');
        $this->logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));
        $this->logger->pushHandler(
            new RotatingFileHandler('/var/log/pkgist.log', 5, Logger::INFO)
        );

        $this->client = new DefaultClient();
        $this->client->setOption(Client::OP_TRANSFER_TIMEOUT, 100 * 1000);
        $this->redisClient = new RedisClient($this->config['redis']);
    }

    public function run()
    {
        yield from [
            new Coroutine($this->gc_loop()),
            new Coroutine($this->main_loop()),
        ];
    }

    public function gc_loop()
    {
        while (true) {
            yield new Delayed(6 * 60 * 60 * 1000);
            /** @var Lock $lock */
            $lock = yield $this->mutex->acquire();

            $this->logger->info("start gc");
            yield from $this->gc();
            $this->logger->info("end gc");

            $lock->release();
        }
    }

    public function purge($pkg)
    {
        /** @var Response $response */
        $response = yield $this->client->request($this->url . 'packages.json');

        $body = yield $response->getBody();
        $root_provider = json_decode($body, true);

        foreach ($root_provider['provider-includes'] as $path_tmpl => $sha256_arr) {
            $del = false;
            $sha256 = $sha256_arr['sha256'];
            $all[] = $sha256;

            $url = str_replace('%hash%', $sha256, $path_tmpl);
            $url = $this->url . $url;
            /** @var Response $response */
            $response = yield $this->client->request($url);

            $body = yield $response->getBody();
            $providers = json_decode($body, true);

            foreach ($providers['providers'] as $pkg_name => $sha256_arr) {
                $pkg_sha256 = $sha256_arr['sha256'];
                if ($pkg_name == $pkg) {
                    yield $this->redisClient->hDel('hashmap', $pkg_sha256);
                    $del = true;
                }
            }
            if ($del) {
                yield $this->redisClient->hDel('hashmap', $sha256);
                break;
            }
        }
    }

    public function gc()
    {
        $all = [];
        $all_dist = [];

        $this->logger->info("start collect!");
        $path = $this->storage_path . '/packages.json';
        $content = yield from self::file_get_contents($path);
        $root_provider = json_decode($content, true);
        $pkg_url_tmpl = $root_provider['providers-url'];

        foreach ($root_provider['provider-includes'] as $path_tmpl => $sha256_arr) {
            $sha256 = $sha256_arr['sha256'];
            $real_path = str_replace('%hash%', $sha256, $path_tmpl);
            $all[] = $sha256;

            $content = yield from self::file_get_contents($this->storage_path . $real_path);
            $sub_provider = json_decode($content, true);

            foreach ($sub_provider['providers'] as $pkg_name => $sha256_arr) {
                $sha256 = $sha256_arr['sha256'];
                $all[] = $sha256;

                $pkg_path = $pkg_url_tmpl;
                $pkg_path = str_replace('%package%', $pkg_name, $pkg_path);
                $pkg_path = str_replace('%hash%', $sha256, $pkg_path);

                $content = yield from self::file_get_contents($this->storage_path . $pkg_path);
                if (!$content)
                    continue;
                $pkg_provider = json_decode($content, true);
                foreach ($pkg_provider['packages'] as $sub_pkg_name => $data) {
                    foreach ($data as $version => $version_data) {
                        if (!empty($version_data['dist'])) {
                            $all_dist[] = $version_data['dist']['reference'];
                        }
                    }
                }
            }
        }

        $this->logger->info("clear redis hashmap!");
        $cursor = 0;
        $to_del = [];
        do {
            list($cursor, $keys) = yield $this->redisClient->hScan('hashmap', $cursor, null, 1000);
            $p_hashmap = [];
            for ($i = 0; $i < count($keys); $i += 2) {
                $p_hashmap[$keys[$i]] = $keys[$i + 1];
            }
            foreach ($p_hashmap as $key => $value) {
                if (!in_array($value, $all)) {
                    $to_del[] = $key;
                }
            }
        } while ($cursor != 0);

        foreach ($to_del as $k) {
            $this->logger->info("clear redis: hashmap $k");
            yield $this->redisClient->hDel('hashmap', $k);
        }
        unset($to_del);

        $this->logger->info("clear meta file!");
        $p_list = yield File\scandir($this->storage_path . '/p/');
        foreach ($p_list as $item) {
            $match_result = preg_match(
                '/^provider-(?:[^\$]+)\$(?P<hash>\w{64})\.json\.gz$/',
                $item, $matches);
            if ($match_result) {
                $sha256 = $matches['hash'];

                if (!in_array($sha256, $all)) {
                    $this->logger->info("clear file: " . $this->storage_path . "/p/$item");
                    yield File\unlink($this->storage_path . "/p/$item");
                } else {
                    $this->logger->debug("keep file: " . $this->storage_path . "/p/$item");
                }
            } elseif (yield File\isdir($this->storage_path . "/p/$item")) {
                $p_list = yield File\scandir($this->storage_path . "/p/$item");
                foreach ($p_list as $sub_item) {
                    $match_result = preg_match(
                        '/^[^\$]+\$(?P<hash>\w{64})\.json\.gz$/',
                        $sub_item, $matches);
                    if ($match_result) {
                        $sha256 = $matches['hash'];
                        if (!in_array($sha256, $all)) {
                            $this->logger->debug("clear file: " . $this->storage_path . "/p/$item/$sub_item");
                            yield File\unlink($this->storage_path . "/p/$item/$sub_item");
                        } else {
                            $this->logger->debug("keep file: " . $this->storage_path . "/p/$item/$sub_item");
                        }
                    } else {
                        $this->logger->debug("ignore file: " . $this->storage_path . "/p/$item/$sub_item");
                    }
                }
            } else {
                $this->logger->debug("ignore file: " . $this->storage_path . "/p/$item");
            }
        }
        unset($all);

        $this->logger->info("clear redis file!");
        $cursor = 0;
        $to_del_file = [];
        do {
            list($cursor, $keys) = yield $this->redisClient->hScan('file', $cursor, null, 1000);
            $p_hashmap = [];
            for ($i = 0; $i < count($keys); $i += 2) {
                $p_hashmap[$keys[$i]] = $keys[$i + 1];
            }
            foreach ($p_hashmap as $key => $value) {
                $reference = explode('/', $key)[2];
                if (!in_array($reference, $all_dist)) {
                    $to_del_file[] = $key;
                }
            }
        } while ($cursor != 0);

        foreach ($to_del_file as $k) {
            $this->logger->info("clear redis: file $k");
            yield $this->redisClient->hDel('file', $k);
        }
        unset($to_del_file);

        $this->logger->info("clear generated dist file!");
        $p_list = yield File\scandir($this->storage_path . '/file/');
        foreach ($p_list as $vendor) {
            if (yield File\isdir($this->storage_path . "/file/$vendor")) {
                continue;
            }
            $pkg_name_list = yield File\scandir($this->storage_path . "/file/$vendor");
            foreach ($pkg_name_list as $pkg_name) {
                if (yield File\isdir($this->storage_path . "/file/$vendor/$pkg_name")) {
                    continue;
                }
                $file_list = yield File\scandir($this->storage_path . "/file/$vendor/$pkg_name");
                foreach ($file_list as $file) {
                    $match_result = preg_match(
                        '/^(?P<reference>.*)\.zip$/',
                        $file, $matches);
                    if ($match_result) {
                        $reference = $matches['reference'];
                        if (!in_array($reference, $all_dist)) {
                            $this->logger->debug(
                                "clear file: " . $this->storage_path . "/file/$vendor/$pkg_name/$reference.zip"
                            );
                            yield File\unlink($this->storage_path . "/file/$vendor/$pkg_name/$reference.zip");
                        } else {
                            $this->logger->debug(
                                "keep file: " . $this->storage_path . "/file/$vendor/$pkg_name/$reference.zip"
                            );
                        }
                    } else {
                        $this->logger->debug("ignore file: " . $this->storage_path . "/file/$vendor/$pkg_name/$file");
                    }
                }
            }
        }
    }

    public function main_loop()
    {
        while (true) {
            /** @var Lock $lock */
            $lock = yield $this->mutex->acquire();

            $success = true;
            $this->logger->debug("start process");
            try {
                yield from $this->process();
            } catch (\Throwable $e) {
                $this->logger->err($e);
                $success = false;
            }
            $this->logger->debug("end process");

            $lock->release();

            if ($success) {
                $this->logger->info("sync completed!");
            } else {
                $this->logger->info("sync failed!");
            }

            yield new Delayed(2 * 1000);
        }
    }

    public function process()
    {
        /** @var Response $response */
        $response = yield $this->client->request($this->url . 'packages.json');

        $body = yield $response->getBody();
        $root_provider = json_decode($body, true);

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

        $date = (new DateTime('now'))->format(DateTime::ISO8601);
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
        $providers = json_decode($body, true);

        $total = count($providers['providers']);
        $processed = 0;

        $coroutines = [];
        foreach ($providers['providers'] as $pkg_name => &$sha256_arr) {
            $provider_sha256 = $sha256_arr['sha256'];
            $coroutines[$pkg_name] = new Coroutine($this->processProvider($pkg_name, $provider_sha256));

            $processed += 1;
            if ($processed % 4 == 0) {
                $pkg_hash = yield $coroutines;
                foreach ($pkg_hash as $pkg => $hash) {
                    $providers['providers'][$pkg]['sha256'] = $hash;
                }
                $coroutines = [];
            }
            if ($processed % 100 == 0)
                $this->logger->info("processed $processed/$total@$o_url");
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
        $tmps = [];
        $cache = true;

        $url = $this->providers_url;
        $url = str_replace('%package%', $pkg_name, $url);
        $url = str_replace('%hash%', $sha256, $url);
        $url = $this->url . $url;

        /** @var Response $response */
        $response = yield $this->client->request($url);

        $body = yield $response->getBody();
        $packages = json_decode($body, true);

        foreach ($packages['packages'] as $sub_pkg_name => &$versions) {
            foreach ($versions as $version => &$version_data) {
                if (isset($version_data['dist'])) {
                    $reference = $version_data['dist']['reference'];
                    if (empty($reference))
                        $reference = hash('sha256', $version_data['dist']['url']);

                    yield $this->redisClient->hSet(
                        'file',
                        "$sub_pkg_name/$reference", $version_data['dist']['url']
                    );

                    $version_data['dist']['url'] =
                        $this->config['base_url'] . '/file/'
                        . $sub_pkg_name . '/' . $reference . '.' . $version_data['dist']['type'];
                } elseif (isset($version_data['source'])) {
                    if ($version_data['source']['type'] == 'git') {
                        $dir = "/tmp/" . hash('sha256', $version_data['source']['url']);
                        $reference = $version_data['source']['reference'];

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
                            if (!in_array($dir, $tmps))
                                $tmps[] = $dir;
                            $this->logger->error("$cmd error with code $code");
                            $this->logger->error(yield $process->getStderr()->read());
                            continue;
                        }
                        $cmd = "git --git-dir=$dir/.git/ archive "
                            . "--output=" . $this->storage_path . "/file/$sub_pkg_name/$reference.zip $reference";
                        $process = new Process($cmd, null, ['GIT_ASKPASS' => 'echo']);
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
                            'reference' => $reference
                        ];
                        $this->logger->debug("$version@$sub_pkg_name tared!");
                    } else {
                        $this->logger->error(
                            "$version@$sub_pkg_name is " . $version_data['source']['type'] . " project!"
                        );
                        $cache = false;
                    }
                } else {
                    $this->logger->error("$version@$sub_pkg_name hasn't dist and source!!");
                }
            }
        }
        $new_content = json_encode($packages);
        $new_sha256 = hash('sha256', $new_content);
        $path = $this->storage_path . "/p/$pkg_name\$$new_sha256.json";

        yield from self::file_put_contents($path, $new_content);
        if ($cache)
            yield $this->redisClient->hSet('hashmap', $sha256, $new_sha256);

        $this->logger->debug("processed $pkg_name with new sha256 $new_sha256");
        foreach ($tmps as $tmp) {
            yield File\rmdir($tmp);
        }
        return $new_sha256;
    }

    static public function file_put_contents($path, $content)
    {
        $dir = dirname($path);
        if (!is_dir($dir))
            yield File\mkdir($dir, 0777, true);

        $content = zlib_encode($content, ZLIB_ENCODING_GZIP, 9);
        yield File\put($path . '.gz', $content);
    }

    static public function file_get_contents($path)
    {
        $is_exist = yield File\exists($path . '.gz');
        if (!$is_exist)
            return false;
        return zlib_decode(yield File\get($path . '.gz'));
    }
}
