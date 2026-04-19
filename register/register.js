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