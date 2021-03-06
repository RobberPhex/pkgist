<?php

namespace App;

use Amp\Artax\Client;
use Amp\Artax\DefaultClient;
use Amp\Artax\Request;
use Amp\Artax\Response;
use Amp\Coroutine;
use Amp\Delayed;
use Amp\File;
use Amp\Sync\LocalMutex;
use Amp\Sync\Lock;
use Amp\Sync\Mutex;
use Amp\Process\Process;
use Amp\Redis\Client as RedisClient;
use Amp\Redis\Redis;
use Carbon\Carbon;
use DateTime;
use Monolog\Formatter\LineFormatter;
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
        $this->mutex = new LocalMutex();

        $this->config = Yaml::parse(file_get_contents($path));
        if (!isset($this->config['timezone']))
            $this->config['timezone'] = '';
        if (!isset($this->config['log']))
            $this->config['log'] = '/var/log/pkgist.log';

        $this->storage_path = $storage_path;

        $this->logger = new Logger('pkgist');
        $this->logger->setTimezone(new \DateTimeZone($this->config['timezone']));

        $handler = new StreamHandler('php://stdout', Logger::NOTICE);
        $handler->setFormatter(new LineFormatter(null, DateTime::ISO8601));
        $this->logger->pushHandler($handler);

        $handler = new RotatingFileHandler($this->config['log'], 5, Logger::INFO);
        $handler->setFormatter(new LineFormatter(null, DateTime::ISO8601));
        $this->logger->pushHandler($handler);

        $this->client = new DefaultClient();
        $this->client->setOption(Client::OP_TRANSFER_TIMEOUT, 100 * 1000);
        $this->client->setOption(
            Client::OP_DEFAULT_HEADERS,
            [
                'User-Agent' => sprintf(
                    'User-Agent: Composer/%s (%s; %s; %s)',
                    '1.5.6',
                    function_exists('php_uname') ? php_uname('s') : 'Unknown',
                    function_exists('php_uname') ? php_uname('r') : 'Unknown',
                    'PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION
                )
            ]);
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
            $now = Carbon::now($this->config['timezone']);
            $next = $now->copy()->setTime(3, 0);
            if ($now->greaterThan($next))
                $next->addDay();

            $delay = $now->diffInSeconds($next, false) * 1000;
            yield new Delayed($delay);

            /** @var Lock $lock */
            $lock = yield $this->mutex->acquire();

            $this->logger->notice("start gc");
            yield from $this->gc();
            $this->logger->notice("end gc");

            $lock->release();
        }
    }

    public function purge($pkgs)
    {
        /** @var Response $response */
        $response = yield $this->client->request($this->url . 'packages.json');

        $body = yield $response->getBody();
        $root_provider = json_decode($body, true);

        foreach ($root_provider['provider-includes'] as $path_tmpl => $provider_sha256_) {
            $provider_sha256 = $provider_sha256_['sha256'];
            $all[] = $provider_sha256;

            $provider_url = str_replace('%hash%', $provider_sha256, $path_tmpl);
            /** @var Response $response */
            $response = yield $this->client->request($this->url . $provider_url);

            $body = yield $response->getBody();
            $providers = json_decode($body, true);

            foreach ($providers['providers'] as $pkg_name => $pkg_sha256_) {
                $pkg_sha256 = $pkg_sha256_['sha256'];
                if (in_array($pkg_name, $pkgs)) {
                    $pkg_url = str_replace(
                        ['%package%', '%hash%'],
                        [$pkg_name, $pkg_sha256],
                        $root_provider['providers-url']
                    );
                    yield $this->redisClient->hDel('hashmap', $pkg_url);
                    yield $this->redisClient->hDel('hashmap', $provider_url);
                    $pkgs = array_diff($pkgs, array($pkg_name));
                    if (count($pkgs) == 0)
                        break;
                }
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

        foreach ($root_provider['provider-includes'] as $path_tmpl => $provider_sha256_) {
            $provider_sha256 = $provider_sha256_['sha256'];
            $real_path = str_replace('%hash%', $provider_sha256, $path_tmpl);
            $all[] = $provider_sha256;

            $content = yield from self::file_get_contents($this->storage_path . $real_path);
            $sub_provider = json_decode($content, true);

            foreach ($sub_provider['providers'] as $pkg_name => $pkg_sha256_) {
                $pkg_sha256 = $pkg_sha256_['sha256'];
                $all[] = $pkg_sha256;

                $pkg_path = $pkg_url_tmpl;
                $pkg_path = str_replace(['%package%', '%hash%'], [$pkg_name, $pkg_sha256], $pkg_path);

                $content = yield from self::file_get_contents($this->storage_path . $pkg_path);
                if (!$content)
                    continue;
                $pkg_provider = json_decode($content, true);
                foreach ($pkg_provider['packages'] as $sub_pkg_name => $data) {
                    foreach ($data as $version => $version_data) {
                        if (!empty($version_data['dist']) &&
                            !empty($version_data['dist']['generated']) && $version_data['dist']['generated']
                        ) {
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
            for ($i = 0; $i < count($keys); $i += 2) {
                if (!in_array($keys[$i + 1], $all)) {
                    $to_del[] = $keys[$i];
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
                            $this->logger->info("clear file: " . $this->storage_path . "/p/$item/$sub_item");
                            yield File\unlink($this->storage_path . "/p/$item/$sub_item");
                        }
                    } else {
                        yield File\unlink($this->storage_path . "/p/$item/$sub_item");
                    }
                }
            } else {
                yield File\unlink($this->storage_path . "/p/$item");
            }
        }
        unset($all);

        $this->logger->info("clear generated dist file!");
        $p_list = yield File\scandir($this->storage_path . '/file/');
        foreach ($p_list as $vendor) {
            if (!yield File\isdir($this->storage_path . "/file/$vendor")) {
                yield File\unlink($this->storage_path . "/file/$vendor");
                continue;
            }
            $pkg_name_list = yield File\scandir($this->storage_path . "/file/$vendor");
            foreach ($pkg_name_list as $pkg_name) {
                if (!yield File\isdir($this->storage_path . "/file/$vendor/$pkg_name")) {
                    yield File\unlink($this->storage_path . "/file/$vendor/$pkg_name");
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
                            $this->logger->info(
                                "clear file: " . $this->storage_path . "/file/$vendor/$pkg_name/$reference.zip"
                            );
                            yield File\unlink($this->storage_path . "/file/$vendor/$pkg_name/$reference.zip");
                        }
                    } else {
                        yield File\unlink($this->storage_path . "/file/$vendor/$pkg_name/$file");
                    }
                }
            }
        }
    }

    public function main_loop()
    {
        $lastModified = null;
        $etag = null;
        while (true) {
            /** @var Lock $lock */
            $lock = yield $this->mutex->acquire();

            $success = true;
            $this->logger->debug("start process");
            try {
                yield from $this->process($lastModified, $etag);
            } catch (\Throwable $e) {
                $this->logger->err($e);
                $success = false;
            }
            $this->logger->debug("end process");

            $lock->release();
            gc_collect_cycles();

            if ($success) {
                $this->logger->notice("sync completed!");
            } else {
                $this->logger->notice("sync failed!");
            }

            yield new Delayed(2 * 1000);
        }
    }

    public function process(&$lastModified, &$etag)
    {
        $request = new Request($this->url . 'packages.json');
        if (!empty($lastModified))
            $request = $request->withHeader('If-Modified-Since', $lastModified);
        if (!empty($etag))
            $request = $request->withHeader('If-None-Match', $etag);

        /** @var Response $response */
        $response = yield $this->client->request($request);
        if ($response->getStatus() == 304)
            return;
        $lastModified = $request->getHeader('Last-Modified');
        $etag = $request->getHeader('ETag');

        $body = yield $response->getBody();
        $root_provider = json_decode($body, true);

        $this->providers_url = $root_provider['providers-url'];

        $sha256_map = [];
        foreach ($root_provider['provider-includes'] as $url => &$sha256_arr) {
            $sha256 = $sha256_arr['sha256'];
            $sha256_map[$sha256] = yield from $this->processProviders($url, $sha256);
        }

        foreach ($root_provider['provider-includes'] as $url => &$sha256_arr) {
            $sha256 = $sha256_arr['sha256'];
            $new_sha256 = $sha256_map[$sha256];
            $sha256_arr['sha256'] = $new_sha256;
        }

        $root_provider['notify'] = 'https://packagist.org/downloads/%package%';
        $root_provider['notify-batch'] = 'https://packagist.org/downloads/';
        $root_provider['search'] = 'https://packagist.org/search.json?q=%query%&type=%type%';

        $root_provider['sync-time'] = Carbon::now($this->config['timezone'])->toIso8601String();
        $new_content = json_encode($root_provider);
        $path = $this->storage_path . '/packages.json';

        yield from self::file_put_contents($path, $new_content);
    }

    public function processProviders($url, $sha256)
    {
        $this->logger->debug("processing $url with sha256 $sha256");

        $o_url = $url;
        $url = str_replace('%hash%', $sha256, $url);

        $new_sha256 = yield $this->redisClient->hGet('hashmap', $url);
        if ($new_sha256)
            return $new_sha256;

        /** @var Response $response */
        $response = yield $this->client->request($this->url . $url);

        $body = yield $response->getBody();
        $providers = json_decode($body, true);

        $total = count($providers['providers']);
        $processed = 0;

        $coroutines = [];
        foreach ($providers['providers'] as $pkg_name => &$sha256_arr) {
            $provider_sha256 = $sha256_arr['sha256'];
            $coroutines[$pkg_name] = new Coroutine($this->processProvider($pkg_name, $provider_sha256));

            $processed += 1;
            if ($processed % 50 == 0) {
                $pkg_hash = yield $coroutines;
                foreach ($pkg_hash as $pkg => $hash) {
                    $providers['providers'][$pkg]['sha256'] = $hash;
                }
                $coroutines = [];
                $this->logger->info("processed $processed/$total@$o_url");
            }
        }
        $pkg_hash = yield $coroutines;
        foreach ($pkg_hash as $pkg => $hash) {
            $providers['providers'][$pkg]['sha256'] = $hash;
        }

        $new_content = json_encode($providers);
        $new_sha256 = hash('sha256', $new_content);
        $o_url = str_replace('%hash%', $new_sha256, $o_url);
        $path = $this->storage_path . "/" . $o_url;

        yield from self::file_put_contents($path, $new_content);
        yield $this->redisClient->hSet('hashmap', $url, $new_sha256);

        $this->logger->info("processed $url with new sha256 $new_sha256");
        return $new_sha256;
    }

    public function processProvider($pkg_name, $sha256)
    {
        $this->logger->debug("processing $pkg_name with sha256 $sha256");

        $url = $this->providers_url;
        $url = str_replace(['%package%', '%hash%'], [$pkg_name, $sha256], $url);

        $new_sha256 = yield $this->redisClient->hGet('hashmap', $url);
        if ($new_sha256)
            return $new_sha256;

        /** @var Response $response */
        $response = yield $this->client->request($this->url . $url);

        $body = yield $response->getBody();
        $packages = json_decode($body, true);

        foreach ($packages['packages'] as $sub_pkg_name => &$versions) {
            foreach ($versions as $version => &$version_data) {
                if (isset($version_data['dist'])) {
                    $version_data['dist']['url'] =
                        $this->config['base_url'] . '/dl/' . base64_encode($version_data['dist']['url']);
                } elseif (isset($version_data['source'])) {
                    if ($version_data['source']['type'] == 'git') {
                        $reference = $version_data['source']['reference'];
                        $dist_url = $this->tryMapping($version_data);
                        $generated = false;
                        if (!$dist_url) {
                            $dist_url = yield from $this->tryGitlab($version_data);
                        }
                        if (!$dist_url) {
                            $dist_url = yield from $this->tryCoding($version_data);
                        }
                        if (!$dist_url) {
                            $dist_url = yield from $this->tryLocalGit($version_data);
                            $generated = true;
                        }

                        if ($dist_url) {
                            $version_data['dist'] = [
                                'type' => 'zip',
                                'url' => $dist_url,
                                'reference' => $reference,
                                'generated' => $generated
                            ];
                        }
                    } else {
                        $this->logger->error(
                            "$version@$sub_pkg_name is " . $version_data['source']['type'] . " project!"
                        );
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
        yield $this->redisClient->hSet('hashmap', $url, $new_sha256);

        $this->logger->debug("processed $pkg_name with new sha256 $new_sha256");
        return $new_sha256;
    }

    public function tryMapping($version_data)
    {
        $mapping = $this->config['mapping'];
        $name = $version_data['name'];
        if (!empty($mapping[$name])) {
            $commit = $version_data['source']['reference'];
            $dist_url = str_replace('%commit%', $commit, $mapping[$name]);
            return $this->config['base_url'] . '/dl/' . base64_encode($dist_url);
        }
        return false;
    }

    public function tryGitlab($version_data)
    {
        $ret = false;

        $repo_url = $version_data['source']['url'];
        if (substr($repo_url, -4) == '.git') {
            $repo_url = substr($repo_url, 0, -4);
        }
        $commit = $version_data['source']['reference'];
        $dist_url = "$repo_url/repository/$commit/archive.zip";

        try {
            /** @var Response $response */
            $response = yield $this->client->request(
                $dist_url,
                [Client::OP_DISCARD_BODY => true]
            );
            if ($response->getStatus() == 200 &&
                strpos($response->getHeader('Content-Type'), 'application/zip') !== false
            ) {
                return $this->config['base_url'] . '/dl/' . base64_encode($dist_url);
            }
        } catch (\Exception $e) {
            $ret = false;
        }

        if (!$ret) {
            $dist_url = "$repo_url/repository/archive.zip?ref=$commit";
            try {
                /** @var Response $response */
                $response = yield $this->client->request(
                    $dist_url,
                    [Client::OP_DISCARD_BODY => true]
                );
                if ($response->getStatus() == 200 &&
                    strpos($response->getHeader('Content-Type'), 'application/zip') !== false
                ) {
                    return $this->config['base_url'] . '/dl/' . base64_encode($dist_url);
                }
            } catch (\Exception $e) {
                $ret = false;
            }
        }
        return $ret;
    }

    public function tryCoding($version_data)
    {
        $repo_url = $version_data['source']['url'];
        $re = '/coding.net\/(?:u\/)?(?<user>[^\/]+)\/(?:p\/)?(?<proj>[^\/]+)(?:\.|\/)git/';
        if (!preg_match($re, $repo_url, $matches))
            return false;

        $user = $matches['user'];
        $proj = $matches['proj'];
        $commit = $version_data['source']['reference'];

        $dist_url = "https://coding.net/u/$user/p/$proj/git/archive/$commit";

        try {
            /** @var Response $response */
            $response = yield $this->client->request(
                $dist_url,
                [Client::OP_DISCARD_BODY => true]
            );
            if ($response->getStatus() == 200 &&
                strpos($response->getHeader('Content-Disposition'), '.zip') !== false
            ) {
                return $this->config['base_url'] . '/dl/' . base64_encode($dist_url);
            }
        } catch (\Exception $e) {
            return false;
        }
        return false;
    }

    public function tryLocalGit($version_data)
    {
        $sub_pkg_name = $version_data['name'];
        $reference = $version_data['source']['reference'];
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
            return false;
        }
        yield File\mkdir($this->storage_path . "/file/$sub_pkg_name/", 0777, true);
        $cmd = "git --git-dir=$dir/.git/ archive "
            . "--output=" . $this->storage_path . "/file/$sub_pkg_name/$reference.zip $reference";
        $process = new Process($cmd, null, ['GIT_ASKPASS' => 'echo']);
        $process->start();
        $process->getStdin()->close();
        $code = yield $process->join();
        if ($code != 0) {
            $this->logger->error("$cmd error with code $code");
            $this->logger->error(yield $process->getStderr()->read());
            return false;
        }
        $dist_url = $this->config['base_url'] . '/file/' . $sub_pkg_name . '/' . $version_data['source']['reference'] . '.zip';
        return $dist_url;
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
