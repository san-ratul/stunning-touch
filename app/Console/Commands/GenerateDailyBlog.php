<?php

namespace App\Console\Commands;

use App\Models\AiGeneration;
use App\Models\AutomationSetting;
use App\Services\Ai\BlogGeneratorService;
use Illuminate\Console\Command;

class GenerateDailyBlog extends Command
{
    protected $signature = 'blog:generate-daily {--force : Run even if already generated today}';

    protected $description = 'Generate one AI-assisted blog draft per configured frequency for admin review';

    public function handle(BlogGeneratorService $generator): int
    {
        $automation = AutomationSetting::current();

        if (! $automation->is_enabled) {
            $this->info('Blog automation is disabled.');

            return self::SUCCESS;
        }

        // The command runs on every scheduler tick, so due-ness is decided
        // here: this keeps automation_settings.preferred_time and timezone
        // authoritative without redeploys when the schedule entry fires.
        $timezone = new \DateTimeZone($automation->timezone ?: config('app.timezone'));
        $now = now($timezone);

        if (! $this->option('force')) {
            if ($automation->frequency === 'weekly' && $now->dayOfWeekIso !== 1) {
                $this->info('Weekly schedule: generation runs on Mondays only.');

                return self::SUCCESS;
            }

            if ($now->format('H:i') < $automation->preferred_time) {
                $this->info("Before the preferred time ({$automation->preferred_time} {$automation->timezone}). Skipping.");

                return self::SUCCESS;
            }

            $alreadyToday = AiGeneration::query()
                ->where('status', 'completed')
                ->whereDate('created_at', $now->toDateString())
                ->exists();

            if ($alreadyToday) {
                $this->info('A generation already completed today. Skipping.');

                return self::SUCCESS;
            }
        }

        $result = $generator->generateFromAutomationSettings();

        AutomationSetting::query()->update(['last_run_at' => now()]);

        $this->info($result['message']);

        return $result['success'] ? self::SUCCESS : self::FAILURE;
    }
}
