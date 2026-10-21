<?php

namespace App\Services\Billing;

use App\Models\CmsPage;
use App\Models\PricingPlan;
use App\Support\Billing\UsageMetrics;
use App\Support\PublicStorageUrl;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * The pricing plans PDF (A4 portrait, Dompdf): staff-portal branding, the
 * active plans sorted by monthly base price, a per-metric comparison of
 * included usage and extra prices, the billing formula and a worked example
 * computed with the real BillingCalculator.
 */
class PricingPlanPdfService
{
    public function __construct(private readonly BillingCalculator $calculator) {}

    public function download(): Response
    {
        $data = $this->viewData();

        $pdf = Pdf::loadView('pdf.pricing-plans', $data)
            ->setPaper('a4', 'portrait')
            ->setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans']);

        // Dompdf has no CSS counter for the total page count; stamp
        // "Page X of Y" on every page after layout instead.
        $dompdf = $pdf->getDomPDF();
        $dompdf->render();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() - 100, $canvas->get_height() - 34, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7.5, [0.42, 0.48, 0.44]);

        return new Response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="pricing-plans-'.now('UTC')->format('Y-m-d').'.pdf"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function viewData(): array
    {
        $plans = PricingPlan::query()->active()->with('metrics')
            ->orderBy('base_price')->orderBy('sort_order')->orderBy('name')->get();

        $metrics = UsageMetrics::all();
        $planRows = $plans->map(fn (PricingPlan $plan) => [
            'name' => $plan->name,
            'description' => $plan->description,
            'currency' => $plan->currency,
            'base_price' => $this->money((string) $plan->base_price),
            'is_default' => (bool) $plan->is_default,
            'metrics' => collect($plan->metricConfigs())->map(fn ($config, $key) => [
                'included' => $this->quantity($config['included_quantity'], $metrics[$key]),
                'price' => (float) $config['unit_price'] > 0
                    ? $this->money($config['unit_price'], 4).' per '.$this->quantity($config['billing_unit'], $metrics[$key])
                        .((float) $config['metric_factor'] !== 1.0 ? ' × '.$this->trim($config['metric_factor']) : '')
                    : 'No extra charge',
            ])->all(),
        ])->all();

        return [
            'brand' => $this->brand(),
            'generatedAt' => now('UTC'),
            'plans' => $planRows,
            'planChunks' => array_chunk($planRows, 3),
            'metrics' => $metrics,
            'example' => $this->example($this->examplePlan($plans)),
        ];
    }

    /** The default plan when it charges for usage, else the cheapest active plan that does. */
    private function examplePlan(Collection $plans): ?PricingPlan
    {
        $charges = fn (PricingPlan $plan) => collect($plan->metricConfigs())->contains(fn ($c) => (float) $c['unit_price'] > 0);
        $default = $plans->firstWhere('is_default', true);

        return ($default && $charges($default) ? $default : null)
            ?? $plans->first(fn (PricingPlan $plan) => $charges($plan))
            ?? $default
            ?? $plans->first();
    }

    /** A worked example: a few priced metrics used past their allowance, priced by BillingCalculator. */
    private function example(?PricingPlan $plan): ?array
    {
        if (! $plan) {
            return null;
        }

        $configs = $plan->metricConfigs();
        $usage = [];
        $priced = 0;
        foreach ($configs as $key => $config) {
            $included = (float) $config['included_quantity'];
            if ((float) $config['unit_price'] > 0 && $priced < 3) {
                // Two and a half billing units over; whole numbers for count metrics.
                $over = 2.5 * (float) $config['billing_unit'];
                if (UsageMetrics::get($key)['decimals'] === 0) {
                    $over = max(1, round($over));
                }
                $usage[$key] = (string) ($included + $over);
                $priced++;
            } else {
                $usage[$key] = (string) $included;
            }
        }

        $bill = $this->calculator->calculate($plan, $usage, '1');
        $metrics = UsageMetrics::all();

        return [
            'plan' => $plan->name,
            'currency' => $plan->currency,
            'base_price' => $this->money($bill['base_price']),
            'lines' => collect($bill['lines'])
                ->filter(fn ($line) => (float) $line['line_total'] > 0)
                ->map(fn ($line) => [
                    'label' => $line['label'],
                    'usage' => $this->quantity($line['usage'], $metrics[$line['key']]),
                    'included' => $this->quantity($line['included'], $metrics[$line['key']]),
                    'excess' => $this->quantity($line['excess'], $metrics[$line['key']]),
                    'calculation' => $this->trim($line['excess']).' ÷ '.$this->trim($line['billing_unit']).' × '.$this->money($line['unit_price'], 4)
                        .($line['metric_factor'] !== '1' ? ' × '.$line['metric_factor'] : ''),
                    'total' => $this->money($line['line_total']),
                ])->values()->all(),
            'subtotal' => $this->money($bill['subtotal']),
            'total' => $this->money($bill['total']),
        ];
    }

    private function brand(): array
    {
        $content = CmsPage::query()->where('slug', CmsPage::SLUG_PORTAL_BRANDING)->value('content');
        $content = is_string($content) ? json_decode($content, true) : ($content ?? []);
        $title = $content['site_title'] ?? [];

        return [
            'primary_text' => $title['primary_text'] ?? 'Camp',
            'secondary_text' => $title['secondary_text'] ?? 'Manager',
            'primary_color' => $this->color($title['primary_color'] ?? null, '#16301f'),
            'secondary_color' => $this->color($title['secondary_color'] ?? null, '#f26522'),
            'tagline' => $content['tagline'] ?? 'Reservations · Sites · Payments',
            'logo' => $this->logoDataUri($content['logo_url'] ?? null),
        ];
    }

    /** Embeds an uploaded PNG/JPEG/GIF logo (Dompdf never fetches remote files here). */
    private function logoDataUri(?string $url): ?string
    {
        if (! PublicStorageUrl::isOwnStoragePath($url)) {
            return null;
        }
        $disk = Storage::disk('public');
        $path = PublicStorageUrl::diskRelativePath($url);
        if (! $disk->exists($path) || $disk->size($path) > 2 * 1024 * 1024) {
            return null;
        }
        $mime = $disk->mimeType($path);
        if (! in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true)) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($disk->get($path));
    }

    private function color(?string $value, string $fallback): string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $value) ? $value : $fallback;
    }

    private function money(string $amount, int $maxDecimals = 2): string
    {
        $value = (float) $amount;
        $decimals = $maxDecimals > 2 && round($value, 2) != $value ? $maxDecimals : 2;

        return number_format($value, $decimals);
    }

    private function quantity(?string $value, array $metric): string
    {
        if ($value === null) {
            return '—';
        }

        return $this->trim($value).' '.((float) $value === 1.0 ? $metric['unit'] : $metric['unit_plural']);
    }

    private function trim(string $value): string
    {
        if (! str_contains($value, '.')) {
            return number_format((float) $value);
        }
        [$whole, $fraction] = explode('.', $value);
        $fraction = rtrim($fraction, '0');

        return number_format((float) $whole).($fraction !== '' ? '.'.$fraction : '');
    }
}
