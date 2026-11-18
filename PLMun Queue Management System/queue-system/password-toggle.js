const eyeIcon = `
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
       stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    <path d="M2.5 12s3.5-7 9.5-7 9.5 7 9.5 7-3.5 7-9.5 7-9.5-7-9.5-7Z"></path>
    <circle cx="12" cy="12" r="3"></circle>
  </svg>`;

const eyeOffIcon = `
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
       stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    <path d="m3 3 18 18"></path>
    <path d="M10.6 10.6a2 2 0 0 0 2.8 2.8"></path>
    <path d="M9.9 5.2A10.7 10.7 0 0 1 12 5c6 0 9.5 7 9.5 7a14.8 14.8 0 0 1-3.1 3.8"></path>
    <path d="M6.2 6.2A15.4 15.4 0 0 0 2.5 12s3.5 7 9.5 7a10.7 10.7 0 0 0 3-.4"></path>
  </svg>`;

document.querySelectorAll('input[type="password"]').forEach(passwordInput => {
  const field = document.createElement("span");
  field.className = "password-field";
  passwordInput.parentNode.insertBefore(field, passwordInput);
  field.append(passwordInput);

  const toggle = document.createElement("button");
  toggle.type = "button";
  toggle.className = "password-toggle";
  toggle.setAttribute("aria-controls", passwordInput.id);
  toggle.setAttribute("aria-label", "Show password");
  toggle.setAttribute("aria-pressed", "false");
  toggle.title = "Show password";
  toggle.innerHTML = eyeIcon;
  toggle.addEventListener("click", () => {
    const showPassword = passwordInput.type === "password";
    passwordInput.type = showPassword ? "text" : "password";
    toggle.setAttribute("aria-pressed", String(showPassword));
    toggle.setAttribute("aria-label", showPassword ? "Hide password" : "Show password");
    toggle.title = showPassword ? "Hide password" : "Show password";
    toggle.innerHTML = showPassword ? eyeOffIcon : eyeIcon;
    passwordInput.focus();
  });
  field.append(toggle);
});
