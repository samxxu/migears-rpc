<?php

declare(strict_types=1);

namespace MiGears\Rpc\Tests;

use PHPUnit\Framework\TestCase;

class ReadmeLineCountTest extends TestCase
{
    /** How far a stated count may sit from the files before the claim is stale. */
    private const TOLERANCE = 10;

    public function testReadmeStatesTheCurrentSourceLineCount(): void
    {
        // P3-4: the README's line count is a claim about the three src files, and it has
        // gone stale once already — it read ~630 while the files held 762 lines. Pin it to
        // the files themselves, in both halves, so the next drift fails here.
        // P3-4：README 的行数是对三个 src 文件的主张，而它已经过期过一次——文件 762 行时它仍写 ~630。
        // 就它在两半里对着文件本身钉住，让下一次漂移在这里失败。
        $actual = 0;
        foreach (glob(__DIR__ . '/../src/*.php') ?: [] as $file) {
            $actual += count(file($file) ?: []);
        }
        $this->assertGreaterThan(0, $actual);

        $halves = explode("\n---\n", (string) file_get_contents(__DIR__ . '/../README.md'));
        $this->assertCount(2, $halves, 'the README is no longer in two halves');

        $english = [];
        $chinese = [];
        preg_match_all('/(\d+) lines/', $halves[0], $english);
        preg_match_all('/约 (\d+) 行/', $halves[1], $chinese);

        $this->assertArrayHasKey(1, $english, 'the English half states no line count');
        $this->assertArrayHasKey(1, $chinese, 'the Chinese half states no line count');

        foreach (array_merge($english[1], $chinese[1]) as $claim) {
            $this->assertEqualsWithDelta(
                $actual,
                (int) $claim,
                self::TOLERANCE,
                "the README claims $claim lines; src holds $actual"
            );
        }
    }
}
