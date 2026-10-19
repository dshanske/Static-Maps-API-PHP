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
            'defaults' => ['basemap' => 'one'],
            'zoom'     => ['min' => 2, 'max' => 18],
            'token_file' => '/nonexistent/tokens.txt',
            'basemaps' => [
                'one' => ['name' => 'One', 'layers' => ['https://tiles.example/{z}/{x}/{y}.png']],
            ],
        ];
    }

    /**
     * The shipped defaults load and validate without an install file.
     *
     * @return void
     */
    public function testShippedDefaultsAreValid(): void
    {
        $config = Config::load(null, '/nonexistent/config.php');

        $this->assertSame('osm', $config['defaults']['basemap']);
        $this->assertSame(['min' => 2, 'max' => 18], $config['zoom']);
        $this->assertArrayHasKey('streets', $config['basemaps']);
    }

    /**
     * The install file is merged over the defaults.
     *
     * @return void
     */
    public function testInstallFileOverridesDefaults(): void
    {
        $config = Config::load(null, $this->configFile([
            'defaults' => ['width' => 640],
            'basemaps' => ['otm' => ['maxzoom' => 12]],
        ]));

        $this->assertSame(640, $config['defaults']['width']);
        $this->assertSame(300, $config['defaults']['height']);
        $this->assertSame(12, $config['basemaps']['otm']['maxzoom']);
        $this->assertSame('OpenTopoMap', $config['basemaps']['otm']['name']);
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
        return [
            'section not an array' => [['zoom' => 5], "'zoom' must be an array"],
            'basemap not an array' => [['basemaps' => ['bad' => 'x']], "Basemap 'bad' must be an array"],
            'no layers'            => [['basemaps' => ['bad' => ['name' => 'Bad']]], "'bad' needs 'layers'"],
            'three layers'         => [['basemaps' => ['bad' => ['layers' => ['a', 'b', 'c']]]], 'needs'],
            'unknown alias target' => [['basemaps' => ['bad' => ['alias_of' => 'nope']]], "unknown basemap 'nope'"],
            'alias of alias'       => [
                ['basemaps' => ['a1' => ['alias_of' => 'one'], 'a2' => ['alias_of' => 'a1']]],
                'alias of another alias',
            ],
            'unknown default'      => [['defaults' => ['basemap' => 'nope']], 'Default basemap must be'],
            'default needs token'  => [['basemaps' => ['one' => ['requires_auth' => true]]], 'Default basemap must be'],
            'default disabled'     => [['basemaps' => ['one' => ['enabled' => false]]], 'Default basemap must be'],
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

        Config::load($this->configFile(Config::merge(self::minimal(), $override)), '/nonexistent');
    }

    /**
     * A disabled basemap without layers is allowed.
     *
     * @return void
     */
    public function testDisabledBasemapNeedsNoLayers(): void
    {
        $config = Config::load($this->configFile(Config::merge(self::minimal(), [
            'basemaps' => ['off' => ['enabled' => false]],
        ])), '/nonexistent');

        $this->assertNull(Config::basemap($config, 'off', true));
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
     * basemap() resolves aliases, honours enabled/requires_auth and fills in the zoom range.
     *
     * @return void
     */
    public function testBasemapResolution(): void
    {
        $config = Config::merge(self::minimal(), ['basemaps' => [
            'narrow' => ['layers' => ['n'], 'minzoom' => 4, 'maxzoom' => 9],
            'secret' => ['layers' => ['s'], 'requires_auth' => true],
            'off'    => ['layers' => ['o'], 'enabled' => false],
            'alias'  => ['alias_of' => 'narrow'],
            'to-off' => ['alias_of' => 'off'],
        ]]);

        $one = Config::basemap($config, 'one', false);
        $this->assertSame(2, $one['minzoom']);
        $this->assertSame(18, $one['maxzoom']);

        $alias = Config::basemap($config, 'alias', false);
        $this->assertSame(['n'], $alias['layers']);
        $this->assertSame([4, 9], [$alias['minzoom'], $alias['maxzoom']]);

        $this->assertNull(Config::basemap($config, 'secret', false));
        $this->assertSame(['s'], Config::basemap($config, 'secret', true)['layers']);
        $this->assertNull(Config::basemap($config, 'off', true));
        $this->assertNull(Config::basemap($config, 'to-off', true));
        $this->assertNull(Config::basemap($config, 'missing', true));
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
