<?php

declare(strict_types=1);

namespace FormBridge\Form;

use Symfony\Component\Validator\Constraint;

/**
 * Puts a validation rule into words for the agent, for constraints FormBridge does not describe.
 */
interface ConstraintDescriberInterface
{
    /**
     * @return string|null a short sentence such as "Letters and digits only.", or null if the constraint is not known
     */
    public function describe(Constraint $constraint): ?string;
}
