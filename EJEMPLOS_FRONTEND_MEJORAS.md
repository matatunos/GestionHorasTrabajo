# Ejemplos de Integración Frontend - 5 Mejoras

## 1. HTML Dashboard Simple

```html
<div id="improvements-dashboard" class="container">
  <!-- MEJORA 1: Alertas -->
  <section id="alerts-section" class="alerts-box">
    <h3>⚠️ Alertas Activas</h3>
    <div id="alerts-container"></div>
  </section>

  <!-- MEJORA 2: Proyección Semanal -->
  <section id="projection-section" class="card">
    <h3>📊 Proyección Semanal</h3>
    <div class="projection-info">
      <p>Promedio: <strong id="avg-hours">7.0</strong> h/día</p>
      <p>Restantes: <strong id="remaining-hours">4.3</strong> h</p>
      <p>Completarás en: <strong id="completion-days">0.6</strong> días</p>
      <p>Status: <strong id="on-pace">No en ritmo</strong></p>
    </div>
  </section>

  <!-- MEJORA 3: Consistencia -->
  <section id="consistency-section" class="card">
    <h3>📈 Análisis de Consistencia</h3>
    <div class="consistency-info">
      <p>Muestra: <strong id="sample-size">48</strong> días</p>
      <p>Promedio: <strong id="mean-hours">8.0</strong> h/día</p>
      <p>Desviación: <strong id="std-dev">1.2</strong> h</p>
      <p>Consistencia: <strong id="consistency-score">85%</strong></p>
      <div class="progress-bar" style="width: 85%">85%</div>
    </div>
  </section>

  <!-- MEJORA 4: Recomendaciones Adaptativas -->
  <section id="recommendations-section" class="card">
    <h3>💡 Recomendaciones Personalizadas</h3>
    <div class="recommendations-info">
      <p>Progreso: <strong id="progress-pct">89.1%</strong></p>
      <p>Status: <strong id="rec-status" class="status-ahead">ADELANTADO</strong></p>
      <p id="rec-message" class="message-text"></p>
      <div id="adjustment-box" class="adjustment"></div>
    </div>
  </section>

  <!-- MEJORA 5: Tendencias -->
  <section id="trends-section" class="card">
    <h3>📊 Tendencias Históricas (4 semanas)</h3>
    <table id="trends-table" class="trends-table">
      <thead>
        <tr>
          <th>Semana</th>
          <th>Horas</th>
          <th>Gráfico</th>
        </tr>
      </thead>
      <tbody id="trends-tbody"></tbody>
    </table>
    <p>Tendencia: <strong id="trend-direction">📈 Mejora</strong></p>
    <p>Días productivos:</p>
    <ul id="productive-days"></ul>
  </section>
</div>
```

---

## 2. JavaScript para Renderizar Datos

