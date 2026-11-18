const API_URL = "api.php";
const timeSlots = [
  "9:00 AM", "9:30 AM", "10:00 AM", "10:30 AM", "11:00 AM", "11:30 AM",
  "1:00 PM", "1:30 PM", "2:00 PM", "2:30 PM", "3:00 PM", "3:30 PM", "4:00 PM"
];

const sections = ["dashboard", "appointment", "queue", "analytics", "settings"];
const dateInput = document.getElementById("appointmentDate");
const dateStatus = document.getElementById("appointmentDateStatus");
const slotsContainer = document.getElementById("timeSlots");
const bookingForm = document.getElementById("bookingForm");
const selectedDate = document.getElementById("selectedDate");
const selectedTime = document.getElementById("selectedTime");
const bookingMessage = document.getElementById("bookingMessage");

function showSection(sectionId) {
  if (!sections.includes(sectionId)) return;

  sections.forEach(id => {
    document.getElementById(id).hidden = id !== sectionId;
  });

  document.querySelectorAll(".sidebar li[data-section]").forEach(item => {
    item.classList.toggle("active", item.dataset.section === sectionId);
  });

  const titles = {
    dashboard: "DASHBOARD",
    appointment: "APPOINTMENT SCHEDULING",
    queue: "QUEUE MANAGEMENT",
    analytics: "REPORTS & ANALYTICS",
    settings: "SETTINGS"
  };
  document.getElementById("pageTitle").textContent = titles[sectionId];

  if (sectionId === "dashboard" || sectionId === "queue") loadDashboard();
  if (sectionId === "analytics") loadReports();
}

async function requestApi(action, options = {}) {
  const { params, ...requestOptions } = options;
  const url = new URL(API_URL, window.location.href);
  url.searchParams.set("action", action);
  if (params) {
    Object.entries(params).forEach(([key, value]) => url.searchParams.set(key, value));
  }
  const response = await fetch(url, {
    credentials: "same-origin",
    headers: { "Content-Type": "application/json" },
    ...requestOptions
  });

  const result = await response.json();
  if (response.status === 401) {
    window.location.href = "login.html";
    throw new Error("Please sign in again.");
  }
  if (!response.ok) throw new Error(result.error || "The request failed.");
  return result;
}

function displayMessage(element, message, isError = false) {
  element.textContent = message;
  element.classList.toggle("error", isError);
  element.hidden = false;
}

function updateDateStatus(message, state) {
  dateStatus.textContent = message;
  dateStatus.className = `schedule-status is-${state}`;
  dateStatus.hidden = false;
  dateInput.classList.toggle("schedule-date-available", state === "available");
  dateInput.classList.toggle("schedule-date-unavailable", state === "unavailable");
}

async function checkSession() {
  try {
    const result = await requestApi("session");
    if (!result.authenticated) window.location.replace("login.html");
    else {
      document.getElementById("adminName").textContent = result.email;
      showSection("dashboard");
    }
  } catch (error) {
    if (error.message !== "Please sign in again.") {
      window.location.replace("login.html");
    }
  }
}

async function loadSlots(date) {
  slotsContainer.replaceChildren();
  bookingForm.hidden = true;
  bookingMessage.hidden = true;
  if (!date) return;

  updateDateStatus("Checking schedule...", "checking");
  slotsContainer.setAttribute("aria-busy", "true");
  try {
    const result = await requestApi("slots", { params: { date } });
    if (dateInput.value !== date) return;
    updateDateStatus("Applicable — scheduling is open for this date.", "available");
    timeSlots.forEach(time => {
      const button = document.createElement("button");
      button.type = "button";
      button.className = "time-slot";
      button.textContent = time;
      button.disabled = !result.available.includes(time);
      if (button.disabled) {
        button.classList.add("booked");
        button.title = "This time is no longer available";
      }
      button.addEventListener("click", () => {
        document.querySelectorAll(".time-slot.selected").forEach(item => item.classList.remove("selected"));
        button.classList.add("selected");
        selectedDate.value = date;
        selectedTime.value = time;
        bookingForm.hidden = false;
        bookingForm.scrollIntoView({ behavior: "smooth", block: "nearest" });
      });
      slotsContainer.appendChild(button);
    });
    if (result.daily_limit_reached) {
      displayMessage(bookingMessage, "The 100-appointment limit for this weekday has been reached.", true);
    } else if (result.available.length === 0) {
      displayMessage(bookingMessage, "No available times for this date.", true);
    }
  } catch (error) {
    if (dateInput.value !== date) return;
    if (error.message.startsWith("Appointments are unavailable")
      || error.message.startsWith("Choose a weekday")) {
      updateDateStatus(`Not applicable — ${error.message}`, "unavailable");
    } else {
      updateDateStatus("Schedule status could not be verified.", "checking");
    }
    displayMessage(bookingMessage, error.message, true);
  } finally {
    slotsContainer.removeAttribute("aria-busy");
  }
}

