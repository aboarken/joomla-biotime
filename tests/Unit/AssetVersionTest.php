<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 1.3.3: every script and style carries the component version, so its address (admin.js?1.3.3)
 * changes with each release. Joomla's extension update (System → Update → Extensions) does not
 * renew its media version, so without this browsers keep the previous release's files.
 */
final class AssetVersionTest extends TestCase
{
    public function testEveryAssetCarriesTheComponentVersion(): void
    {
        $root     = \dirname(__DIR__, 2);
        $manifest = simplexml_load_file($root . '/biotime.xml');
        $version  = trim((string) $manifest->version);
        $assets   = json_decode((string) file_get_contents($root . '/media/joomla.asset.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($version, $assets['version']);
        self::assertNotEmpty($assets['assets']);

        foreach ($assets['assets'] as $asset) {
            self::assertSame($version, $asset['version'] ?? null, $asset['name'] . ' (' . $asset['type'] . ')');
            self::assertFileExists($root . '/media/' . ($asset['type'] === 'style' ? 'css' : 'js') . '/' . basename($asset['uri']), $asset['name']);
        }
    }
}