```javascript
// Función principal para procesar respuesta de API
function updateImprovementsDashboard(response) {
  // MEJORA 1: Mostrar alertas
  updateAlerts(response.alerts);
  
  // MEJORA 2: Mostrar proyección
  updateProjection(response.week_projection);
  
  // MEJORA 3: Mostrar consistencia
  updateConsistency(response.consistency);
  
  // MEJORA 4: Mostrar recomendaciones
  updateRecommendations(response.adaptive_recommendations);
  
  // MEJORA 5: Mostrar tendencias
  updateTrends(response.trends);
}

// MEJORA 1: Alertas
function updateAlerts(alerts) {
  const container = document.getElementById('alerts-container');
  
  if (!alerts || alerts.length === 0) {
    container.innerHTML = '<p class="no-alerts">No hay alertas</p>';
    return;
  }
  
  container.innerHTML = alerts.map(alert => `
    <div class="alert alert-${alert.severity}">
      <strong>${alert.title}</strong>
      <p>${alert.message}</p>
    </div>
  `).join('');
}

// MEJORA 2: Proyección
function updateProjection(projection) {
  document.getElementById('avg-hours').textContent = projection.avg_hours_per_day;
  document.getElementById('remaining-hours').textContent = projection.remaining_hours_needed;
  document.getElementById('completion-days').textContent = projection.projected_days_until_completion || 'Completado';
  document.getElementById('on-pace').textContent = projection.on_pace ? '✅ En ritmo' : '⚠️ Retrasado';
  document.getElementById('on-pace').className = projection.on_pace ? 'on-pace' : 'behind-pace';
}

// MEJORA 3: Consistencia
function updateConsistency(consistency) {
  if (!consistency.has_data) {
    document.getElementById('consistency-section').style.display = 'none';
    return;
  }
  
  document.getElementById('sample-size').textContent = consistency.sample_size;
  document.getElementById('mean-hours').textContent = consistency.mean_hours;
  document.getElementById('std-dev').textContent = consistency.std_dev;
  document.getElementById('consistency-score').textContent = consistency.consistency_score + '%';
  
  // Actualizar barra de progreso
  const bar = document.querySelector('.progress-bar');
  bar.style.width = consistency.consistency_score + '%';
  bar.textContent = consistency.consistency_score + '%';
}

// MEJORA 4: Recomendaciones Adaptativas
function updateRecommendations(recommendations) {
  document.getElementById('progress-pct').textContent = recommendations.progress_percentage + '%';
  document.getElementById('rec-status').textContent = getStatusLabel(recommendations.status);
  document.getElementById('rec-status').className = `status-${recommendations.status}`;
  document.getElementById('rec-message').textContent = recommendations.message;
  
  // Mostrar ajustes según estado
  const adjustmentBox = document.getElementById('adjustment-box');
  if (recommendations.adjustment) {
    adjustmentBox.innerHTML = `
      <h4>Ajustes Recomendados:</h4>
      ${Object.entries(recommendations.adjustment).map(([key, value]) => `
        <p><strong>${formatKey(key)}:</strong> ${value}</p>
      `).join('')}
    `;
  }
}

// MEJORA 5: Tendencias
function updateTrends(trends) {
  const tbody = document.getElementById('trends-tbody');
  
  tbody.innerHTML = trends.weeks.map(week => `
    <tr>
      <td>${week.week}</td>
      <td>${week.hours}h</td>
      <td><div class="week-bar" style="width: ${(week.hours / 50) * 100}%"></div></td>
    </tr>
  `).join('');
  
  // Tendencia
  const trendEmoji = trends.trend === 'mejora' ? '📈' : (trends.trend === 'declive' ? '📉' : '➡️');
  document.getElementById('trend-direction').textContent = `${trendEmoji} ${formatTrend(trends.trend)}`;
  
  // Días productivos
  const productiveDays = document.getElementById('productive-days');
  productiveDays.innerHTML = trends.most_productive_days.map(day => `
    <li>${day.day_name}: ${day.avg_hours}h</li>
  `).join('');
}

// Funciones auxiliares
function getStatusLabel(status) {
  const labels = {
    'ahead': '✅ ADELANTADO',
    'on_pace': '➡️ EN RITMO',
    'behind': '⚠️ RETRASADO'
  };
  return labels[status] || status;
}

function formatTrend(trend) {
  const labels = {
    'mejora': 'Mejorando',
    'declive': 'Declinando',
    'estable': 'Estable'
  };
  return labels[trend] || trend;
}

function formatKey(key) {
  return key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
}

// Llamada a la API
async function loadImprovements() {
  const params = new URLSearchParams({
    user_id: getCurrentUserId(),
    date: new Date().toISOString().split('T')[0]
  });
  
  try {
    const response = await fetch(`/schedule_suggestions.php?${params}`);
    const data = await response.json();
    
    if (data.success) {
      updateImprovementsDashboard(data);
    } else {
      console.error('Error en respuesta API:', data);
    }
  } catch (error) {
    console.error('Error cargando mejoras:', error);
  }
}

// Inicializar al cargar página
document.addEventListener('DOMContentLoaded', loadImprovements);
```

---

## 3. CSS Estilos

