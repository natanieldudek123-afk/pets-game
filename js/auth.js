import { authAPI } from './api.js';

const msg = document.getElementById('form-msg');

function showMsg(text, type = 'error') {
  msg.textContent = text;
  msg.className = `flash flash--${type}`;
  msg.style.display = 'block';
}

// Tab switching
document.querySelectorAll('.tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.auth-form').forEach(f => f.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById(`${btn.dataset.tab}-form`).classList.add('active');
    msg.style.display = 'none';
  });
});

// Login
document.getElementById('login-form').addEventListener('submit', async e => {
  e.preventDefault();
  const btn = e.target.querySelector('button');
  btn.disabled = true; btn.textContent = 'Please wait…';
  const res = await authAPI.login({
    identifier: document.getElementById('login-id').value.trim(),
    password:   document.getElementById('login-pw').value,
  });
  btn.disabled = false; btn.textContent = 'Enter the Realm';
  if (!res) return;
  if (res.success) { showMsg(res.message, 'success'); setTimeout(() => window.location.href = './dashboard.php', 800); }
  else showMsg(res.errors ? Object.values(res.errors).flat().join(' ') : res.message);
});

// Register
document.getElementById('register-form').addEventListener('submit', async e => {
  e.preventDefault();
  if (document.getElementById('reg-pw').value !== document.getElementById('reg-pw2').value) { showMsg('Passwords do not match.'); return; }
  const btn = e.target.querySelector('button');
  btn.disabled = true; btn.textContent = 'Please wait…';
  const res = await authAPI.register({
    username: document.getElementById('reg-name').value.trim(),
    email:    document.getElementById('reg-email').value.trim(),
    password: document.getElementById('reg-pw').value,
  });
  btn.disabled = false; btn.textContent = 'Begin Your Legend';
  if (!res) return;
  if (res.success) { showMsg(res.message, 'success'); setTimeout(() => window.location.href = './dashboard.php', 900); }
  else showMsg(res.errors ? Object.values(res.errors).flat().join(' ') : res.message);
});
