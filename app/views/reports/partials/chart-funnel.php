<?php /** The funnel: stacked bars — each stage as wide as the deals that entered it, cut into moved on, still here and lost. Data: spec (a funnel chart), currency */
echo view('reports/partials/chart-bars.php', ['spec' => chart_funnel_spec($spec), 'currency' => $currency]);
