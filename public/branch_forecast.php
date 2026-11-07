<?php
require_once __DIR__ . '/../app/bootstrap.php';

$branchId = $_GET['branch'] ?? '';
$user = require_branch_access($branchId);
$pdo = db();

$stmt = $pdo->prepare('SELECT * FROM branches WHERE id = ?');
$stmt->execute([$branchId]);
$branch = $stmt->fetch();
if (!$branch) { http_response_code(404); die('Branch not found.'); }

$ready = ForecastService::available($pdo);
$rows = $ready ? ForecastService::forBranch($pdo, $branchId) : [];
$risks = $ready ? ForecastService::expiryRisk($pdo, $branchId) : [];

$counts = array_count_values(array_column($rows, 'status'));
$atRiskUnits = array_sum(array_column($risks, 'at_risk_quantity'));
$generatedAt = $rows ? max(array_column($rows, 'generated_at')) : null;
$stale = $generatedAt && strtotime($generatedAt) < time() - 36 * 3600;
$modelsUsed = array_unique(array_column($rows, 'model'));
$modelCounts = array_count_values(array_column($rows, 'model'));
arsort($modelCounts);
$mainModel = array_key_first($modelCounts);
$leadTime = (int)env('FORECAST_LEAD_TIME_DAYS', '5');
$coverDays = (int)env('FORECAST_COVER_DAYS', '14');

// The product drawn in the chart: ?product= if it belongs to this branch, else the most urgent one.
$selected = $rows[0] ?? null;
foreach ($rows as $r) {
    if ((int)$r['product_id'] === (int)($_GET['product'] ?? 0)) { $selected = $r; }
}
$chart = $selected ? ForecastService::chartSeries($pdo, (int)$selected['product_id']) : null;

// Display rules: calculations keep full precision; the page shows whole numbers.
// Days left is rounded DOWN (4.8 days of stock means it runs out on the 5th day, so 4 full days).
function days_left_label(?string $days): string
{
    if ($days === null) return '—';
    return (float)$days >= 365 ? '365+' : (string)(int)floor((float)$days);
}

function per_day(float $v): string
{
    return number_format(round($v));
}

