<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use Symfony\Component\Console\Tester\CommandTester;

/**
 * Base of every interactive-console test: the hub driven by piped answers — one array
 * entry per prompt, an empty string being a plain enter (the default). A final quit
 * answer is appended automatically, since the menu always comes back after a journey.
 */
abstract class HubTestCase extends DatabaseTestCase
{
    /**
     * Runs the hub with the given prompt answers, then quits at the returning menu.
     *
     * @param list<string> $inputs
     */
    protected function runHub(array $inputs): CommandTester
    {
        $tester = $this->hubTester();
        $tester->setInputs(array_merge($inputs, ['']));
        $tester->execute([], ['interactive' => true]);

        return $tester;
    }

    protected function runHubWithoutTty(): CommandTester
    {
        $tester = $this->hubTester();
        $tester->execute([], ['interactive' => false]);

        return $tester;
    }

    private function hubTester(): CommandTester
    {
        return $this->commandTester('cyllene:ai-translation');
    }
}
