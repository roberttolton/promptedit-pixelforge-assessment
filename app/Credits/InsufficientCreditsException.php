<?php

namespace App\Credits;

use RuntimeException;

class InsufficientCreditsException extends RuntimeException
{
    /**
     * @param  int  $needed  Nanodollars the generation could cost, at most.
     * @param  int  $available  Nanodollars the user has that are not already reserved.
     */
    public function __construct(public readonly int $needed, public readonly int $available)
    {
        parent::__construct('Not enough credits.');
    }
}
