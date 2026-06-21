<?php

namespace fabianhaef\simpleform\exceptions;

/**
 * Raised by {@see \fabianhaef\simpleform\helpers\Formula} when a Calculation
 * field formula is syntactically invalid (an unexpected character, an unknown
 * function, a malformed reference, or a structural error). Save-time validation
 * catches it and surfaces a translated field error; runtime evaluation of an
 * already-validated formula is total and never throws.
 *
 * @author Fabian Haefliger
 * @since 5.x
 */
class FormulaException extends \RuntimeException
{
}
