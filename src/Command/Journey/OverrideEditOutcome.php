<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Command\Journey;

/**
 * What one pass of {@see OverrideEditor::edit()} did — the caller needs more than an
 * exit code: a batch counts what it changed and has to know when the user asked out.
 */
enum OverrideEditOutcome
{
    case Saved;
    case Removed;
    case Untouched;
    /** The user left the batch — the remaining items must not be walked. */
    case Quit;
}
