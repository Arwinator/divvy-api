<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateBillRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'total_amount' => 'required|numeric|min:0.01',
            'bill_date' => 'required|date',
            'group_id' => 'required|exists:groups,id',
            'split_type' => 'required|in:equal,custom',
            'shares' => 'required_if:split_type,custom|array',
            'shares.*.user_id' => 'required_with:shares|exists:users,id',
            'shares.*.amount' => 'required_with:shares|numeric|min:0.01',
        ];
    }

    /**
     * Configure the validator instance.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @return void
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // Only validate sum for custom split
            if ($this->split_type === 'custom' && $this->has('shares')) {
                $total = collect($this->shares)->sum('amount');
                $expectedTotal = $this->total_amount;

                // Allow small rounding tolerance (0.01)
                if (abs($total - $expectedTotal) > 0.01) {
                    $validator->errors()->add('shares', 
                        "The sum of shares ({$total}) must equal the total amount ({$expectedTotal})");
                }
            }
        });
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Bill title is required',
            'total_amount.required' => 'Total amount is required',
            'total_amount.numeric' => 'Total amount must be a number',
            'total_amount.min' => 'Total amount must be greater than zero',
            'bill_date.required' => 'Bill date is required',
            'bill_date.date' => 'Bill date must be a valid date',
            'group_id.required' => 'Group ID is required',
            'group_id.exists' => 'The selected group does not exist',
            'split_type.required' => 'Split type is required',
            'split_type.in' => 'Split type must be either equal or custom',
            'shares.required_if' => 'Shares are required for custom split',
            'shares.array' => 'Shares must be an array',
            'shares.*.user_id.required_with' => 'User ID is required for each share',
            'shares.*.user_id.exists' => 'One or more users do not exist',
            'shares.*.amount.required_with' => 'Amount is required for each share',
            'shares.*.amount.numeric' => 'Share amount must be a number',
            'shares.*.amount.min' => 'Share amount must be greater than zero',
        ];
    }
}
