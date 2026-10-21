<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Pricing Plans</title>
<style>
    @page { margin: 30mm 16mm 22mm 16mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #1f2a24; line-height: 1.45; }
    header { position: fixed; top: -22mm; left: 0; right: 0; height: 17mm; border-bottom: 1.5pt solid {{ $brand['primary_color'] }}; }
    footer { position: fixed; bottom: -14mm; left: 0; right: 0; height: 10mm; border-top: 0.5pt solid #cfd8d2; font-size: 7.5pt; color: #6b7a71; }
    .brand-name { font-size: 15pt; font-weight: bold; }
    .muted { color: #6b7a71; }
    h1 { font-size: 18pt; margin: 0 0 2mm 0; color: {{ $brand['primary_color'] }}; }
    h2 { font-size: 12pt; margin: 7mm 0 3mm 0; color: {{ $brand['primary_color'] }}; }
    table { width: 100%; border-collapse: collapse; }
    .cards td.card { width: 33.33%; vertical-align: top; padding: 0 2mm; }
    .card-box { border: 0.75pt solid #cfd8d2; border-radius: 4pt; padding: 4mm; }
    .card-box.default { border: 1.5pt solid {{ $brand['secondary_color'] }}; background: #fff7f1; }
    .plan-name { font-size: 12pt; font-weight: bold; color: {{ $brand['primary_color'] }}; }
    .price { font-size: 17pt; font-weight: bold; margin-top: 2mm; }
    .badge { display: inline-block; font-size: 7pt; font-weight: bold; color: #ffffff; background: {{ $brand['secondary_color'] }}; padding: 0.6mm 2mm; border-radius: 2pt; }
    .compare th, .compare td { border: 0.5pt solid #cfd8d2; padding: 2mm; text-align: left; vertical-align: top; }
    .compare th { background: #eef3ef; font-size: 8.5pt; }
    .compare th.default-col { background: #fde9dc; }
    .compare td.metric { width: 26%; font-weight: bold; }
    .small { font-size: 8pt; }
    .formula { border: 0.75pt solid #cfd8d2; background: #f6f8f6; padding: 3mm 4mm; margin: 2mm 0; font-family: 'DejaVu Sans Mono', monospace; font-size: 8.5pt; }
    .example th, .example td { border-bottom: 0.5pt solid #cfd8d2; padding: 1.6mm 2mm; text-align: left; }
    .example td.num, .example th.num { text-align: right; }
    .total-row td { font-weight: bold; border-top: 1pt solid #1f2a24; }
    .avoid-break { page-break-inside: avoid; }
</style>
</head>
<body>
<header>
    <table><tr>
        <td style="width: 60%;">
            @if ($brand['logo'])
                <img src="{{ $brand['logo'] }}" style="height: 9mm; vertical-align: middle; margin-right: 2mm;" alt="">
            @endif
            <span class="brand-name"><span style="color: {{ $brand['primary_color'] }};">{{ $brand['primary_text'] }}</span> <span style="color: {{ $brand['secondary_color'] }};">{{ $brand['secondary_text'] }}</span></span>
            <div class="muted small">{{ $brand['tagline'] }}</div>
        </td>
        <td style="text-align: right;" class="muted small">Pricing Plans<br>Generated {{ $generatedAt->format('F j, Y') }} (UTC)</td>
    </tr></table>
</header>

<footer>
    <table><tr>
        <td>{{ $brand['primary_text'] }} {{ $brand['secondary_text'] }} — pricing plans, generated {{ $generatedAt->format('Y-m-d H:i') }} UTC. Prices are estimates; usage is billed monthly.</td>
        <td style="width: 22%;"></td>
    </tr></table>
</footer>

<main>
    <h1>Pricing Plans</h1>
    <p class="muted">Every plan has a monthly base price that includes an allowance for each usage metric. Usage above the allowance is charged per billing unit. Plans are listed from the lowest to the highest monthly base price.</p>

    @if (count($plans) === 0)
        <p><strong>There are no active pricing plans.</strong></p>
    @endif

    @foreach ($planChunks as $chunk)
        <table class="cards avoid-break" style="margin: 3mm -2mm;">
            <tr>
                @foreach ($chunk as $plan)
                    <td class="card">
                        <div class="card-box {{ $plan['is_default'] ? 'default' : '' }}">
                            @if ($plan['is_default'])<span class="badge">DEFAULT PLAN</span><br>@endif
                            <span class="plan-name">{{ $plan['name'] }}</span>
                            <div class="price">{{ $plan['currency'] }} {{ $plan['base_price'] }}</div>
                            <div class="small muted">per month</div>
                            @if ($plan['description'])<div class="small muted" style="margin-top: 2mm;">{{ $plan['description'] }}</div>@endif
                        </div>
                    </td>
                @endforeach
                @for ($i = count($chunk); $i < 3; $i++)<td class="card"></td>@endfor
            </tr>
        </table>
    @endforeach

    @foreach ($planChunks as $chunk)
        <h2>{{ $loop->first ? 'Included usage and extra prices' : 'Included usage and extra prices (continued)' }}</h2>
        <table class="compare">
            <thead>
                <tr>
                    <th>Metric</th>
                    @foreach ($chunk as $plan)
                        <th class="{{ $plan['is_default'] ? 'default-col' : '' }}">{{ $plan['name'] }}@if ($plan['is_default']) (default)@endif</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($metrics as $key => $metric)
                    <tr class="avoid-break">
                        <td class="metric">{{ $metric['label'] }}@if ($metric['estimated'])*@endif<div class="small muted" style="font-weight: normal;">{{ $metric['help'] }}</div></td>
                        @foreach ($chunk as $plan)
                            <td class="small">
                                <strong>Included:</strong> {{ $plan['metrics'][$key]['included'] }}<br>
                                <strong>Extra:</strong> {{ $plan['metrics'][$key]['price'] }}
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endforeach
    <p class="small muted">* Database storage is an estimate of the space the organization's records use, including a proportional share of indexes. 1 GB = 1,073,741,824 bytes.</p>

    <div class="avoid-break">
        <h2>How the monthly bill is calculated</h2>
        <p>Each organization is billed on its assigned plan (or the default plan). For every metric, only usage above the plan's included allowance is charged:</p>
        <div class="formula">
            extra charge = max(0, usage − included) ÷ billing unit × unit price × metric factor<br>
            subtotal = monthly base price + sum of extra charges (each rounded to 2 decimals)<br>
            estimated total = subtotal × organization pricing factor (rounded to 2 decimals)
        </div>
        <p class="small">Usage within the allowance costs nothing beyond the base price. Usage is charged proportionally to the billing unit. Storage is the average of the daily measurements in the month, members the highest daily count, and record counts the latest measurement. An organization pricing factor of 1 is list price; 0.9 is a 10% discount.</p>
    </div>

    @if ($example)
        <div class="avoid-break">
            <h2>Worked example — {{ $example['plan'] }}</h2>
            @if (count($example['lines']) === 0)
                <p>This plan charges nothing beyond its base price, so a month on it costs {{ $example['currency'] }} {{ $example['base_price'] }}.</p>
            @else
                <p class="small">An organization on {{ $example['plan'] }} that uses more than its allowance of the metrics below, and stays within the rest:</p>
                <table class="example">
                    <thead><tr><th>Metric</th><th>Used</th><th>Included</th><th>Calculation</th><th class="num">Charge</th></tr></thead>
                    <tbody>
                        <tr><td>Monthly base price</td><td></td><td></td><td></td><td class="num">{{ $example['currency'] }} {{ $example['base_price'] }}</td></tr>
                        @foreach ($example['lines'] as $line)
                            <tr><td>{{ $line['label'] }}</td><td>{{ $line['usage'] }}</td><td>{{ $line['included'] }}</td><td class="small">{{ $line['calculation'] }}</td><td class="num">{{ $example['currency'] }} {{ $line['total'] }}</td></tr>
                        @endforeach
                        <tr class="total-row"><td colspan="4">Estimated monthly total (pricing factor 1)</td><td class="num">{{ $example['currency'] }} {{ $example['total'] }}</td></tr>
                    </tbody>
                </table>
            @endif
        </div>
    @endif
</main>
</body>
</html>
