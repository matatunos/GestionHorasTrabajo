/**
 * settings-user-management.js
 * User management modal functionality
 */

(function(){
  const openBtn = document.getElementById('open-add-user-btn');
  const userOverlay = document.getElementById('userModalOverlay');
  const closeBtn = document.getElementById('closeUserModal');
  const resetOverlay = document.getElementById('resetModalOverlay');
  const closeResetBtn = document.getElementById('closeResetModal');
  const deleteOverlay = document.getElementById('deleteModalOverlay');
  const closeDeleteBtn = document.getElementById('closeDeleteModal');

  if (openBtn && userOverlay) {
    openBtn.addEventListener('click', () => {
      userOverlay.style.display = 'flex';
      userOverlay.setAttribute('aria-hidden','false');
      try{ document.getElementById('add-user-form').querySelector('input[name="username"]').focus(); }catch(e){}
    });
  }

  if (closeBtn && userOverlay) {
    closeBtn.addEventListener('click', () => {
      userOverlay.style.display = 'none';
      userOverlay.setAttribute('aria-hidden','true');
    });
  }

  if (userOverlay) {
    userOverlay.addEventListener('click', (e)=>{ if (e.target===userOverlay) { userOverlay.style.display='none'; userOverlay.setAttribute('aria-hidden','true'); } });
  }

  window.openResetModal = function(userId, username) {
    document.getElementById('reset_user_id').value = userId;
    document.getElementById('reset_username').textContent = username;
    resetOverlay.style.display = 'flex';
    resetOverlay.setAttribute('aria-hidden','false');
  };

  if (closeResetBtn && resetOverlay) {
    closeResetBtn.addEventListener('click', () => {
      resetOverlay.style.display = 'none';
      resetOverlay.setAttribute('aria-hidden','true');
    });
  }

  if (resetOverlay) {
    resetOverlay.addEventListener('click', (e)=>{ if (e.target===resetOverlay) { resetOverlay.style.display='none'; resetOverlay.setAttribute('aria-hidden','true'); } });
  }

  window.openDeleteModal = function(userId, username) {
    document.getElementById('delete_user_id').value = userId;
    document.getElementById('delete_username').textContent = username;
    deleteOverlay.style.display = 'flex';
    deleteOverlay.setAttribute('aria-hidden','false');
  };

  if (closeDeleteBtn && deleteOverlay) {
    closeDeleteBtn.addEventListener('click', () => {
      deleteOverlay.style.display = 'none';
      deleteOverlay.setAttribute('aria-hidden','true');
    });
  }

  if (deleteOverlay) {
    deleteOverlay.addEventListener('click', (e)=>{ if (e.target===deleteOverlay) { deleteOverlay.style.display='none'; deleteOverlay.setAttribute('aria-hidden','true'); } });
  }
})();
