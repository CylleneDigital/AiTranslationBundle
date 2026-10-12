<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;

/**
 * The public contract page names classes, methods, properties and constants as plain
 * text. This guard keeps it honest: rename or remove one of them and this test goes red
 * until the page follows — and, since the page is the contract, until `UPGRADE.md` says so.
 */
final class PublicContractDocsTest extends TestCase
{
    private const string PAGE = 'docs/public-contract.md';

    private const string ROOT_NAMESPACE = 'CylleneDigital\\AiTranslationBundle\\';

    public function testEverySymbolThePageNamesExists(): void
    {
        $content = file_get_contents(\dirname(__DIR__, 3).'/'.self::PAGE);

        self::assertIsString($content, self::PAGE.' is unreadable.');

        $checked = 0;
        $unknown = [];

        foreach (explode("\n", $content) as $line) {
            // A table row whose first cell is a class: | `Override\OverrideReader` | `getFileValue()`, … |
            if (1 !== preg_match('/^\| `([A-Za-z\\\\]+)` \|(.*)\|$/', $line, $row)) {
                continue;
            }

            $name = self::ROOT_NAMESPACE.$row[1];
            ++$checked;

            if (!class_exists($name) && !interface_exists($name)) {
                $unknown[] = $row[1];

                continue;
            }

            $class = new \ReflectionClass($name);

            preg_match_all('/`([^`]+)`/', $row[2], $members);

            foreach ($members[1] as $member) {
                ++$checked;

                if (!self::isPublicMember($class, $member)) {
                    $unknown[] = $row[1].'::'.$member;
                }
            }
        }

        // A page whose tables changed shape would otherwise pass on nothing.
        self::assertGreaterThan(100, $checked, self::PAGE.' no longer lists its symbols in tables this test can read.');
        self::assertSame([], $unknown, self::PAGE.' names symbols the code no longer has — a contract break: restore them, or update the page and UPGRADE.md in a major version.');
    }

    /**
     * `name()` is a method, `$name` a property, anything else a constant or an enum case.
     *
     * @param \ReflectionClass<object> $class
     */
    private static function isPublicMember(\ReflectionClass $class, string $member): bool
    {
        if (str_ends_with($member, '()')) {
            $method = substr($member, 0, -2);

            return $class->hasMethod($method) && $class->getMethod($method)->isPublic();
        }

        if (str_starts_with($member, '$')) {
            $property = substr($member, 1);

            return $class->hasProperty($property) && $class->getProperty($property)->isPublic();
        }

        $constant = $class->getReflectionConstant($member);

        return false !== $constant && $constant->isPublic();
    }
}
