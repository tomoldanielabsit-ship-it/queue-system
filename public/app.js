const form = document.querySelector("#booking-form");
const dateInput = document.querySelector("#date");
const timeSelect = document.querySelector("#time");
const availabilityNote = document.querySelector("#availability-note");
const formMessage = document.querySelector("#form-message");
const submitButton = document.querySelector("#submit-button");

function localDateString(date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}

dateInput.min = localDateString(new Date());
dateInput.value = dateInput.min;

async function loadAvailability() {
  timeSelect.innerHTML = "";
  availabilityNote.textContent = "Loading available times…";
  try {
    const response = await fetch(`/api/availability?date=${encodeURIComponent(dateInput.value)}`);
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || "Could not load available times.");
    const available = data.slots.filter(slot => slot.available > 0);
    if (available.length === 0) {
      timeSelect.add(new Option("No times available", ""));
      availabilityNote.textContent = "There are no available times for this date. Please choose another.";
      return;
    }
    timeSelect.add(new Option("Choose a time", ""));
    available.forEach(slot => timeSelect.add(new Option(
      `${slot.time} · ${slot.available} spot${slot.available === 1 ? "" : "s"} left`,
      slot.time
    )));
    availabilityNote.textContent = `${available.length} time slots available`;
  } catch (error) {
    timeSelect.add(new Option("Unavailable", ""));
    availabilityNote.textContent = error.message;
  }
}

dateInput.addEventListener("change", loadAvailability);
loadAvailability();

form.addEventListener("submit", async event => {
  event.preventDefault();
  formMessage.textContent = "";
  submitButton.disabled = true;
  submitButton.textContent = "Confirming…";
  const payload = Object.fromEntries(new FormData(form));
  try {
    const response = await fetch("/api/bookings", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });
    const result = await response.json();
    if (!response.ok) throw new Error(result.error || "Could not create your appointment.");
    form.reset();
    dateInput.min = localDateString(new Date());
    dateInput.value = dateInput.min;
    await loadAvailability();
    formMessage.className = "form-message success";
    formMessage.textContent = result.email_status === "sent"
      ? `You’re booked! Your queue number is ${result.ticket}. Confirmation sent to your email.`
      : result.email_status === "not_configured"
        ? `You’re booked! Your queue number is ${result.ticket}. Email delivery is not configured, so no email was sent.`
        : `You’re booked! Your queue number is ${result.ticket}, but we couldn't send the confirmation email.`;
  } catch (error) {
    formMessage.className = "form-message error";
    formMessage.textContent = error.message;
  } finally {
    submitButton.disabled = false;
    submitButton.innerHTML = 'Confirm appointment <span aria-hidden="true">→</span>';
  }
});
