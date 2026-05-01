<?php

/**
 * Smoke test that lints every AgentForge Co-Pilot view file.
 *
 * Each `copilot_*.php` file under `interface/`, `custom/`, and `portal/`
 * is fed through `php -l`. Any syntax error fails the build before push.
 *
 * @package   OpenEMR
 * @author    AgentForge / Claude Code
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CopilotPagesSyntaxTest extends TestCase
{
    private const REPO_ROOT = __DIR__ . '/../../../..';

    #[DataProvider('copilotFilesProvider')]
    public function testCopilotFileHasNoSyntaxError(string $relPath): void
    {
        $absPath = self::REPO_ROOT . '/' . $relPath;
        $this->assertFileExists($absPath, "Expected file to exist: $relPath");

        $output = [];
        $exitCode = 0;
        $cmd = 'php -l ' . escapeshellarg($absPath) . ' 2>&1';
        exec($cmd, $output, $exitCode);

        $this->assertSame(
            0,
            $exitCode,
            "PHP syntax error in $relPath:\n" . implode("\n", $output)
        );
    }

    /**
     * Discover every copilot_*.php file under interface/, custom/, portal/.
     *
     * @return array<string, array{string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function copilotFilesProvider(): array
    {
        $repoRoot = self::REPO_ROOT;
        $cases = [];

        foreach (['interface', 'custom', 'portal', 'public'] as $rootDir) {
            $absRoot = $repoRoot . '/' . $rootDir;
            if (!is_dir($absRoot)) {
                continue;
            }
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absRoot, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iter as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $name = $file->getFilename();
                if (!preg_match('/^copilot_.*\.php$/', $name)) {
                    continue;
                }
                $abs = $file->getPathname();
                $rel = ltrim(str_replace($repoRoot, '', $abs), '/');
                $cases[$rel] = [$rel];
            }
        }

        ksort($cases);

        return $cases;
    }

    public function testAtLeastTwentyCopilotFilesExist(): void
    {
        $cases = self::copilotFilesProvider();
        $this->assertGreaterThanOrEqual(
            20,
            count($cases),
            'Expected at least 20 copilot_*.php files to be discovered.'
        );
    }
}
