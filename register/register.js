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

document.getElementById('regForm').addEventListener('submit', function(e) {
  e.preventDefault();

  const password = document.getElementById('password').value;
  const confirmPassword = document.getElementById('confirmPassword').value;

  // Check minimum length
  if (password.length < 16) {
    alert('Password must be at least 16 characters long.');
    return;
  }

  // Check for at least 2 special characters
  const specialChars = password.match(/[^a-zA-Z0-9]/g);
  if (!specialChars || specialChars.length < 2) {
    alert('Password must contain at least 2 special characters (e.g. @, #, !).');
    return;
  }

  // Check for at least 2 numbers
  const numbers = password.match(/[0-9]/g);
  if (!numbers || numbers.length < 2) {
    alert('Password must contain at least 2 numbers.');
    return;
  }

  // Check if passwords match
  if (password !== confirmPassword) {
    alert('Passwords do not match. Please try again.');
    document.getElementById('confirmPassword').value = '';
    document.getElementById('confirmPassword').focus();
    return;
  }

  // All checks passed — submit the form
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
    errDiv.textContent = 'Password must be at least 16 characters and contain 2+ numbers and 2+ special characters.';
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
document.addEventListener('DOMContentLoaded', () => {
  const slider = document.getElementById('authSlider');
  if (!slider) return;

  // Check if we arrived from another auth page
  const slideFrom = sessionStorage.getItem('slideFrom');
  if (slideFrom === 'login') {
    slider.style.transition = 'none';
    slider.style.transform = 'translateX(0)';
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        slider.style.transition = 'transform 0.4s ease-in-out';
        slider.style.transform = 'translateX(-200%)';
      });
    });
  } else if (slideFrom === 'admin') {
    slider.style.transition = 'none';
    slider.style.transform = 'translateX(-100%)';
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        slider.style.transition = 'transform 0.4s ease-in-out';
        slider.style.transform = 'translateX(-200%)';
      });
    });
  }
  sessionStorage.removeItem('slideFrom');

  // Intercept navigation to other auth pages
  document.querySelectorAll('a').forEach(link => {
    link.addEventListener('click', e => {
      const href = link.getAttribute('href');
      if (!href || href.includes('landing.html')) return;

      let targetPos = href.includes('admin_login.php') ? 1 : (href.includes('login.php') ? 0 : -1);
      if (targetPos !== -1) {
        e.preventDefault();
        sessionStorage.setItem('slideFrom', 'register');
        slider.style.transition = 'transform 0.4s ease-in-out';
        slider.style.transform = `translateX(-${targetPos * 100}%)`;
        setTimeout(() => window.location.href = link.href, 400); // Wait for animation to finish
      }
    });
  });
});
