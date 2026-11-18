const dateElement = document.querySelector("#display-date");
const ticketElement = document.querySelector("#called-ticket");
const nameElement = document.querySelector("#called-name");
const timeElement = document.querySelector("#called-time");
const waitingList = document.querySelector("#waiting-list");
const waitingCount = document.querySelector("#waiting-count");

function escapeHtml(value) {
  return String(value).replace(/[&<>"']/g, char => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"
  })[char]);
}

async function refreshQueue() {
  const response = await fetch("/api/queue");
  const queue = await response.json();
  if (!response.ok) throw new Error(queue.error || "Queue update failed.");
  dateElement.textContent = new Date(`${queue.date}T12:00:00`).toLocaleDateString(undefined, {
    weekday: "long", month: "long", day: "numeric"
  }).toUpperCase();
  if (queue.called) {
    ticketElement.textContent = queue.called.ticket;
    nameElement.textContent = "Please come to the counter";
    timeElement.textContent = `Appointment · ${queue.called.time}`;
  } else {
    ticketElement.textContent = "—";
    nameElement.textContent = "Waiting for the next customer";
    timeElement.textContent = "";
  }
  waitingCount.textContent = queue.waiting.length;
  waitingList.innerHTML = queue.waiting.length
    ? queue.waiting.slice(0, 5).map(item =>
      `<div class="waiting-item"><span>${escapeHtml(item.ticket)}</span><span>${escapeHtml(item.time)}</span></div>`
    ).join("")
    : '<span class="muted">No customers waiting</span>';
}

refreshQueue().catch(error => {
  nameElement.textContent = `Unable to load queue: ${error.message}`;
});
setInterval(() => refreshQueue().catch(error => {
  nameElement.textContent = `Unable to update queue: ${error.message}`;
}), 5000);
