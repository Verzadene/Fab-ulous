document.addEventListener('DOMContentLoaded', async () => {
  const notice = document.getElementById('prefillNotice');

  try {
    const response = await fetch('prefill.php', { credentials: 'same-origin' });
    const data = await response.json();
    const prefill = data?.prefill ?? {};

    if (prefill.googleLinked) {
      if (prefill.firstName) document.getElementById('firstName').value = prefill.firstName;
      if (prefill.lastName) document.getElementById('lastName').value = prefill.lastName;
      if (prefill.email) document.getElementById('email').value = prefill.email;

      notice.textContent = 'Google details were carried over for you. Finish creating your username and password to complete sign up.';
      notice.style.display = 'block';
    }
  } catch (error) {
    console.error('Could not load registration prefill.', error);
  }
});

// --- Password strength checklist ---
function setCheck(id, met) {
  const row = document.getElementById(id);
  if (!row) return;
  row.classList.toggle('met', met);
  const icon = row.querySelector('.check-icon');
  if (icon) icon.textContent = met ? '✓' : '○';
}

function updatePasswordChecks(password) {
  setCheck('check-length',  password.length >= 8);
  setCheck('check-special', /[^a-zA-Z0-9]/.test(password));
  setCheck('check-number',  /[0-9]/.test(password));
}

document.addEventListener('DOMContentLoaded', () => {
  const passInput = document.getElementById('password');
  if (passInput) {
    passInput.addEventListener('input', () => updatePasswordChecks(passInput.value));
  }
});

document.getElementById('regForm').addEventListener('submit', function(e) {
  e.preventDefault();

  const password = document.getElementById('password').value;
  const confirmPassword = document.getElementById('confirmPassword').value;

  if (password.length < 8) {
    alert('Password must be at least 8 characters long.');
    return;
  }

  const specialChars = password.match(/[^a-zA-Z0-9]/g);
  if (!specialChars || specialChars.length < 1) {
    alert('Password must contain at least 1 special character (e.g. @, #, !).');
    return;
  }

  const numbers = password.match(/[0-9]/g);
  if (!numbers || numbers.length < 1) {
    alert('Password must contain at least 1 number.');
    return;
  }

  if (password !== confirmPassword) {
    alert('Passwords do not match. Please try again.');
    document.getElementById('confirmPassword').value = '';
    document.getElementById('confirmPassword').focus();
    return;
  }

  this.submit();
});

// --- Show error from register.php redirect ---
document.addEventListener('DOMContentLoaded', () => {
  const params = new URLSearchParams(window.location.search);
  const error  = params.get('error');
  const errDiv = document.getElementById('errorMsg');

  if (error === 'email_taken') {
    errDiv.textContent = 'This email is already registered. Please log in instead.';
    errDiv.style.display = 'block';
  } else if (error === 'username_taken') {
    errDiv.textContent = 'This username is already taken. Please choose another.';
    errDiv.style.display = 'block';
  } else if (error === 'weak_password') {
    errDiv.textContent = 'Password must be at least 8 characters and contain 1+ number and 1+ special character.';
    errDiv.style.display = 'block';
  } else if (error === 'password_mismatch') {
    errDiv.textContent = 'Passwords do not match. Please try again.';
    errDiv.style.display = 'block';
  } else if (error === 'smtp_not_configured') {
    errDiv.textContent = 'Email verification is not configured. Contact the administrator.';
    errDiv.style.display = 'block';
  } else if (error === 'email_failed') {
    errDiv.textContent = 'Could not send verification email. Please try again later.';
    errDiv.style.display = 'block';
  }
});

// --- Page Transition Animation Logic ---
// Handled by ../login/auth_slider.js (loaded in register.html).
// AuthSlider.init({ page: 'register' }) is called there.
