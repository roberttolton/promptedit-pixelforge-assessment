<?php

namespace App\Enums;

enum CreditTransactionType: string
{
    /** Credits added by a (fake) purchase; the amount is positive. */
    case PURCHASE = 'purchase';

    /** Credits spent on a succeeded generation; the amount is negative. */
    case CHARGE = 'charge';
}
