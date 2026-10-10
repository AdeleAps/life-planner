<?php

namespace App\Console\Commands;

use App\Services\PlanningBriefBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

#[Signature('planner:preview-brief {date? : Planning date in YYYY-MM-DD format; defaults to tomorrow}')]
#[Description('Show the read-only planning brief without calling AI or changing planner data')]
class PreviewPlanningBrief extends Command
{
    public function handle(PlanningBriefBuilder $builder): int
    {
        $date = $this->argument('date') ?? CarbonImmutable::now('Europe/Riga')->addDay()->toDateString();

        if (Validator::make(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->fails()) {
            $this->components->error('Use a real date in YYYY-MM-DD format.');

            return self::FAILURE;
        }

        $targetDate = CarbonImmutable::parse($date, 'Europe/Riga');
        $this->line(json_encode($builder->build($targetDate), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
