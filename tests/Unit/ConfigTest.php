<?php

/**
 * Tests for include/Config.php.
 */

namespace StaticMaps\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use StaticMaps\Config;

/**
 * Loading, merging, validating and querying the configuration.
 */
final class ConfigTest extends TestCase
{
    /** @var list<string> Temporary files to delete after the test. */
    private array $files = [];

    /**
     * Delete temporary files.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Write a PHP config file that returns the given value.
     *
     * @param mixed $value What the file returns.
     *
     * @return string Path to the file.
     */
    private function configFile(mixed $value): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'static-maps-config-');
        file_put_contents($file, '<?php return ' . var_export($value, true) . ';');
        $this->files[] = $file;

        return $file;
    }

    /**
     * A minimal valid configuration, as a base for tests.
     *
     * @return array<string, mixed> Configuration array.
     */
    private static function minimal(): array
    {
        return [
            'defaults'        => ['provider' => 'one', 'style' => 'base', 'format' => 'png'],
            'zoom'            => ['min' => 2, 'max' => 18],
            'limits'          => ['max_width' => 1280, 'max_height' => 1280],
            'cache'           => ['max_age' => 604800, 'fallback_max_age' => 300],
            'remote'          => [
                'timeout'         => 10,
                'connect_timeout' => 5,
                'max_bytes'       => 5000000,
                'max_redirects'   => 3,
            ],
            'token_file'      => '/nonexistent/tokens.txt',
            'home_page'       => __DIR__ . '/../../assets/home.html',
            'basemap_aliases' => ['old-one' => 'one/base'],
            'removed'         => ['gone' => 'Shut down'],
            'providers'       => [
                'one' => [
                    'name'   => 'One',
                    'styles' => ['base' => ['name' => 'Base', 'layers' => ['https://tiles.example/{z}/{x}/{y}.png']]],
                ],
            ],
        ];
    }

    /**
     * Load a configuration made of minimal() with $override merged over it, as the defaults file.
     *
     * @param array<string, mixed> $override Settings merged over minimal().
     *
     * @return array<string, mixed> The loaded configuration.
     */
    private function loadMinimal(array $override = []): array
    {
        return Config::load($this->configFile(Config::merge(self::minimal(), $override)), '/nonexistent');
    }

    /**
     * The shipped defaults load and validate without an install file.
     *
     * @return void
     */
    public function testShippedDefaultsAreValid(): void
    {
        $config = Config::load(null, '/nonexistent/config.php');

        $this->assertSame(['osm', 'streets'], [$config['defaults']['provider'], $config['defaults']['style']]);
        $this->assertSame(['min' => 2, 'max' => 18, 'max_limit' => null], $config['zoom']);
        $this->assertSame(['osm', 'esri', 'opentopomap', 'carto'], array_keys($config['providers']));
        $this->assertSame([], $config['deprecations']);
    }

    /**
     * Every shipped style sets its own attribution and credit text, so changing
     * defaults.attribution never changes the credit on a shipped map (#50, #29):
     * Esri styles draw the Esri logo with their sources, and the OpenStreetMap-based
     * providers draw only text.
     *
     * @return void
     */
    public function testShippedAttribution(): void
    {
        $config = Config::load(null, $this->configFile([
            'defaults'  => ['attribution' => 'mapbox'],
            'providers' => ['carto' => ['key' => 'k']],
        ]));

        $expected = ['osm' => 'text', 'esri' => 'esri', 'opentopomap' => 'text', 'carto' => 'text'];
        $this->assertSame(array_keys($expected), array_keys($config['providers']));
        foreach ($expected as $provider => $attribution) {
            foreach (array_keys($config['providers'][$provider]['styles']) as $style) {
                $resolved = Config::resolve($config, $provider, $style, false);
                $this->assertSame($attribution, $resolved['attribution'] ?? null, "$provider/$style");
                $this->assertNotEmpty($resolved['attribution_text'] ?? null, "$provider/$style has credit text");
                $html = (string) ($resolved['attribution_html'] ?? '');
                $this->assertStringContainsString('<a href="https://', $html, "$provider/$style links");
                if ($provider === 'esri') {
                    $this->assertStringStartsWith('Powered by <a href="https://www.esri.com">Esri</a>', $html);
                    $onMap = mb_strlen($resolved['attribution_text']);
                    $this->assertLessThanOrEqual(80, $onMap, "$style on-map text is short");
                }
            }
        }
    }

    /**
     * Every shipped style has its own checked maximum zoom (#54), not the zoom.max default.
     *
     * @return void
     */
    public function testShippedZoomRanges(): void
    {
        $config = Config::load(null, $this->configFile([
            'zoom'      => ['max' => 5],
            'providers' => ['carto' => ['key' => 'k']],
        ]));
        $expected = [
            'osm/streets'         => 19,
            'esri/streets'        => 19,
            'esri/satellite'      => 19,
            'esri/hybrid'         => 19,
            'esri/topo'           => 19,
            'esri/light'          => 16,
            'esri/light-nolabels' => 16,
            'esri/oceans'         => 13,
            'esri/natgeo'         => 16,
            'opentopomap/topo'    => 17,
            'carto/light'         => 20,
            'carto/dark'          => 20,
            'carto/streets'       => 20,
        ];

        $actual = [];
        foreach ($config['providers'] as $provider => $p) {
            foreach (array_keys($p['styles']) as $style) {
                $actual["$provider/$style"] = Config::resolve($config, $provider, $style, false)['maxzoom'];
            }
        }
        $this->assertSame($expected, $actual);
    }

    /**
     * zoom.max_limit caps every map's maxzoom (and minzoom when higher); unset, it changes nothing.
     *
     * @return void
     */
    public function testMaxLimit(): void
    {
        $providers = ['providers' => ['p' => ['styles' => [
            'deep'    => ['layers' => ['a'], 'maxzoom' => 20],
            'shallow' => ['layers' => ['b'], 'maxzoom' => 13],
            'plain'   => ['layers' => ['c']],
            'high'    => ['layers' => ['d'], 'minzoom' => 16, 'maxzoom' => 19],
        ]]]];
        $range = static fn (array $c, string $s): array => [
            Config::resolve($c, 'p', $s, false)['minzoom'],
            Config::resolve($c, 'p', $s, false)['maxzoom'],
        ];

        $open = $this->loadMinimal($providers);
        $this->assertSame([2, 20], $range($open, 'deep'));
        $this->assertSame([2, 18], $range($open, 'plain'));

        $capped = $this->loadMinimal($providers + ['zoom' => ['max_limit' => 15]]);
        $this->assertSame([2, 15], $range($capped, 'deep'));
        $this->assertSame([2, 13], $range($capped, 'shallow'));
        $this->assertSame([2, 15], $range($capped, 'plain'));
        $this->assertSame([15, 15], $range($capped, 'high'));
    }

    /**
     * A style whose layers use {key} is unavailable until a key is set (#17).
     *
     * @return void
     */
    public function testStyleNeedingAKeyIsUnavailableWithoutOne(): void
    {
        $keyed = 'https://t.example/{z}/{x}/{y}.png?key={key}';
        $providers = ['providers' => [
            'p'     => ['styles' => ['s' => ['layers' => [$keyed]]]],
            'empty' => ['key' => '', 'styles' => ['s' => ['layers' => [$keyed]]]],
            'set'   => ['key' => 'abc', 'styles' => ['s' => ['layers' => [$keyed]]]],
            'own'   => ['styles' => ['s' => ['layers' => ['a', $keyed], 'key' => 'x']]],
        ]];
        $config = $this->loadMinimal($providers);

        $this->assertNull(Config::resolve($config, 'p', 's', true));
        $this->assertNull(Config::resolve($config, 'empty', 's', true));
        $this->assertSame('abc', Config::resolve($config, 'set', 's', false)['key']);
        $this->assertSame('x', Config::resolve($config, 'own', 's', false)['key']);
    }

    /**
     * The shipped CARTO styles need a key; with one they use CARTO's keyed URLs (#17).
     *
     * @return void
     */
    public function testCartoNeedsAKey(): void
    {
        $without = Config::load(null, '/nonexistent/config.php');
        foreach (['light', 'dark', 'streets'] as $style) {
            $this->assertNull(Config::resolve($without, 'carto', $style, true), $style);
        }

        $with = Config::load(null, $this->configFile(['providers' => ['carto' => ['key' => 'my-key']]]));
        $light = Config::resolve($with, 'carto', 'light', false);
        $this->assertSame('my-key', $light['key']);
        $this->assertSame(
            'https://basemaps.cartocdn.com/light_all/3/1/2.png?key=my-key',
            Config::tileUrl($light['layers'][0], 1, 2, 3, $light['key'])
        );
    }

    /**
     * The oceans style uses Esri's current ocean services, whose detailed data ends at zoom 13 (#17).
     *
     * @return void
     */
    public function testOceansUsesCurrentServices(): void
    {
        $oceans = Config::resolve(Config::load(null, '/nonexistent/config.php'), 'esri', 'oceans', false);

        $this->assertCount(2, $oceans['layers']);
        $this->assertStringContainsString('/Ocean/World_Ocean_Base/', $oceans['layers'][0]);
        $this->assertStringContainsString('/Ocean/World_Ocean_Reference/', $oceans['layers'][1]);
        $this->assertSame(13, $oceans['maxzoom']);
    }

    /**
     * Every old basemap id still maps to an enabled style (#40).
     *
     * @return void
     */
    public function testEveryOldBasemapIdResolves(): void
    {
        // CARTO's styles need a key (#17), so give it one for this test.
        $config = Config::load(null, $this->configFile(['providers' => ['carto' => ['key' => 'k']]]));

        $this->assertCount(13, $config['basemap_aliases']);
        foreach (array_keys($config['basemap_aliases']) as $id) {
            $alias = Config::alias($config, $id);
            $this->assertNotNull($alias, $id);
            $this->assertNotNull(Config::resolve($config, $alias[0], $alias[1], false), $id);
        }
        $this->assertSame(['carto', 'streets'], Config::alias($config, 'carto-voyager'));
        $this->assertSame(['esri', 'light-nolabels'], Config::alias($config, 'gray-background'));
        $this->assertSame(['opentopomap', 'topo'], Config::alias($config, 'otm'));
        $this->assertNull(Config::alias($config, 'custom'));
        $this->assertNull(Config::alias($config, 'stamen-toner'), 'Stamen ids were removed');
    }

    /**
     * The install file is merged over the defaults.
     *
     * @return void
     */
    public function testInstallFileOverridesDefaults(): void
    {
        $config = Config::load(null, $this->configFile([
            'defaults'  => ['width' => 640],
            'providers' => ['opentopomap' => ['maxzoom' => 12]],
        ]));

        $this->assertSame(640, $config['defaults']['width']);
        $this->assertSame(300, $config['defaults']['height']);
        $topo = Config::resolve($config, 'opentopomap', 'topo', false);
        $this->assertSame(12, $topo['maxzoom']);
        $this->assertSame('OpenTopoMap', $topo['name']);
        $this->assertSame([], $config['deprecations']);
    }

    /**
     * STATIC_MAPS_CONFIG names the install file when no path is passed.
     *
     * @return void
     */
    public function testEnvironmentVariableNamesInstallFile(): void
    {
        $file = $this->configFile(['defaults' => ['width' => 123]]);
        putenv("STATIC_MAPS_CONFIG=$file");
        try {
            $config = Config::load();
        } finally {
            putenv('STATIC_MAPS_CONFIG');
        }

        $this->assertSame(123, $config['defaults']['width']);
    }

    /**
     * Named arrays merge key by key; lists and scalars replace.
     *
     * @return void
     */
    public function testMerge(): void
    {
        $merged = Config::merge(
            ['a' => ['x' => 1, 'y' => 2], 'layers' => ['p', 'q'], 's' => 1],
            ['a' => ['y' => 3], 'layers' => ['r'], 's' => ['now' => 'array'], 'new' => true]
        );

        $this->assertSame(
            ['a' => ['x' => 1, 'y' => 3], 'layers' => ['r'], 's' => ['now' => 'array'], 'new' => true],
            $merged
        );
    }

    /**
     * Invalid configurations and the message each one produces.
     *
     * @return array<string, array{array<string, mixed>, string}> Override merged over minimal(),
     *                                                           and a fragment of the expected message.
     */
    public static function invalidConfigs(): array
    {
        $style = ['name' => 'S', 'layers' => ['t']];

        return [
            'section not an array'  => [['zoom' => 5], "'zoom' must be an array"],
            'aliases not an array'  => [['basemap_aliases' => 'x'], "'basemap_aliases' must be an array"],
            'provider not an array' => [['providers' => ['bad' => 'x']], "Provider 'bad' must be an array"],
            'no styles'             => [['providers' => ['bad' => ['name' => 'Bad']]], "'bad' needs 'styles'"],
            'styles as a list'      => [['providers' => ['bad' => ['styles' => [$style]]]], "'bad' needs 'styles'"],
            'unknown default style' => [
                ['providers' => ['bad' => ['default_style' => 'nope', 'styles' => ['s' => $style]]]],
                'unknown default_style',
            ],
            'style not an array'    => [['providers' => ['bad' => ['styles' => ['s' => 'x']]]], 'must be an array'],
            'no layers' => [['providers' => ['b' => ['styles' => ['s' => ['name' => 'S']]]]], "'b/s' needs"],
            'three layers'          => [
                ['providers' => ['bad' => ['styles' => ['s' => ['layers' => ['a', 'b', 'c']]]]]],
                "'bad/s' needs",
            ],
            'reserved id'           => [['providers' => ['custom' => ['styles' => ['s' => $style]]]], 'is reserved'],
            'alias to nowhere'      => [['basemap_aliases' => ['old' => 'one/nope']], "alias 'old' must point"],
            'alias malformed'       => [['basemap_aliases' => ['old' => 'one']], "alias 'old' must point"],
            'limit not int'         => [['limits' => ['max_width' => '1280']], "'limits.max_width' must be"],
            'limit zero'            => [['limits' => ['max_height' => 0]], "'limits.max_height' must be"],
            'cache max_age'         => [['cache' => ['max_age' => '7d']], "'cache.max_age' must be"],
            'cache policy'          => [['providers' => ['one' => ['cache' => 'sometimes']]], "isn't 'headers'"],
            'style cache policy'    => [
                ['providers' => ['one' => ['styles' => ['base' => ['cache' => true]]]]],
                "isn't 'headers' or 'off'",
            ],
            'remote timeout'        => [['remote' => ['timeout' => 0]], "'remote.timeout' must be"],
            'remote redirects'      => [['remote' => ['max_redirects' => -1]], "'remote.max_redirects' must be"],
            'unknown format'        => [['defaults' => ['format' => 'gif']], "'defaults.format' must be"],
            'max_limit not int'     => [['zoom' => ['max_limit' => '15']], "'zoom.max_limit' must be null or"],
            'max_limit negative'    => [['zoom' => ['max_limit' => -1]], "'zoom.max_limit' must be null or"],
            'provider text'         => [['providers' => ['one' => ['attribution_text' => 5]]], 'must be a string'],
            'style text'            => [
                ['providers' => ['one' => ['styles' => ['base' => ['attribution_text' => ['x']]]]]],
                'attribution_text must be a string',
            ],
            'removed not array'     => [['removed' => 'x'], "'removed' must be an array"],
            'removed reason'        => [['removed' => ['gone' => false]], "'removed' must map ids to reasons"],
            'home page not string'  => [['home_page' => false], "'home_page' must be a string"],
            'home page missing'     => [['home_page' => '/nonexistent/home.html'], 'must name a readable file'],
            'unknown default'       => [['defaults' => ['provider' => 'nope']], 'Default provider and style'],
            'unknown defaults.style' => [['defaults' => ['style' => 'nope']], 'Default provider and style'],
            'default needs token'   => [['providers' => ['one' => ['requires_auth' => true]]], 'Default provider'],
            'default needs a key'   => [
                ['providers' => ['one' => ['styles' => ['base' => ['layers' => ['t?key={key}']]]]]],
                'Default provider and style',
            ],
            'default disabled'      => [['providers' => ['one' => ['enabled' => false]]], 'Default provider and style'],
            'default style off'     => [
                ['providers' => ['one' => ['styles' => ['base' => ['enabled' => false]]]]],
                'Default provider and style',
            ],
        ];
    }

    /**
     * Invalid configurations are rejected with a helpful message.
     *
     * @param array<string, mixed> $override Settings merged over minimal().
     * @param string               $message  Expected part of the exception message.
     *
     * @return void
     */
    #[DataProvider('invalidConfigs')]
    public function testInvalidConfigIsRejected(array $override, string $message): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        $this->loadMinimal($override);
    }

    /**
     * Disabled styles, and the styles of a disabled provider, need no layers.
     *
     * @return void
     */
    public function testDisabledStylesNeedNoLayers(): void
    {
        $config = $this->loadMinimal(['providers' => [
            'one' => ['styles' => ['off' => ['enabled' => false]]],
            'two' => ['enabled' => false, 'styles' => ['s' => ['name' => 'S']]],
        ]]);

        $this->assertNull(Config::resolve($config, 'one', 'off', true));
        $this->assertNull(Config::resolve($config, 'two', 's', true));
    }

    /**
     * A file that does not return an array, or does not exist, is an error.
     *
     * @return void
     */
    public function testUnreadableFiles(): void
    {
        try {
            Config::load($this->configFile('not an array'), '/nonexistent');
            $this->fail('Expected an exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('does not return an array', $e->getMessage());
        }

        $this->expectExceptionMessage('Configuration file not found');
        Config::load('/nonexistent/defaults.php', '/nonexistent');
    }

    /**
     * resolve() merges provider settings into the style, honours enabled and
     * requires_auth, and fills in the zoom range and ids.
     *
     * @return void
     */
    public function testResolve(): void
    {
        $config = $this->loadMinimal(['providers' => [
            'p' => [
                'name'        => 'P',
                'key'         => 'provider-key',
                'attribution' => 'esri',
                'attribution_text' => '© P',
                'maxzoom'     => 12,
                'styles'      => [
                    'plain' => ['name' => 'Plain', 'layers' => ['a']],
                    'own'   => ['name' => 'Own', 'layers' => ['b'], 'key' => 'style-key', 'maxzoom' => 15],
                    'tok'   => ['name' => 'Token', 'layers' => ['c'], 'requires_auth' => true],
                    'off'   => ['name' => 'Off', 'layers' => ['d'], 'enabled' => false],
                ],
            ],
            'secret' => ['requires_auth' => true, 'styles' => ['s' => ['name' => 'S', 'layers' => ['e']]]],
        ]]);

        $plain = Config::resolve($config, 'p', 'plain', false);
        $this->assertSame(['a'], $plain['layers']);
        $this->assertSame('Plain', $plain['name']);
        $this->assertSame('provider-key', $plain['key']);
        $this->assertSame('esri', $plain['attribution']);
        $this->assertSame('© P', $plain['attribution_text'], 'inherited from the provider');
        $this->assertSame([2, 12], [$plain['minzoom'], $plain['maxzoom']]);
        $this->assertSame(['p', 'plain'], [$plain['provider'], $plain['style']]);

        $own = Config::resolve($config, 'p', 'own', false);
        $this->assertSame(['style-key', 15, 'esri'], [$own['key'], $own['maxzoom'], $own['attribution']]);

        $this->assertNull(Config::resolve($config, 'p', 'tok', false));
        $this->assertSame(['c'], Config::resolve($config, 'p', 'tok', true)['layers']);
        $this->assertNull(Config::resolve($config, 'p', 'off', true));
        $this->assertNull(Config::resolve($config, 'secret', 's', false));
        $this->assertNotNull(Config::resolve($config, 'secret', 's', true));
        $this->assertNull(Config::resolve($config, 'p', 'missing', true));
        $this->assertNull(Config::resolve($config, 'missing', 'plain', true));
    }

    /**
     * defaultStyle() is default_style, else the first style.
     *
     * @return void
     */
    public function testDefaultStyle(): void
    {
        $config = $this->loadMinimal(['providers' => [
            'p' => ['default_style' => 'b', 'styles' => ['a' => ['layers' => ['a']], 'b' => ['layers' => ['b']]]],
            'q' => ['styles' => ['first' => ['layers' => ['a']], 'second' => ['layers' => ['b']]]],
        ]]);

        $this->assertSame('b', Config::defaultStyle($config, 'p'));
        $this->assertSame('first', Config::defaultStyle($config, 'q'));
        $this->assertNull(Config::defaultStyle($config, 'missing'));
    }

    /**
     * Deprecated `basemaps` install settings are translated to providers and aliases (#40).
     *
     * @return void
     */
    public function testLegacyBasemapsAreTranslated(): void
    {
        $config = Config::load(null, $this->configFile([
            'basemaps' => [
                'otm'                 => ['maxzoom' => 17],
                'national-geographic' => ['enabled' => false],
                'satellite'           => ['requires_auth' => true],
                'stadia-toner'        => [
                    'name'   => 'Stadia Toner',
                    'layers' => ['https://t.example/{z}/{x}/{y}.png?k={key}'],
                    'key'    => 'k',
                ],
                'old-light'           => ['alias_of' => 'carto-light'],
                'old-new'             => ['alias_of' => 'stadia-toner'],
                'gone'                => ['alias_of' => 'osm', 'enabled' => false],
            ],
        ]));

        $this->assertSame(
            ["Setting 'basemaps' is deprecated; use 'providers' (see config/defaults.php)"],
            $config['deprecations']
        );
        $this->assertSame(17, Config::resolve($config, 'opentopomap', 'topo', false)['maxzoom']);
        $this->assertNull(Config::resolve($config, 'esri', 'natgeo', true));
        $this->assertNull(Config::resolve($config, 'esri', 'satellite', false));
        $this->assertNotNull(Config::resolve($config, 'esri', 'satellite', true));

        $this->assertSame(['stadia-toner', 'default'], Config::alias($config, 'stadia-toner'));
        $stadia = Config::resolve($config, 'stadia-toner', 'default', false);
        $this->assertSame(['https://t.example/{z}/{x}/{y}.png?k={key}'], $stadia['layers']);
        $this->assertSame('k', $stadia['key']);

        $this->assertSame(['carto', 'light'], Config::alias($config, 'old-light'));
        $this->assertSame(['stadia-toner', 'default'], Config::alias($config, 'old-new'));
        $this->assertNull(Config::alias($config, 'gone'));
    }

    /**
     * A deprecated `defaults.basemap` becomes the default provider and style,
     * unless the install also sets the new keys.
     *
     * @return void
     */
    public function testLegacyDefaultBasemapIsTranslated(): void
    {
        $config = Config::load(null, $this->configFile(['defaults' => ['basemap' => 'otm']]));
        $this->assertSame(['opentopomap', 'topo'], [$config['defaults']['provider'], $config['defaults']['style']]);
        $this->assertArrayNotHasKey('basemap', $config['defaults']);
        $this->assertSame(
            ["Setting 'defaults.basemap' is deprecated; use 'defaults.provider' and 'defaults.style'"],
            $config['deprecations']
        );

        $config = Config::load(null, $this->configFile([
            'defaults' => ['basemap' => 'carto-dark', 'provider' => 'esri', 'style' => 'topo'],
        ]));
        $this->assertSame(['esri', 'topo'], [$config['defaults']['provider'], $config['defaults']['style']]);
    }

    /**
     * Old settings that can't be translated are configuration errors.
     *
     * @return array<string, array{array<string, mixed>, string}> Install settings and message fragment.
     */
    public static function untranslatable(): array
    {
        return [
            'unknown default basemap' => [['defaults' => ['basemap' => 'nope']], "Default basemap 'nope' is unknown"],
            'alias of unknown'        => [['basemaps' => ['x' => ['alias_of' => 'nope']]], "unknown basemap 'nope'"],
            'id of a provider'        => [['basemaps' => ['esri' => ['layers' => ['t']]]], 'same id as a provider'],
            'basemap not an array'    => [['basemaps' => ['x' => 'y']], "Basemap 'x' must be an array"],
        ];
    }

    /**
     * Untranslatable old settings are rejected with a helpful message.
     *
     * @param array<string, mixed> $install Install file settings.
     * @param string               $message Expected part of the exception message.
     *
     * @return void
     */
    #[DataProvider('untranslatable')]
    public function testUntranslatableLegacySettings(array $install, string $message): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        Config::load(null, $this->configFile($install));
    }

    /**
     * tileUrl() fills in coordinates (either case) and the URL-encoded key.
     *
     * @return void
     */
    public function testTileUrl(): void
    {
        $this->assertSame(
            'https://t.example/14/2613/5853.png',
            Config::tileUrl('https://t.example/{z}/{x}/{y}.png', 2613, 5853, 14)
        );
        $this->assertSame(
            'https://t.example/tile/14/5853/2613',
            Config::tileUrl('https://t.example/tile/{Z}/{Y}/{X}', 2613, 5853, 14)
        );
        $this->assertSame(
            'https://t.example/1/2/3.png?key=a%20b%26c',
            Config::tileUrl('https://t.example/{z}/{x}/{y}.png?key={key}', 2, 3, 1, 'a b&c')
        );
        $this->assertSame(
            'https://t.example/1/2/3.png?key=',
            Config::tileUrl('https://t.example/{z}/{x}/{y}.png?key={key}', 2, 3, 1)
        );
    }
}
