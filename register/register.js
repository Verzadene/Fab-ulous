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