async function loadDashboard() {
  try {
    const result = await requestApi("dashboard");
    document.getElementById("dashboardMessage").hidden = true;
    document.getElementById("totalToday").textContent = result.total_today;
    document.getElementById("waitingToday").textContent = result.waiting_today;
    document.getElementById("completedToday").textContent = result.completed_today;
    ["queueRows", "queueRowsCopy"].forEach(tableId => {
      const table = document.getElementById(tableId);
      if (!table) return;
      table.replaceChildren(...result.appointments.map(appointment => {
        const row = document.createElement("tr");
        const statusLabels = {
          booked: "Booked / Waiting",
          checked_in: "Checked in / Waiting",
          completed: "Completed",
          cancelled: "Cancelled",
          no_show: "No-show"
        };
        [
          `Q-${String(appointment.id).padStart(4, "0")}`,
          appointment.time,
          appointment.name,
          appointment.service_type,
          statusLabels[appointment.status] || appointment.status
        ].forEach(value => {
          const cell = document.createElement("td");
          cell.textContent = value;
          row.appendChild(cell);
        });
        const actionCell = document.createElement("td");
        let actions = [];
        if (appointment.status === "booked") {
          actions = [
            ["Check in", "checked_in", "btn-check-in"],
            ["Complete", "completed", "btn-complete"],
            ["No-show", "no_show", "btn-missed"],
            ["Cancel", "cancelled", "btn-cancel"]
          ];
        } else if (appointment.status === "checked_in") {
          actions = [
            ["Complete", "completed", "btn-complete"],
            ["Cancel", "cancelled", "btn-cancel"]
          ];
        }
        if (actions.length > 0) {
          actions.forEach(([label, status, className]) => {
            const button = document.createElement("button");
            button.type = "button";
            button.className = className;
            button.textContent = label;
            button.addEventListener("click", () => updateAppointmentStatus(appointment.id, status));
            actionCell.appendChild(button);
          });
        } else {
          actionCell.textContent = "—";
        }
        row.appendChild(actionCell);
        return row;
      }));
      if (result.appointments.length === 0) {
        const row = document.createElement("tr");
        const cell = document.createElement("td");
        cell.colSpan = 6;
        cell.textContent = "No appointments scheduled for today.";
        row.appendChild(cell);
        table.appendChild(row);
      }
    });
    const upcomingRows = document.getElementById("upcomingRows");
    upcomingRows.replaceChildren(...result.upcoming_appointments.map(appointment => {
      const row = document.createElement("tr");
      [
        appointment.date,
        `Q-${String(appointment.id).padStart(4, "0")}`,
        appointment.time,
        appointment.name,
        appointment.service_type,
        "Booked / Waiting"
      ].forEach(value => {
        const cell = document.createElement("td");
        cell.textContent = value;
        row.appendChild(cell);
      });
      return row;
    }));
    if (result.upcoming_appointments.length === 0) {
      const row = document.createElement("tr");
      const cell = document.createElement("td");
      cell.colSpan = 6;
      cell.textContent = "No upcoming appointments in the next 90 days.";
      row.appendChild(cell);
      upcomingRows.appendChild(row);
    }
  } catch (error) {
    displayMessage(document.getElementById("dashboardMessage"), error.message, true);
  }
}

async function updateAppointmentStatus(id, status) {
  const prompts = {
    checked_in: "Check in this student for today's appointment?",
    completed: "Mark this appointment as completed?",
    no_show: "Mark this appointment as a no-show?",
    cancelled: "Cancel this appointment?"
  };
  if (!window.confirm(prompts[status])) return;
  try {
    await requestApi("update_status", {
      method: "POST",
      body: JSON.stringify({ id, status })
    });
    await loadDashboard();
  } catch (error) {
    window.alert(error.message);
  }
}

