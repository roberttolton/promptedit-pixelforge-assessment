<?php

namespace App\Http\Requests;

use App\Enums\CreditPack;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseCreditsRequest extends FormRequest
{
    /**
     * @return array<string, list<ValidationRule|string|Rule>>
     */
    public function rules(): array
    {
        return [
            'pack' => ['required', Rule::enum(CreditPack::class)],
            // Made by the page when it loads, so a double-click or a retry is the same purchase.
            'purchase_id' => ['required', 'uuid'],
        ];
    }

    public function pack(): CreditPack
    {
        return CreditPack::from($this->validated('pack'));
    }
}
