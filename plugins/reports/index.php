<?php
// Plugin de informes: horas trabajadas por año y mes, con filtros, exportar CSV y gráfico
$user = current_user();
$pdo = get_pdo();

$selected_year = isset($_GET['year']) ? intval($_GET['year']) : null;
$export_csv = isset($_GET['export_csv']) ? true : false;

// Obtener años disponibles
$years_stmt = $pdo->prepare('SELECT DISTINCT YEAR(date) as y FROM entries WHERE user_id = ? ORDER BY y DESC');
$years_stmt->execute([$user['id']]);
$years = array_column($years_stmt->fetchAll(PDO::FETCH_ASSOC), 'y');

// Consulta: horas trabajadas por año y mes
$params = [$user['id']];
$where = '';
if ($selected_year) {
    $where = 'AND YEAR(date) = ?';
    $params[] = $selected_year;
}
$stmt = $pdo->prepare('
    SELECT YEAR(date) as year, MONTH(date) as month,
           SUM(TIMESTAMPDIFF(MINUTE, start, end))/60 as horas
    FROM entries
    WHERE user_id = ? AND start IS NOT NULL AND end IS NOT NULL ' . $where . '
    GROUP BY YEAR(date), MONTH(date)
    ORDER BY year DESC, month DESC
');
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Agrupar por año
$data = [];
foreach ($rows as $r) {
    $data[$r['year']][(int)$r['month']] = round($r['horas'], 2);
}

// Nombres de meses
$meses = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];

// Exportar CSV
if ($export_csv) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="horas_trabajadas_' . ($selected_year ?: 'todos') . '.csv"');
    echo "Año,Mes,Horas\n";
    foreach ($data as $year => $meses_data) {
        foreach ($meses as $num => $nombre) {
            $horas = isset($meses_data[$num]) ? $meses_data[$num] : 0;
            echo "$year,$nombre,$horas\n";
        }
    }
    exit;
}
?>
<h2>Horas trabajadas por Año y Mes</h2>
<form method="get" action="plugin_wrapper.php" style="margin-bottom:1em; display:flex; gap:1em; align-items:center;">
  <input type="hidden" name="plugin" value="reports">
  <label>Año:
    <select name="year" onchange="this.form.submit()">
      <option value="">Todos</option>
      <?php foreach ($years as $y): ?>
        <option value="<?php echo $y; ?>" <?php if ($selected_year == $y) echo 'selected'; ?>><?php echo $y; ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <button type="submit">Filtrar</button>
</form>

<?php foreach ($data as $year => $meses_data):
    $total_anual = array_sum($meses_data);
?>
  <h3><?php echo htmlspecialchars($year); ?> <span style="font-size:0.9em; color:#4a90e2;">(Total: <?php echo round($total_anual,2); ?> h)</span></h3>
  <table>
    <thead><tr><th>Mes</th><th>Horas trabajadas</th></tr></thead>
    <tbody>
      <?php foreach ($meses as $num => $nombre): ?>
        <tr>
          <td><?php echo htmlspecialchars($nombre); ?></td>
          <td><?php echo isset($meses_data[$num]) ? htmlspecialchars($meses_data[$num]) : 0; ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <canvas id="chart_<?php echo $year; ?>" width="600" height="200" style="margin:1em 0;"></canvas>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script>
    const ctx_<?php echo $year; ?> = document.getElementById('chart_<?php echo $year; ?>').getContext('2d');
    new Chart(ctx_<?php echo $year; ?>, {
      type: 'bar',
      data: {
        labels: <?php echo json_encode(array_values($meses)); ?>,
        datasets: [{
          label: 'Horas trabajadas',
          data: <?php echo json_encode(array_map(function($num) use ($meses_data) { return isset($meses_data[$num]) ? $meses_data[$num] : 0; }, array_keys($meses))); ?>,
          backgroundColor: 'rgba(74, 144, 226, 0.6)'
        }]
      },
      options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } }
      }
    });
  </script>
<?php endforeach; ?>