async function loadReports() {
  try {
    const result = await requestApi("reports");
    document.getElementById("reportsMessage").hidden = true;
    document.getElementById("weeklyTotal").textContent = result.total;
    document.getElementById("weeklyCompleted").textContent = result.completed;
    document.getElementById("weeklyWaiting").textContent = result.waiting;
    document.getElementById("weeklyCancelled").textContent = result.cancelled;
    document.getElementById("weeklyNoShow").textContent = result.no_show;
  } catch (error) {
    displayMessage(document.getElementById("reportsMessage"), error.message, true);
  }
}

dateInput.addEventListener("change", () => {
  if (!dateInput.value) {
    slotsContainer.replaceChildren();
    bookingForm.hidden = true;
    bookingMessage.hidden = true;
    dateStatus.hidden = true;
    dateInput.classList.remove("schedule-date-available", "schedule-date-unavailable");
    return;
  }
  const day = new Date(`${dateInput.value}T00:00:00`).getDay();
  if (day === 0 || day === 6) {
    slotsContainer.replaceChildren();
    bookingForm.hidden = true;
    updateDateStatus("Not applicable — appointments are unavailable on weekends.", "unavailable");
    displayMessage(bookingMessage, "Appointments are unavailable on weekends and Philippine holidays. Please select an available weekday.", true);
    return;
  }
  loadSlots(dateInput.value);
});
dateInput.min = new Date(Date.now() - new Date().getTimezoneOffset() * 60000)
  .toISOString().slice(0, 10);

bookingForm.addEventListener("submit", async event => {
  event.preventDefault();
  const submitButton = bookingForm.querySelector('[type="submit"]');
  submitButton.disabled = true;
  bookingMessage.hidden = true;

  try {
    const result = await requestApi("book", {
      method: "POST",
      body: JSON.stringify({
        name: document.getElementById("studentName").value.trim(),
        email: document.getElementById("studentEmail").value.trim(),
        service_type: document.getElementById("serviceType").value.trim(),
        date: selectedDate.value,
        time: selectedTime.value
      })
    });
    let successMessage = `Appointment booked. Queue number: Q-${String(result.id).padStart(4, "0")}. Tracking code: ${result.tracking_code}. Keep this code safe.`;
    if (!result.email_sent) successMessage += ` ${result.email_warning}`;
    bookingForm.reset();
    bookingForm.hidden = true;
    document.querySelectorAll(".time-slot.selected").forEach(item => item.classList.remove("selected"));
    await loadSlots(dateInput.value);
    await loadDashboard();
    displayMessage(bookingMessage, successMessage);
  } catch (error) {
    await loadSlots(dateInput.value);
    displayMessage(bookingMessage, error.message, true);
  } finally {
    submitButton.disabled = false;
  }
});

async function logout() {
  try {
    await requestApi("logout", { method: "POST", body: "{}" });
  } finally {
    window.location.replace("login.html");
  }
}

function closeForm() {
  bookingForm.hidden = true;
  bookingForm.reset();
  document.querySelectorAll(".time-slot.selected").forEach(item => item.classList.remove("selected"));
}

document.getElementById("refreshDashboardButton").addEventListener("click", loadDashboard);

document.getElementById("reissueTrackingCodesButton").addEventListener("click", async event => {
  const button = event.currentTarget;
  if (!window.confirm(
    "This will replace all existing tracking codes. Old codes will stop working. "
    + "Download the staff-only CSV and distribute the new codes privately. Continue?"
  )) {
    return;
  }

  button.disabled = true;
  const message = document.getElementById("trackingCodeMigrationMessage");
  message.hidden = true;
  try {
    const result = await requestApi("reissue_tracking_codes", {
      method: "POST",
      body: JSON.stringify({ confirm: true })
    });
    displayMessage(
      message,
      `Converted ${result.converted_count} code(s). Download the staff-only CSV for manual distribution.`
    );
  } catch (error) {
    displayMessage(message, error.message, true);
  } finally {
    button.disabled = false;
  }
});

checkSession();
