// Holiday calendar multi-select functionality
class HolidayCalendar {
  constructor() {
    this.selectedDates = new Set(); // Store dates as 'YYYY-MM-DD'
    this.lastClickedDate = null; // For shift+click range selection
    this.init();
  }

  init() {
    const monthSelect = document.getElementById('hd_calendar_month');
    const yearSelect = document.getElementById('hd_calendar_year');

    if (!monthSelect || !yearSelect) return;

    // Set current month/year as default
    const now = new Date();
    monthSelect.value = now.getMonth() + 1;
    yearSelect.value = now.getFullYear();

    // Calendar change handlers
    monthSelect.addEventListener('change', () => this.renderCalendar());
    yearSelect.addEventListener('change', () => this.renderCalendar());

    // Form submit handler
    const form = document.getElementById('holidayAddForm');
    if (form) {
      form.addEventListener('submit', (e) => this.handleFormSubmit(e));
    }

    // Close modal button
    const closeBtn = document.getElementById('closeHolidayModal');
    if (closeBtn) {
      closeBtn.addEventListener('click', () => this.closeModal());
    }

    // Initial render
    this.renderCalendar();
  }

  renderCalendar() {
    const month = parseInt(document.getElementById('hd_calendar_month').value);
    const year = parseInt(document.getElementById('hd_calendar_year').value);
    const container = document.getElementById('holidayCalendar');

    if (!container) return;

    // Create calendar grid
    const firstDay = new Date(year, month - 1, 1);
    const lastDay = new Date(year, month, 0);
    const daysInMonth = lastDay.getDate();
    // Convert getDay() (0=Sun) to week starting Monday (0=Mon, 6=Sun)
    let startingDayOfWeek = firstDay.getDay();
    startingDayOfWeek = (startingDayOfWeek === 0) ? 6 : startingDayOfWeek - 1;

    let html = '<div class="calendar-grid">';

    // Day headers (Mon-Sun)
    const dayNames = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
    dayNames.forEach(day => {
      html += `<div class="calendar-day-header">${day}</div>`;
    });

    // Empty cells before month starts
    for (let i = 0; i < startingDayOfWeek; i++) {
      html += '<div class="calendar-empty"></div>';
    }

    // Days of month
    for (let day = 1; day <= daysInMonth; day++) {
      const dateStr = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
      const isSelected = this.selectedDates.has(dateStr);
      const isToday = this.isToday(year, month - 1, day);
      
      html += `<div class="calendar-day ${isSelected ? 'selected' : ''} ${isToday ? 'today' : ''}" data-date="${dateStr}">
        ${day}
      </div>`;
    }

    html += '</div>';
    container.innerHTML = html;

    // Attach click handlers to day cells
    container.querySelectorAll('.calendar-day').forEach(dayCell => {
      dayCell.addEventListener('click', (e) => this.handleDayClick(e, dayCell));
    });
  }

  handleDayClick(e, dayCell) {
    const dateStr = dayCell.dataset.date;

    if (e.ctrlKey || e.metaKey) {
      // Ctrl+click: toggle individual selection
      this.toggleDate(dateStr);
      this.lastClickedDate = dateStr;
    } else if (e.shiftKey && this.lastClickedDate) {
      // Shift+click: select range from last clicked to current
      this.selectRange(this.lastClickedDate, dateStr);
    } else {
      // Regular click: select only this date
      this.selectedDates.clear();
      this.selectedDates.add(dateStr);
      this.lastClickedDate = dateStr;
    }

    this.updateDisplay();
  }

  toggleDate(dateStr) {
    if (this.selectedDates.has(dateStr)) {
      this.selectedDates.delete(dateStr);
    } else {
      this.selectedDates.add(dateStr);
    }
  }

  selectRange(startDateStr, endDateStr) {
    const start = new Date(startDateStr);
    const end = new Date(endDateStr);

    // Ensure start is before end
    if (start > end) {
      [start, end] = [end, start];
    }

    // Add all dates in range (not replacing, adding to selection)
    const current = new Date(start);
    while (current <= end) {
      const dateStr = current.toISOString().split('T')[0];
      this.selectedDates.add(dateStr);
      current.setDate(current.getDate() + 1);
    }
  }

  updateDisplay() {
    // Update calendar visual
    this.renderCalendar();

    // Update selected dates display
    const display = document.getElementById('selectedDatesDisplay');
    if (!display) return;

    if (this.selectedDates.size === 0) {
      display.innerHTML = '<span style="color: #999;">Ninguno seleccionado</span>';
      document.getElementById('hd_dates_json').value = '[]';
      return;
    }

    const sortedDates = Array.from(this.selectedDates).sort();
    const formatted = sortedDates.map(d => {
      const date = new Date(d);
      return `${date.getDate()}/${date.getMonth() + 1}`;
    }).join(', ');

    display.innerHTML = `<strong>${this.selectedDates.size} día(s):</strong> ${formatted}`;
    document.getElementById('hd_dates_json').value = JSON.stringify(sortedDates);
  }

  isToday(year, month, day) {
    const today = new Date();
    return today.getFullYear() === year &&
           today.getMonth() === month &&
           today.getDate() === day;
  }

  handleFormSubmit(e) {
    e.preventDefault();

    if (this.selectedDates.size === 0) {
      alert('Por favor, selecciona al menos un día');
      return;
    }

    const form = e.target;
    const label = form.querySelector('input[name="label"]').value;
    const type = form.querySelector('select[name="type"]').value;
    const annual = form.querySelector('input[name="annual"]').checked;
    const global_flag = form.querySelector('input[name="global"]').checked;

    // Submit each selected date
    const sortedDates = Array.from(this.selectedDates).sort();
    const submitNext = (index) => {
      if (index >= sortedDates.length) {
        // All submitted
        this.selectedDates.clear();
        this.lastClickedDate = null;
        this.updateDisplay();
        this.closeModal();
        location.reload(); // Reload to show new holidays
        return;
      }

      const dateStr = sortedDates[index];
      const formData = new FormData();
      formData.append('holiday_action', 'add');
      formData.append('date', dateStr);
      formData.append('label', label);
      formData.append('type', type);
      formData.append('annual', annual ? '1' : '0');
      formData.append('global', global_flag ? '1' : '0');

      fetch(window.location.href, {
        method: 'POST',
        body: formData
      })
      .then(response => response.text())
      .then(data => {
        // Continue with next date
        submitNext(index + 1);
      })
      .catch(error => {
        console.error('Error submitting holiday:', error);
        alert('Error al añadir festivo: ' + error);
      });
    };

    submitNext(0);
  }

  closeModal() {
    const overlay = document.getElementById('holidayModalOverlay');
    if (overlay) {
      overlay.style.display = 'none';
    }
  }
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => {
    window.holidayCalendar = new HolidayCalendar();
  });
} else {
  window.holidayCalendar = new HolidayCalendar();
}
