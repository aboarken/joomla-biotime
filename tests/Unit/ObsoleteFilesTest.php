<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 1.3.7 (audit): the installer script deletes the files of removed features on every update
 * (OBSOLETE_SITE, OBSOLETE_ADMIN, OBSOLETE_MEDIA in script.php). A file that is shipped again must
 * never be on those lists: 1.3.0 – 1.3.3 shipped media/js/admin.js, and every update deleted it.
 */
final class ObsoleteFilesTest extends TestCase
{
    public function testNoShippedFileIsOnTheInstallersDeleteLists(): void
    {
        $root   = \dirname(__DIR__, 2);
        $script = (string) file_get_contents($root . '/script.php');
        $lists  = ['OBSOLETE_SITE' => 'site', 'OBSOLETE_ADMIN' => 'administrator', 'OBSOLETE_MEDIA' => 'media'];
        $seen   = 0;

        foreach ($lists as $const => $folder) {
            self::assertSame(1, preg_match('/const ' . $const . '\s*=\s*\[(.*?)\];/s', $script, $m), $const . ' not found in script.php');

            // The quoted paths, without the comments between them.
            $body = (string) preg_replace('~//[^\n]*~', '', $m[1]);
            preg_match_all("/'([^']+)'/", $body, $paths);

            foreach ($paths[1] as $path) {
                $seen++;
                self::assertStringNotContainsString('..', $path, $const . ': ' . $path);
                self::assertFileDoesNotExist($root . '/' . $folder . '/' . $path, $const . ' lists a shipped file: ' . $folder . '/' . $path);
            }
        }

        self::assertGreaterThan(10, $seen, 'the lists were read');
    }
}