$pageTitle = "Forecast — {$branch['name']}";
require __DIR__ . '/../app/Views/partials/head.php';
$active = 'forecast';
require __DIR__ . '/../app/Views/partials/nav_branch.php';
$base = '/branch_forecast.php?branch=' . urlencode($branchId);
?>
<div class="container-xl py-4">
  <div class="d-flex justify-content-between align-items-end flex-wrap gap-2 mb-3">
    <div>
      <h1 class="h6 text-faint text-uppercase mb-1" style="letter-spacing:.08em">Demand Forecast — <?= e($branch['name']) ?></h1>
      <p class="text-faint small mb-0">Predicted sales for the next 14 days, and what they mean for stock on the shelf.</p>
    </div>
    <?php if ($generatedAt): ?>
      <div class="text-faint small mono text-end">
        Updated <?= e(date('D j M, H:i', strtotime($generatedAt))) ?> ·
        <?= e(implode(', ', array_map(fn($m) => ForecastService::MODEL_LABELS[$m] ?? $m, $modelsUsed))) ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if (!$ready): ?>
    <div class="card-stockwise p-4 text-faint">The forecast tables don't exist yet. Import <span class="mono">database/ml_migration.sql</span>, then run the forecast job (<span class="mono">ml/run_forecast.bat</span>).</div>
  <?php elseif (!$rows): ?>
    <div class="card-stockwise p-4 text-faint">No forecast has been run yet. Run <span class="mono">ml/run_forecast.bat</span> (or <span class="mono">python ml/forecast_job.py</span>); forecasts appear here once it finishes.</div>
  <?php else: ?>
    <?php if ($stale): ?>
      <div class="card-stockwise px-3 py-2 mb-3 small" style="background:#F5A62322;color:var(--orange)">This forecast is more than a day old. Check that the nightly forecast job is still scheduled.</div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
      <div class="col-md-4"><div class="card-stockwise stat-card p-3" style="border-top-color:var(--red)"><div class="stat-label">Runs out before delivery</div><div class="stat-value"><?= $counts['critical'] ?? 0 ?></div><div class="text-faint mono small">stock gone within <?= $leadTime ?> days</div></div></div>
      <div class="col-md-4"><div class="card-stockwise stat-card p-3" style="border-top-color:var(--orange)"><div class="stat-label">Need ordering</div><div class="stat-value"><?= ($counts['critical'] ?? 0) + ($counts['reorder'] ?? 0) ?></div><div class="text-faint mono small">at or below reorder point</div></div></div>
      <div class="col-md-4"><div class="card-stockwise stat-card p-3" style="border-top-color:#A78BFA"><div class="stat-label">At risk of expiring unsold</div><div class="stat-value"><?= fmt($atRiskUnits) ?></div><div class="text-faint mono small">units across <?= count($risks) ?> batch(es)</div></div></div>
    </div>

    <div class="card-stockwise p-3 mb-4">
      <div class="d-flex justify-content-between flex-wrap gap-2 mb-2">
        <h2 class="h6 mb-0"><?= e($selected['name']) ?> <span class="text-faint fw-normal small">· last 28 days sold, next 14 days forecast</span></h2>
        <span class="small mono text-faint">average forecast: <?= per_day((float)$selected['avg_daily_demand']) ?> <?= e(['unit' => 'units', 'kg' => 'kg', 'pack' => 'packs', 'box' => 'boxes'][$selected['unit_of_measure']] ?? $selected['unit_of_measure']) ?> per day</span>
      </div>
      <div style="height:260px"><canvas id="forecastChart"></canvas></div>
    </div>

    <div class="card-stockwise mb-4">
      <div class="table-responsive">
        <table class="table table-stockwise mb-0 align-middle small">
          <thead><tr>
            <th>Product</th><th class="text-end">On hand</th><th class="text-end">Forecast (units/day)</th>
            <th class="text-end">Days left (days)</th><th>Runs out (date)</th><th class="text-end">Reorder point (units)</th>
            <th class="text-end">Suggested order</th><th>Status</th>
          </tr></thead>
          <tbody>
          <?php foreach ($rows as $r): $s = ForecastService::STATUS[$r['status']]; $isSel = $r['product_id'] == $selected['product_id']; ?>
            <tr<?= $isSel ? ' style="--bs-table-bg:var(--border)"' : '' ?>>
              <td>
                <a href="<?= $base ?>&product=<?= (int)$r['product_id'] ?>" class="text-reset text-decoration-none d-flex align-items-center gap-2">
                  <span class="dot" style="background:<?= category_color($r['category']) ?>"></span><?= e($r['name']) ?>
                </a>
                <?php if ($r['model'] !== $mainModel): ?><div class="text-faint" style="font-size:.7rem">short history: <?= e(ForecastService::MODEL_LABELS[$r['model']] ?? $r['model']) ?></div><?php endif; ?>
              </td>
              <td class="text-end mono"><?= fmt((int)$r['sellable_quantity']) ?> <span class="text-faint"><?= e($r['unit_of_measure']) ?></span></td>
              <td class="text-end mono"><?= per_day((float)$r['avg_daily_demand']) ?></td>
              <td class="text-end mono" style="color:<?= $s['color'] ?>"><?= days_left_label($r['days_until_stockout']) ?></td>
              <td class="mono text-faint"><?= $r['stockout_date'] ? e(date('D j M', strtotime($r['stockout_date']))) : '—' ?></td>
              <td class="text-end mono"><?= fmt((int)$r['reorder_point']) ?></td>
              <td class="text-end mono fw-semibold"><?= $r['suggested_order_qty'] > 0 ? fmt((int)$r['suggested_order_qty']) . ' <span class="text-faint fw-normal">' . e($r['unit_of_measure']) . '</span>' : '<span class="text-faint fw-normal">—</span>' ?></td>
              <td><span class="badge" style="color:<?= $s['color'] ?>;background:<?= $s['bg'] ?>"><?= e($s['label']) ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <h2 class="h6 mb-2">Stock likely to expire before it sells</h2>
    <?php if (!$risks): ?>
      <div class="card-stockwise p-3 text-faint small mb-4">At the forecast rate of sale, every batch with an expiry date will sell before it expires.</div>
    <?php else: ?>
      <div class="card-stockwise mb-4">
        <div class="table-responsive">
          <table class="table table-stockwise mb-0 align-middle small">
            <thead><tr><th>Product</th><th>Batch</th><th>Expires (date)</th><th class="text-end">Remaining</th><th class="text-end">Expected to sell (units)</th><th class="text-end">At risk</th><th>Suggested action</th></tr></thead>
            <tbody>
            <?php foreach ($risks as $k): $days = ExpiryService::daysUntil($k['expiry_date']); ?>
              <tr>
                <td><?= e($k['name']) ?></td>
                <td class="mono text-faint"><?= $k['barcode'] !== '' ? e($k['barcode']) : '#' . (int)$k['batch_id'] ?></td>
                <td class="mono"><?= e(date('j M Y', strtotime($k['expiry_date']))) ?> <span class="text-faint">(<?= $days < 0 ? abs($days) . 'd ago' : ($days === 0 ? 'today' : "in {$days}d") ?>)</span></td>
                <td class="text-end mono"><?= fmt((int)$k['quantity_remaining']) ?></td>
                <td class="text-end mono"><?= fmt((int)$k['expected_sold']) ?></td>
                <td class="text-end mono fw-semibold" style="color:var(--red)"><?= fmt((int)$k['at_risk_quantity']) ?> <span class="text-faint fw-normal"><?= e($k['unit_of_measure']) ?></span></td>
                <td class="text-faint"><?= $days < 0 ? 'Expired: remove from the shelf' : 'Discount it, or transfer to a busier branch' ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <p class="text-faint small mb-0">
      How to read this: <b>days left</b> replays the forecast against the stock on the shelf, selling the soonest-expiring batch first and skipping expired stock.
      <b>Reorder point</b> = forecast demand over a <?= $leadTime ?>-day supplier lead time + safety stock (about a 95% chance of not running out while an order is on its way).
      <b>Suggested order</b> covers the lead time plus <?= $coverDays ?> days of demand.
    </p>
  <?php endif; ?>
