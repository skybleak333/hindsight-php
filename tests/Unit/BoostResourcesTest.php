<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Laravel Boost installs resources/boost/guidelines into the agent's guideline file and copies every
 * resources/boost/skills/<name>/SKILL.md into the agent's skills directory (Boost 2.x, ThirdPartyPackage).
 */
final class BoostResourcesTest extends TestCase
{
    private const ROOT = __DIR__.'/../../resources/boost';

    public function test_the_guideline_points_to_a_skill_that_exists(): void
    {
        $guideline = (string) file_get_contents(self::ROOT.'/guidelines/core.blade.php');

        $this->assertMatchesRegularExpression('/`(hindsight-php-[a-z-]+)` skill/', $guideline);
        preg_match('/`(hindsight-php-[a-z-]+)` skill/', $guideline, $match);
        $this->assertFileExists(self::ROOT.'/skills/'.$match[1].'/SKILL.md');

        // Rendered with Blade: an echo or a directive in the text would break the render or change it.
        $this->assertStringNotContainsString('{{ ', $guideline);
        $this->assertDoesNotMatchRegularExpression('/^\s*@[a-z]/m', $guideline);
    }

    public function test_every_skill_has_valid_front_matter(): void
    {
        $skills = glob(self::ROOT.'/skills/*/SKILL.md') ?: [];
        $this->assertNotEmpty($skills);

        foreach ($skills as $path) {
            $text = (string) file_get_contents($path);
            $this->assertMatchesRegularExpression('/\A---\n(.*?)\n---\n/s', $text, $path);
            preg_match('/\A---\n(.*?)\n---\n/s', $text, $front);

            preg_match('/^name: (.+)$/m', $front[1], $name);
            preg_match('/^description: (.+)$/m', $front[1], $description);

            // The Agent Skills format: the name matches the directory, lowercase with hyphens, at most 64
            // characters; the description says what the skill does and when to use it, at most 1,024.
            $this->assertSame(basename(dirname($path)), $name[1] ?? null, $path);
            $this->assertMatchesRegularExpression('/^[a-z0-9-]{1,64}$/', $name[1] ?? '');
            $this->assertNotEmpty($description[1] ?? '');
            $this->assertLessThanOrEqual(1024, strlen($description[1] ?? ''));
        }
    }
}