```css
#improvements-dashboard {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
  margin: 20px;
}

.card {
  background: white;
  border-radius: 8px;
  padding: 20px;
  box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.alerts-box {
  grid-column: 1 / -1;
  background: #fff3cd;
  border-left: 4px solid #ff9800;
  padding: 15px;
  border-radius: 4px;
}

.alert {
  margin: 10px 0;
  padding: 10px;
  border-radius: 4px;
  background: white;
}

.alert-high {
  border-left: 4px solid #d32f2f;
  background: #ffebee;
}

.alert-medium {
  border-left: 4px solid #ff9800;
  background: #fff3e0;
}

.alert-info {
  border-left: 4px solid #1976d2;
  background: #e3f2fd;
}

.no-alerts {
  color: #666;
  font-style: italic;
}

.progress-bar {
  background: linear-gradient(90deg, #4caf50, #81c784);
  height: 20px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: white;
  font-weight: bold;
  margin: 10px 0;
}

.status-ahead {
  color: #2e7d32;
  background: #e8f5e9;
  padding: 4px 8px;
  border-radius: 4px;
  display: inline-block;
}

.status-on_pace {
  color: #0277bd;
  background: #e1f5fe;
  padding: 4px 8px;
  border-radius: 4px;
  display: inline-block;
}

.status-behind {
  color: #d32f2f;
  background: #ffebee;
  padding: 4px 8px;
  border-radius: 4px;
  display: inline-block;
}

.adjustment {
  background: #f5f5f5;
  padding: 15px;
  border-radius: 4px;
  margin-top: 10px;
}

.adjustment h4 {
  margin-top: 0;
}

.week-bar {
  background: linear-gradient(90deg, #4caf50, #81c784);
  height: 30px;
  border-radius: 4px;
  min-width: 5%;
}

.trends-table {
  width: 100%;
  border-collapse: collapse;
  margin: 15px 0;
}

.trends-table th,
.trends-table td {
  padding: 12px;
  text-align: left;
  border-bottom: 1px solid #ddd;
}

.trends-table th {
  background: #f5f5f5;
  font-weight: bold;
}

#productive-days {
  list-style: none;
  padding: 0;
}

#productive-days li {
  padding: 8px;
  background: #f5f5f5;
  margin: 5px 0;
  border-radius: 4px;
}

@media (max-width: 768px) {
  #improvements-dashboard {
    grid-template-columns: 1fr;
  }
}
```

---

## 4. Integración API Real

```javascript
// Ejemplo de llamada real a schedule_suggestions.php
const API_URL = '/schedule_suggestions.php';

async function fetchScheduleSuggestions(userId, date) {
  const params = new URLSearchParams({
    user_id: userId,
    date: date || new Date().toISOString().split('T')[0]
  });
  
  const response = await fetch(`${API_URL}?${params}`);
  return response.json();
}

// Uso
fetchScheduleSuggestions(1).then(data => {
  console.log('Alertas:', data.alerts);
  console.log('Proyección:', data.week_projection);
  console.log('Consistencia:', data.consistency);
  console.log('Recomendaciones:', data.adaptive_recommendations);
  console.log('Tendencias:', data.trends);
  
  updateImprovementsDashboard(data);
});
```

---

## 5. Notificaciones Push (Opcional)

```javascript
// Mostrar notificaciones solo si hay alertas importantes
function showNotifications(alerts) {
  const importantAlerts = alerts.filter(a => a.severity === 'high');
  
  importantAlerts.forEach(alert => {
    if ('Notification' in window && Notification.permission === 'granted') {
      new Notification('GestionHorasTrabajo', {
        title: alert.title,
        body: alert.message,
        icon: '/logo.png'
      });
    }
  });
}

// Pedir permiso para notificaciones
if ('Notification' in window && Notification.permission === 'default') {
  Notification.requestPermission();
}
```

---

## 6. Exportar a PDF (Opcional)

```javascript
// Usar jsPDF para exportar
function exportTrendsToPDF(trends) {
  const doc = new jsPDF();
  
  doc.setFontSize(16);
  doc.text('Análisis de Tendencias (4 semanas)', 10, 10);
  
  doc.setFontSize(12);
  doc.text(`Promedio semanal: ${trends.average_weekly_hours}h`, 10, 20);
  doc.text(`Tendencia: ${trends.trend}`, 10, 30);
  
  // Tabla con semanas
  const tableData = trends.weeks.map(w => [w.week, w.hours + 'h']);
  doc.autoTable({
    head: [['Semana', 'Horas']],
    body: tableData,
    startY: 40
  });
  
  doc.save('tendencias.pdf');
}
```

---

## 7. Dashboard Completo

Ver archivo: `dashboard.php` para integración completa en el sistema existente.
