<?php

namespace Tests\Unit;

use App\Support\ToolRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ToolUsageTrackingTest extends TestCase
{
    /**
     * Every client-side tool must fire a /api/tool-usage beacon when it
     * actually processes something — either directly in its own module or
     * in one of the local modules it imports. Otherwise the admin
     * tool-statistics page shows no data for that tool.
     */
    #[DataProvider('clientSideToolProvider')]
    public function test_client_side_tool_reports_usage_beacons(string $key, string $js): void
    {
        $seen = [];

        $this->assertTrue(
            $this->moduleReportsUsage($js, $seen),
            "Tool [{$key}] ({$js}) never fires a tool-usage beacon — tool statistics will show no data for it."
        );
    }

    public static function clientSideToolProvider(): array
    {
        $cases = [];

        foreach (ToolRegistry::TOOLS as $key => $config) {
            if (! ($config['client_side'] ?? false)) {
                continue; // server-side tools record usage in PHP (e.g. SMTP tester)
            }

            $cases[$key] = [$key, $config['js']];
        }

        return $cases;
    }

    /**
     * True when the module (or any local module it transitively imports)
     * calls trackToolUsage().
     *
     * @param  list<string>  $seen
     */
    protected function moduleReportsUsage(string $js, array &$seen): bool
    {
        if (in_array($js, $seen, true)) {
            return false;
        }

        $seen[] = $js;

        $path = resource_path('js/'.$js);

        if (! is_file($path)) {
            $this->fail("Tool module missing: {$path}");
        }

        $source = file_get_contents($path);

        if (str_contains($source, 'trackToolUsage')) {
            return true;
        }

        preg_match_all("/from\s+'(\.[^']+)'/", $source, $matches);

        foreach ($matches[1] as $import) {
            $resolved = str_replace('\\', '/', dirname($js).'/'.$import);

            if (! str_ends_with($resolved, '.js')) {
                $resolved .= '.js';
            }

            if ($this->moduleReportsUsage($resolved, $seen)) {
                return true;
            }
        }

        return false;
    }
}
