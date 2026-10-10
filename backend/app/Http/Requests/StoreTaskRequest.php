<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
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
        $taskType = $this->input('type', 'evening');
        $recurrenceType = $this->input('recurrence_type', 'one_time');
        $isSchedule = $recurrenceType === 'schedule';
        $isCount = $recurrenceType === 'count';
        $scheduleUnit = $this->input('recurrence_rule.unit');
        $isOneTimeEvening = $taskType === 'evening' && $recurrenceType === 'one_time';

        return [
            'client_request_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'special_instructions' => ['nullable', 'string', 'max:5000'],
            'project_id' => [
                'nullable',
                Rule::prohibitedIf($recurrenceType !== 'one_time'),
                'integer',
                Rule::exists('tasks', 'id')->where('item_type', 'project')->where('lifecycle_state', 'active'),
            ],
            'dependency_ids' => ['nullable', 'array'],
            'dependency_ids.*' => [
                'integer',
                'distinct',
                Rule::notIn(array_filter([$this->input('project_id')])),
                Rule::exists('tasks', 'id'),
            ],
            'type' => ['sometimes', Rule::in(['day', 'evening'])],
            'priority' => [
                Rule::prohibitedIf($taskType === 'day'),
                Rule::in(['low', 'standard', 'high']),
            ],
            'energy_cost' => [
                Rule::prohibitedIf($taskType === 'day'),
                Rule::in(['standard', 'high']),
            ],
            'recurrence_type' => [
                Rule::in(['one_time', 'schedule', 'count']),
            ],
            'recurrence_rule' => [
                Rule::requiredIf($isSchedule || $isCount),
                Rule::prohibitedIf($recurrenceType === 'one_time'),
                'array:unit,interval,weekdays,day_of_month,period_unit,period_interval,min_occurrences,max_occurrences',
            ],
            'recurrence_rule.unit' => [
                Rule::requiredIf($isSchedule),
                Rule::prohibitedIf(! $isSchedule),
                Rule::in(['day', 'week', 'month']),
            ],
            'recurrence_rule.interval' => [
                Rule::requiredIf($isSchedule),
                Rule::prohibitedIf(! $isSchedule),
                'integer',
                'between:1,365',
            ],
            'recurrence_rule.weekdays' => [
                Rule::requiredIf($isSchedule && $scheduleUnit === 'week'),
                Rule::prohibitedIf(! $isSchedule || $scheduleUnit !== 'week'),
                'array',
                'min:1',
                'max:7',
            ],
            'recurrence_rule.weekdays.*' => ['integer', Rule::in([0, 1, 2, 3, 4, 5, 6]), 'distinct'],
            'recurrence_rule.day_of_month' => [
                Rule::requiredIf($isSchedule && $scheduleUnit === 'month'),
                Rule::prohibitedIf(! $isSchedule || $scheduleUnit !== 'month'),
                'integer',
                'between:1,31',
            ],
            'recurrence_rule.period_unit' => [
                Rule::requiredIf($isCount),
                Rule::prohibitedIf(! $isCount),
                Rule::in(['week', 'month']),
            ],
            'recurrence_rule.period_interval' => [
                Rule::requiredIf($isCount),
                Rule::prohibitedIf(! $isCount),
                'integer',
                'between:1,120',
            ],
            'recurrence_rule.min_occurrences' => [
                Rule::requiredIf($isCount),
                Rule::prohibitedIf(! $isCount),
                'integer',
                'between:1,99',
            ],
            'recurrence_rule.max_occurrences' => [
                Rule::requiredIf($isCount),
                Rule::prohibitedIf(! $isCount),
                'integer',
                'between:1,99',
                'gte:recurrence_rule.min_occurrences',
            ],
            'is_multi_session' => [
                Rule::prohibitedIf(! $isOneTimeEvening),
                'boolean',
            ],
            'advance_notice_days' => [
                Rule::prohibitedIf($taskType !== 'evening'),
                'nullable',
                'integer',
                'between:1,14',
            ],
            'day_preference' => [
                Rule::prohibitedIf($taskType !== 'evening'),
                'nullable',
                Rule::in(['workday', 'non_workday']),
            ],
            'day_preference_is_hard' => [
                Rule::prohibitedIf($taskType !== 'evening' || ! $this->filled('day_preference')),
                'boolean',
            ],
            'deadline_on' => [
                Rule::prohibitedIf(! $isOneTimeEvening),
                'nullable',
                'date_format:Y-m-d',
            ],
            'deadline_is_hard' => [
                Rule::prohibitedIf(! $isOneTimeEvening || ! $this->filled('deadline_on')),
                'boolean',
            ],
        ];
    }
}
