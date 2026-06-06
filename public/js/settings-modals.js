/**
 * settings-modals.js
 * Gestión de modales para añadir festivos y configuración de años
 */

// Holiday modal
(function(){
  const openBtn = document.getElementById('openAddHolidayBtn');
  const overlay = document.getElementById('holidayModalOverlay');
  const closeBtn = document.getElementById('closeHolidayModal');
  const addForm = document.getElementById('holidayAddForm');
  
  if (!openBtn || !overlay) return;
  
  // Open modal
  openBtn.addEventListener('click', () => {
    overlay.style.display = 'flex';
    overlay.setAttribute('aria-hidden', 'false');
    addForm.reset();
    document.getElementById('hd_month')?.focus();
  });
  
  // Close modal
  const closeModal = () => {
    overlay.style.display = 'none';
    overlay.setAttribute('aria-hidden', 'true');
  };
  
  closeBtn && closeBtn.addEventListener('click', closeModal);
  overlay.addEventListener('click', (e) => {
    if (e.target === overlay) closeModal();
  });
})();

// Year modal
(function(){
  const openBtn = document.getElementById('openAddYearBtn');
  const overlay = document.getElementById('yearModalOverlay');
  const closeBtn = document.getElementById('closeYearModal');
  const addForm = document.getElementById('yearAddForm');
  
  if (!openBtn || !overlay) return;
  
  // Open modal
  openBtn.addEventListener('click', () => {
    overlay.style.display = 'flex';
    overlay.setAttribute('aria-hidden', 'false');
    addForm.reset();
    addForm.querySelector('[name="yearcfg_year"]')?.focus();
  });
  
  // Close modal
  const closeModal = () => {
    overlay.style.display = 'none';
    overlay.setAttribute('aria-hidden', 'true');
  };
  
  closeBtn && closeBtn.addEventListener('click', closeModal);
  overlay.addEventListener('click', (e) => {
    if (e.target === overlay) closeModal();
  });
})();