</div>

<?php if ($chart): ?>
<script>
new Chart(document.getElementById('forecastChart'), {
  type: 'line',
  data: {
    labels: <?= json_encode(array_map(fn($d) => date('j M', strtotime($d)), $chart['labels'])) ?>,
    datasets: [
      { label: 'Sold', data: <?= json_encode($chart['actual']) ?>, borderColor: '#9ca3af', backgroundColor: '#9ca3af', borderWidth: 1.5, pointRadius: 2, tension: 0.2, spanGaps: false },
      { label: 'Forecast', data: <?= json_encode($chart['forecast']) ?>, borderColor: '#4F8EF7', backgroundColor: '#4F8EF730', borderWidth: 2, borderDash: [5, 4], pointRadius: 0, fill: true, tension: 0.3 }
    ]
  },
  options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
    plugins: { legend: { labels: { color: '#9ca3af', boxWidth: 12 } },
               tooltip: { callbacks: { label: c => c.raw === null ? null : `${c.dataset.label}: ${Math.round(Number(c.raw))} units` } } },
    scales: { x: { ticks: { color: '#6b7280', maxTicksLimit: 14 }, grid: { display: false } },
              y: { beginAtZero: true, title: { display: true, text: 'Units per day', color: '#6b7280' }, ticks: { color: '#6b7280', precision: 0 }, grid: { color: '#1e2235' } } } }
});
</script>
<?php endif; ?>
<?php require __DIR__ . '/../app/Views/partials/foot.php'; ?>
