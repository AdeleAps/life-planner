<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
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
            'task_review' => ['sometimes', 'array', 'max:500'],
            'task_review.*.scheduled_task_id' => ['required', 'integer', 'distinct', Rule::exists('scheduled_tasks', 'id')],
            'task_review.*.done' => ['required', 'boolean'],
            'task_review.*.action' => ['sometimes', Rule::in(['automatic', 'defer', 'date'])],
            'task_review.*.delay_days' => ['sometimes', 'integer', 'between:1,365'],
            'task_review.*.target_date' => ['sometimes', 'date_format:Y-m-d'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->boolean('day_off') && $this->boolean('dinner_enabled')) {
                $validator->errors()->add('dinner_enabled', 'Dinner and Day Off cannot both be on.');
            }

            $taskReview = $this->input('task_review', []);

            foreach (is_array($taskReview) ? $taskReview : [] as $index => $review) {
                if (! is_array($review) || ($review['done'] ?? true)) {
                    continue;
                }

                if (($review['action'] ?? 'automatic') === 'defer' && ! isset($review['delay_days'])) {
                    $validator->errors()->add("task_review.{$index}.delay_days", 'Choose how many days to wait.');
                }

                if (($review['action'] ?? 'automatic') === 'date') {
                    $date = $review['target_date'] ?? null;
                    $tomorrow = CarbonImmutable::now('Europe/Riga')->addDay()->toDateString();

                    if (! is_string($date) || $date < $tomorrow) {
                        $validator->errors()->add("task_review.{$index}.target_date", 'Choose tomorrow or a later date.');
                    }
                }
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
