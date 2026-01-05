// Holiday types management
(function() {
  const openBtn = document.getElementById('openAddTypeBtn');
  const closeBtn = document.getElementById('closeTypeModal');
  const overlay = document.getElementById('typeModalOverlay');
  const form = document.getElementById('typeAddForm');

  if (!openBtn || !overlay) return;

  // Open modal
  openBtn.addEventListener('click', function() {
    overlay.style.display = 'flex';
    form.reset();
  });

  // Close modal
  closeBtn?.addEventListener('click', function() {
    overlay.style.display = 'none';
  });

  // Close on overlay click
  overlay.addEventListener('click', function(e) {
    if (e.target === overlay) {
      overlay.style.display = 'none';
    }
  });

  // Handle form submission
  form?.addEventListener('submit', function(e) {
    e.preventDefault();

    const formData = new FormData(form);
    const btn = form.querySelector('[type="submit"]');
    const origText = btn.innerText;

    btn.disabled = true;
    btn.innerText = 'Creando...';

    fetch(window.location.pathname + window.location.search, {
      method: 'POST',
      body: formData,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => {
      btn.disabled = false;
      btn.innerText = origText;

      if (response.ok) {
        overlay.style.display = 'none';
        location.reload();
      } else {
        alert('Error al crear el tipo');
      }
    })
    .catch(error => {
      btn.disabled = false;
      btn.innerText = origText;
      console.error('Error:', error);
      alert('Error de red');
    });
  });
})();
