<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SavePlanningDayInputRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'energy_level' => ['required', Rule::in(['low', 'standard'])],
            'day_off' => ['required', 'boolean'],
            'dinner_enabled' => ['required', 'boolean'],
            'dinner_dish' => [Rule::requiredIf($this->boolean('dinner_enabled')), 'nullable', 'string', 'max:255'],
            'manual_task_ids' => ['sometimes', 'array', 'max:100'],
            'manual_task_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('tasks', 'id')->where('item_type', 'task')->where('lifecycle_state', 'active'),
            ],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->boolean('day_off') && $this->boolean('dinner_enabled')) {
                $validator->errors()->add('dinner_enabled', 'Dinner and Day Off cannot both be on.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('dinner_dish'))) {
            $this->merge(['dinner_dish' => trim($this->input('dinner_dish'))]);
        }
    }
}
