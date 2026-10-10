<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
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
            'client_request_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'special_instructions' => ['prohibited'],
            'priority' => [Rule::in(['low', 'standard', 'high'])],
            'deadline_on' => ['nullable', 'date_format:Y-m-d'],
            'deadline_is_hard' => [Rule::prohibitedIf(! $this->filled('deadline_on')), 'boolean'],
            'dependency_ids' => ['nullable', 'array'],
            'dependency_ids.*' => ['integer', 'distinct', Rule::exists('tasks', 'id')],
            'type' => ['prohibited'],
            'energy_cost' => ['prohibited'],
            'recurrence_type' => ['prohibited'],
            'recurrence_rule' => ['prohibited'],
            'project_id' => ['prohibited'],
            'is_multi_session' => ['prohibited'],
            'advance_notice_days' => ['prohibited'],
            'day_preference' => ['prohibited'],
            'day_preference_is_hard' => ['prohibited'],
        ];
    }
}
