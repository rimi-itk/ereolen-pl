<?php

require __DIR__.'/vendor/autoload.php';

use GuzzleHttp\Client;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Twig\Environment;
use Twig\Extension\DebugExtension;
use Twig\Loader\FilesystemLoader;

class Handler
{
    /**
     * @var array
     */
    private $options;

    public function __construct()
    {
        $configFilename = __DIR__.'/config.json';
        if (!file_exists($configFilename)) {
            throw new RuntimeException(sprintf('Config file %s not found', $configFilename));
        }
        $options = json_decode(file_get_contents($configFilename), true);

        $resolver = new OptionsResolver();
        $this->configureOptions($resolver);
        $this->options = $resolver->resolve($options);

        date_default_timezone_set($this->options['time_zone']);
    }

    private function configureOptions(OptionsResolver $resolver)
    {
        $resolver
            ->setRequired([
                'site_name',
                'branches',
                'pulls',
            ])
            ->setDefaults([
                'client' => [],
                'time_zone' => 'Europe/Copenhagen',
            ]);
    }

    public function handle(array $request)
    {
        $path = $request['path'] ?? 'index';

        if (!method_exists($this, $path)) {
            throw new RuntimeException(sprintf('Invalid path: %s', $path));
        }

        echo $this->{$path}($request);
    }

    private function index()
    {
        return $this->render('index.html.twig');
    }

    private function pulls()
    {
        $data = [];

        $repos = $this->options['pulls']['repos'] ?? null;

        $updatedAt = null;
        if (is_array($repos)) {
            foreach ($repos as $repo) {
                $path = 'repos/'.$repo.'/pulls';
                $repoData = $this->get($path);
                if (isset($repoData['meta']['updated_at']) && $repoData['meta']['updated_at'] > $updatedAt) {
                    $updatedAt = $repoData['meta']['updated_at'];
                }
                $data['data'][$repo] = $repoData;
            }
            if ($updatedAt) {
                $data['meta']['updated_at'] = $updatedAt;
            }
        }

        return $this->render('pulls.html.twig', ['data' => $data]);
    }

    private function branches()
    {
        $data = [];

        $dirs = $this->options['branches']['dirs'] ?? null;

        if (is_array($dirs)) {
            foreach ($dirs as $dir) {
                $datum = [
                    'dir' => $dir,
                ];

                $command = "git -C $dir remote get-url origin";
                $output = null;
                exec($command, $output);
                $remote = reset($output);
                $datum['repo'] = preg_replace('/\.git$/', '', $remote);

                $command = "git -C $dir rev-parse --abbrev-ref HEAD";
                $output = null;
                exec($command, $output);
                $datum['branch'] = reset($output);

                $command = "git -C $dir rev-parse HEAD";
                $output = null;
                exec($command, $output);
                $datum['commit'] = reset($output);

                $data[] = $datum;
            }
        }

        return $this->render('branches.html.twig', ['data' => $data]);
    }

    private $cacheDir = __DIR__.'/var/cache';

    private function flush(array $request)
    {
        // https://stackoverflow.com/a/3338133
        $rrmdir = function ($dir) use (&$rrmdir) {
            if (is_dir($dir)) {
                $objects = scandir($dir);
                foreach ($objects as $object) {
                    if ('.' !== $object && '..' !== $object) {
                        if (is_dir($dir.DIRECTORY_SEPARATOR.$object) && !is_link($dir.'/'.$object)) {
                            $rrmdir($dir.DIRECTORY_SEPARATOR.$object);
                        } else {
                            unlink($dir.DIRECTORY_SEPARATOR.$object);
                        }
                    }
                }
                rmdir($dir);
            }
        };
        $rrmdir($this->cacheDir);

        $redirectUrl = $request['redirect'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        header('Location: '.$redirectUrl);
    }

    private function render(string $templateName, array $context = [])
    {
        $loader = new FilesystemLoader(__DIR__.'/templates');
        $twig = new Environment($loader, ['debug' => true]);
        $twig->getExtension(Twig\Extension\CoreExtension::class)->setDateFormat('Y-m-d H:i:s', '%d days');
        $twig->addExtension(new DebugExtension());
        $twig->addFilter(new Twig\TwigFilter('trans', function (string $text, array $parameters = []) {
            return str_replace(array_keys($parameters), array_values($parameters), $text);
        }, [
            'is_safe' => ['html'],
        ]));
        $twig->addFilter(new Twig\TwigFilter('markdown_to_html', function (string $text, array $parameters = []) {
            $parser = new \cebe\markdown\GithubMarkdown();

            return $parser->parse($text);
        }, [
            'is_safe' => ['html'],
        ]));
        $twig->addFunction(new Twig\TwigFunction('path', function (string $path, array $parameters = []) {
            $requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if (!empty($path)) {
                $parameters += ['path' => $path];
            }

            return $requestPath.'?'.http_build_query($parameters);
        }));
        $twig->addFunction(new Twig\TwigFunction('current_url', function (array $parameters = []) {
            return $_SERVER['REQUEST_URI'];
        }));
        $twig->addFunction(new Twig\TwigFunction('is_current_path', function (string $path) {
            $queryString = parse_url($path, PHP_URL_QUERY);
            parse_str($queryString, $query);

            return ($query['path'] ?? null) === ($_GET['path'] ?? null);
        }));

        $context += [
            'app' => ['query' => $_GET],
            'config' => $this->options,
        ];

        return $twig->render($templateName, $context);
    }

    private function get(string $path, array $query = []): array
    {
        $cacheKey = $path;
        if (!empty($query)) {
            $cacheKey .= '@'.urlencode(http_build_query($query));
        }
        $cacheKey = preg_replace('~[^a-z0-9/-]~i', '@', $cacheKey);
        $cacheFilename = $this->cacheDir.'/'.$cacheKey;
        if (file_exists($cacheFilename)) {
            $content = file_get_contents($cacheFilename);
        } else {
            $response = $this->client()->request('GET', $path);
            $content = (string) $response->getBody();

            if (!mkdir($concurrentDirectory = dirname($cacheFilename), 0755, true) && !is_dir($concurrentDirectory)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
            }
            file_put_contents($cacheFilename, $content);
        }

        return [
            'data' => json_decode($content, true),
            'meta' => [
                'updated_at' => (new DateTimeImmutable('@'.filemtime($cacheFilename)))->format('Y-m-d\TH:i:sP'),
            ],
        ];
    }

    /**
     * @var Client
     */
    private $client;

    private function client()
    {
        if (null === $this->client) {
            $config = [
                'base_uri' => 'https://api.github.com',
            ];
            if (isset($this->options['client']['config'])) {
                $config = array_merge($config, (array) $this->options['client']['config']);
            }
            $this->client = new Client($config);
        }

        return $this->client;
    }
}

(new Handler())->handle($_GET);
