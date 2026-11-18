const list = document.querySelector("#appointment-list");
const emptyState = document.querySelector("#empty-state");
const count = document.querySelector("#appointment-count");
const message = document.querySelector("#staff-message");
const dateLabel = document.querySelector("#staff-date");
const callButton = document.querySelector("#call-next");

function escapeHtml(value) {
  return String(value).replace(/[&<>"']/g, char => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"
  })[char]);
}

async function refresh() {
  const response = await fetch("/api/staff/appointments");
  const data = await response.json();
  dateLabel.textContent = new Date(`${data.date}T12:00:00`).toLocaleDateString(undefined, {
    weekday: "long", year: "numeric", month: "long", day: "numeric"
  });
  count.textContent = data.appointments.length;
  emptyState.hidden = data.appointments.length !== 0;
  list.innerHTML = data.appointments.map(item => {
    const status = item.status;
    return `<tr>
      <td class="time-cell">${escapeHtml(item.time)}</td>
      <td><span class="ticket">${escapeHtml(item.ticket)}</span></td>
      <td><span class="customer-name">${escapeHtml(item.name)}</span><span class="customer-email">${escapeHtml(item.email)}</span></td>
      <td><span class="status status-${escapeHtml(status)}">${escapeHtml(status)}</span></td>
      <td>${status === "called" ? `<button class="text-button" data-complete="${escapeHtml(item.id)}">Complete</button>` : ""}</td>
    </tr>`;
  }).join("");
  list.querySelectorAll("[data-complete]").forEach(button => {
    button.addEventListener("click", () => completeAppointment(button.dataset.complete));
  });
}

async function completeAppointment(id) {
  const response = await fetch(`/api/appointments/${encodeURIComponent(id)}/complete`, { method: "POST" });
  const result = await response.json();
  message.className = response.ok ? "form-message success" : "form-message error";
  message.textContent = response.ok ? `${result.ticket} marked complete.` : result.error;
  await refresh();
}

callButton.addEventListener("click", async () => {
  callButton.disabled = true;
  const response = await fetch("/api/call-next", { method: "POST" });
  const result = await response.json();
  message.className = response.ok ? "form-message success" : "form-message error";
  message.textContent = response.ok
    ? `Now calling ${result.ticket} · ${result.name} (${result.time}).`
    : result.error;
  callButton.disabled = false;
  await refresh();
});

refresh().catch(error => {
  message.className = "form-message error";
  message.textContent = `Could not load appointments: ${error.message}`;
});
setInterval(() => refresh().catch(error => {
  message.className = "form-message error";
  message.textContent = `Could not refresh appointments: ${error.message}`;
}), 10000);
