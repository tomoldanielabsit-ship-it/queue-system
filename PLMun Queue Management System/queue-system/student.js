const API_URL = "api.php";
const slots = [
  "9:00 AM", "9:30 AM", "10:00 AM", "10:30 AM", "11:00 AM", "11:30 AM",
  "1:00 PM", "1:30 PM", "2:00 PM", "2:30 PM", "3:00 PM", "3:30 PM", "4:00 PM"
];

const authPanel = document.getElementById("studentAuthPanel");
const studentPortal = document.getElementById("studentPortal");
const authMessage = document.getElementById("studentAuthMessage");
const loginForm = document.getElementById("studentLoginForm");
const registerForm = document.getElementById("studentRegisterForm");
const bookingForm = document.getElementById("studentBookingForm");
const dateInput = document.getElementById("studentDate");
const dateStatus = document.getElementById("studentDateStatus");
const calendarDays = document.getElementById("studentCalendarDays");
const calendarMonthLabel = document.getElementById("studentCalendarMonth");
const previousMonthButton = document.getElementById("studentCalendarPrevious");
const nextMonthButton = document.getElementById("studentCalendarNext");
const serviceOptions = document.getElementById("studentServiceOptions");
const otherCertificateField = document.getElementById("studentOtherCertificateField");
const otherCertificateInput = document.getElementById("studentOtherCertificate");
const slotsContainer = document.getElementById("studentTimeSlots");
const bookingMessage = document.getElementById("bookingMessage");
const bookingTitle = document.getElementById("studentBookingTitle");
const bookingHelp = document.getElementById("studentBookingHelp");
const rescheduleModeMessage = document.getElementById("rescheduleModeMessage");
const cancelRescheduleButton = document.getElementById("cancelRescheduleButton");
const trackingForm = document.getElementById("trackingForm");
const trackingMessage = document.getElementById("trackingMessage");
const trackingResult = document.getElementById("trackingResult");
const transactionProgress = document.getElementById("transactionProgress");
const rescheduleButton = document.getElementById("rescheduleBookingButton");
const cancelButton = document.getElementById("cancelBookingButton");
let selectedTime = "";
let currentTracking = null;
let rescheduleMode = false;
let calendarMonth = null;
let calendarRangeStart = "";
let calendarRangeEnd = "";
let calendarAvailability = {};

function updateOtherCertificateField() {
  const needsDetails = getSelectedServices().includes("Other Certificate");
  otherCertificateField.hidden = rescheduleMode || !needsDetails;
  otherCertificateInput.required = !rescheduleMode && needsDetails;
  if (!needsDetails) otherCertificateInput.value = "";
}

function getSelectedServices() {
  return Array.from(serviceOptions.querySelectorAll('input[name="studentService"]:checked'))
    .map(option => option.value);
}

async function requestApi(action, options = {}) {
  const { params, ...requestOptions } = options;
  const url = new URL(API_URL, window.location.href);
  url.searchParams.set("action", action);
  if (params) {
    Object.entries(params).forEach(([key, value]) => url.searchParams.set(key, value));
  }
  let response;
  try {
    response = await fetch(url, {
      credentials: "same-origin",
      headers: { "Content-Type": "application/json" },
      ...requestOptions
    });
  } catch (error) {
    if (error instanceof TypeError) {
      throw new Error(
        "Cannot reach the PHP API. Open the student portal through the running PHP server at "
        + "http://127.0.0.1:8000/student.html, not Live Server or a static preview."
      );
    }
    throw error;
  }
  const text = await response.text();
  let result;
  try {
    result = JSON.parse(text);
  } catch {
    throw new Error(`Could not read the server response (HTTP ${response.status}). Make sure the PHP server is running.`);
  }
  if (!response.ok) throw new Error(result.error || "The request failed.");
  return result;
}

function showMessage(element, message, isError = false) {
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

function localDateString(date) {
  const localDate = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
  return localDate.toISOString().slice(0, 10);
}

function dateKey(year, month, day) {
  return `${year}-${String(month + 1).padStart(2, "0")}-${String(day).padStart(2, "0")}`;
}

function renderCalendar() {
  if (!calendarMonth) return;

  const year = calendarMonth.getFullYear();
  const month = calendarMonth.getMonth();
  const firstDay = new Date(year, month, 1).getDay();
  const daysInMonth = new Date(year, month + 1, 0).getDate();
  const todayKey = localDateString(new Date());
  const monthStart = new Date(year, month, 1);
  const currentMonth = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
  const maxMonth = new Date(
    new Date(calendarRangeEnd + "T00:00:00").getFullYear(),
    new Date(calendarRangeEnd + "T00:00:00").getMonth(),
    1
  );

  calendarMonthLabel.textContent = calendarMonth.toLocaleDateString("en-PH", {
    month: "long",
    year: "numeric"
  });
  previousMonthButton.disabled = monthStart <= currentMonth;
  nextMonthButton.disabled = monthStart >= maxMonth;
  calendarDays.replaceChildren();

  for (let index = 0; index < firstDay; index++) {
    const blank = document.createElement("span");
    blank.setAttribute("aria-hidden", "true");
    calendarDays.appendChild(blank);
  }

  for (let day = 1; day <= daysInMonth; day++) {
    const value = dateKey(year, month, day);
    const weekday = new Date(year, month, day).getDay();
    let status = calendarAvailability[value] || "closed";
    if (value < todayKey || value < calendarRangeStart || value > calendarRangeEnd) {
      status = "closed";
    } else if (status !== "holiday" && (weekday === 0 || weekday === 6)) {
      status = "closed";
    }

    const statusLabels = {
      available: "Available",
      unavailable: "Unavailable, no appointment slots remain",
      holiday: "Holiday, office closed",
      closed: "Closed"
    };
    const colorStatus = status === "holiday" ? "unavailable" : status;
    const button = document.createElement("button");
    button.type = "button";
    button.className = `calendar-day calendar-day--${colorStatus}`;
    button.textContent = String(day);
    button.disabled = status !== "available";
    button.setAttribute("aria-pressed", dateInput.value === value ? "true" : "false");
    button.setAttribute("aria-label", `${calendarMonthLabel.textContent} ${day}, ${statusLabels[status]}`);
    button.title = statusLabels[status];
    if (status === "available") {
      button.addEventListener("click", () => selectCalendarDate(value));
    }
    calendarDays.appendChild(button);
  }
}

function selectCalendarDate(date) {
  if (calendarAvailability[date] !== "available") return;
  dateInput.value = date;
  renderCalendar();
  loadSlots(date);
}

async function loadCalendar() {
  calendarDays.setAttribute("aria-busy", "true");
  try {
    const result = await requestApi("calendar");
    calendarAvailability = result.dates;
    calendarRangeStart = result.start;
    calendarRangeEnd = result.end;
    renderCalendar();
  } catch (error) {
    calendarAvailability = {};
    renderCalendar();
    updateDateStatus("Schedule calendar could not be loaded.", "checking");
    showMessage(bookingMessage, error.message, true);
  } finally {
    calendarDays.removeAttribute("aria-busy");
  }
}

function showAuthMode(mode) {
  document.getElementById("studentLoginView").hidden = mode !== "login";
  document.getElementById("studentRegisterView").hidden = mode !== "register";
  authMessage.hidden = true;
}

function showStudentPortal(student) {
  document.body.classList.remove("student-auth-mode");
  authPanel.hidden = true;
  studentPortal.hidden = false;
  document.getElementById("studentAccountName").textContent = student.name;
  document.getElementById("studentAccountEmail").textContent = student.email;
  loadCalendar();
}

async function loadSlots(date) {
  slotsContainer.replaceChildren();
  bookingMessage.hidden = true;
  selectedTime = "";
  document.getElementById("studentSelectedTime").value = "";
  document.getElementById("studentBookButton").disabled = true;
  if (!date) return;

  updateDateStatus("Checking schedule...", "checking");
  try {
    const result = await requestApi("slots", { params: { date } });
    if (dateInput.value !== date) return;
    if (result.daily_limit_reached || result.available.length === 0) {
      calendarAvailability[date] = "unavailable";
      updateDateStatus("Not applicable — no appointment slots remain for this date.", "unavailable");
      renderCalendar();
    } else {
      calendarAvailability[date] = "available";
      updateDateStatus("Applicable — scheduling is open for this date.", "available");
      renderCalendar();
    }
    slots.forEach(time => {
      const button = document.createElement("button");
      button.type = "button";
      button.className = "time-slot";
      button.textContent = time;
      button.disabled = !result.available.includes(time);
      if (button.disabled) {
        button.title = result.daily_limit_reached
          ? "The daily appointment limit has been reached"
          : "This time has already passed";
      }
      button.addEventListener("click", () => {
        slotsContainer.querySelectorAll(".time-slot.selected").forEach(item => item.classList.remove("selected"));
        button.classList.add("selected");
        selectedTime = time;
        document.getElementById("studentSelectedDate").value = date;
        document.getElementById("studentSelectedTime").value = time;
        document.getElementById("studentBookButton").disabled = false;
      });
      slotsContainer.appendChild(button);
    });
    if (result.daily_limit_reached) {
      showMessage(bookingMessage, "The 100-appointment limit for this weekday has been reached.", true);
    } else if (result.available.length === 0) {
      showMessage(bookingMessage, "No available times for this date.", true);
    }
  } catch (error) {
    if (dateInput.value !== date) return;
    if (error.message.startsWith("Appointments are unavailable")
      || error.message.startsWith("Choose a weekday")) {
      calendarAvailability[date] = error.message.startsWith("Appointments are unavailable")
        ? "holiday"
        : "closed";
      renderCalendar();
      updateDateStatus(`Not applicable — ${error.message}`, "unavailable");
    } else {
      updateDateStatus("Schedule status could not be verified.", "checking");
    }
    showMessage(bookingMessage, error.message, true);
  }
}

async function submitAuthForm(form, action, payload) {
  const button = form.querySelector('button[type="submit"]');
  button.disabled = true;
  authMessage.hidden = true;
  try {
    const student = await requestApi(action, {
      method: "POST",
      body: JSON.stringify(payload)
    });
    form.reset();
    showStudentPortal(student);
  } catch (error) {
    showMessage(authMessage, error.message, true);
  } finally {
    button.disabled = false;
  }
}

document.getElementById("showRegisterButton").addEventListener("click", () => showAuthMode("register"));
document.getElementById("showLoginButton").addEventListener("click", () => showAuthMode("login"));
serviceOptions.addEventListener("change", updateOtherCertificateField);
updateOtherCertificateField();

loginForm.addEventListener("submit", async event => {
  event.preventDefault();
  await submitAuthForm(loginForm, "student_login", {
    email: document.getElementById("studentLoginEmail").value.trim(),
    password: document.getElementById("studentLoginPassword").value
  });
});

registerForm.addEventListener("submit", async event => {
  event.preventDefault();
  const password = document.getElementById("studentRegisterPassword").value;
  const confirmation = document.getElementById("studentRegisterPasswordConfirmation").value;
  if (password !== confirmation) {
    showMessage(authMessage, "The passwords do not match.", true);
    return;
  }
  await submitAuthForm(registerForm, "student_register", {
    name: document.getElementById("studentRegisterName").value.trim(),
    email: document.getElementById("studentRegisterEmail").value.trim(),
    password
  });
});

document.getElementById("studentLogoutButton").addEventListener("click", async () => {
  const button = document.getElementById("studentLogoutButton");
  button.disabled = true;
  try {
    await requestApi("student_logout", { method: "POST", body: "{}" });
    window.location.reload();
  } catch (error) {
    button.disabled = false;
    showMessage(authMessage, error.message, true);
    authPanel.hidden = false;
    studentPortal.hidden = true;
  }
});

const today = new Date();
calendarMonth = new Date(today.getFullYear(), today.getMonth(), 1);
calendarRangeStart = localDateString(today);
calendarRangeEnd = localDateString(new Date(today.getTime() + 90 * 24 * 60 * 60 * 1000));
previousMonthButton.addEventListener("click", () => {
  calendarMonth = new Date(calendarMonth.getFullYear(), calendarMonth.getMonth() - 1, 1);
  renderCalendar();
});
nextMonthButton.addEventListener("click", () => {
  calendarMonth = new Date(calendarMonth.getFullYear(), calendarMonth.getMonth() + 1, 1);
  renderCalendar();
});
renderCalendar();

bookingForm.addEventListener("submit", async event => {
  event.preventDefault();
  if (rescheduleMode) {
    if (!currentTracking || !selectedTime) {
      showMessage(bookingMessage, "Choose an available date and time for the reschedule.", true);
      return;
    }
    const button = document.getElementById("studentBookButton");
    const date = document.getElementById("studentSelectedDate").value;
    button.disabled = true;
    bookingMessage.hidden = true;
    try {
      const result = await requestApi("reschedule", {
        method: "POST",
        body: JSON.stringify({
          tracking_code: currentTracking.tracking_code,
          date,
          time: selectedTime
        })
      });
      const message = `Appointment rescheduled to ${result.date} at ${result.time}.`
        + (result.email_warning ? ` ${result.email_warning}` : "");
      exitRescheduleMode();
      await loadCalendar();
      await lookupAppointment();
      selectStudentPanel("trackingPanel");
      showMessage(trackingMessage, message);
    } catch (error) {
      await loadSlots(dateInput.value);
      showMessage(bookingMessage, error.message, true);
    } finally {
      button.disabled = !selectedTime;
    }
    return;
  }

  const services = getSelectedServices();
  if (services.length === 0) {
    showMessage(bookingMessage, "Select at least one service or document.", true);
    serviceOptions.querySelector('input[type="checkbox"]').focus();
    return;
  }
  if (services.includes("Other Certificate")) {
    const details = otherCertificateInput.value.trim();
    if (!details) {
      showMessage(bookingMessage, "Please specify the other certificate.", true);
      otherCertificateInput.focus();
      return;
    }
    services[services.indexOf("Other Certificate")] = `Other Certificate: ${details}`;
  }
  if (!selectedTime) {
    showMessage(bookingMessage, "Please select an available time.", true);
    return;
  }
  const button = document.getElementById("studentBookButton");
  button.disabled = true;
  bookingMessage.hidden = true;
  try {
    const result = await requestApi("book", {
      method: "POST",
      body: JSON.stringify({
        service_type: services.join("; "),
        date: document.getElementById("studentSelectedDate").value,
        time: selectedTime
      })
    });
    let message = `Appointment booked. Queue number: Q-${String(result.id).padStart(4, "0")}. Tracking code: ${result.tracking_code}. Keep this code safe.`;
    if (!result.email_sent) message += ` ${result.email_warning}`;
    document.getElementById("trackingCode").value = result.tracking_code;
    await loadCalendar();
    await loadSlots(dateInput.value);
    showMessage(bookingMessage, message);
  } catch (error) {
    await loadSlots(dateInput.value);
    showMessage(bookingMessage, error.message, true);
  } finally {
    button.disabled = !selectedTime;
  }
});

async function lookupAppointment() {
  trackingMessage.hidden = true;
  trackingResult.hidden = true;
  rescheduleButton.hidden = true;
  currentTracking = {
    tracking_code: document.getElementById("trackingCode").value.trim().toUpperCase()
  };
  try {
    const appointment = await requestApi("track", {
      method: "POST",
      body: JSON.stringify(currentTracking)
    });
    document.getElementById("resultQueueNumber").textContent = appointment.queue_number;
    document.getElementById("resultCode").textContent = appointment.tracking_code;
    document.getElementById("resultService").textContent = appointment.service_type;
    document.getElementById("resultDateTime").textContent = `${appointment.date} at ${appointment.time}`;
    const statuses = {
      booked: "Pending",
      checked_in: "Processing",
      completed: "Completed",
      cancelled: "Cancelled",
      no_show: "No-show"
    };
    document.getElementById("resultStatus").textContent = statuses[appointment.status] || appointment.status;
    const progressStages = { booked: 0, checked_in: 1, completed: 2 };
    const currentStage = progressStages[appointment.status];
    transactionProgress.hidden = !Number.isInteger(currentStage);
    transactionProgress.querySelectorAll("li[data-stage]").forEach((step, index) => {
      step.classList.toggle("is-complete", Number.isInteger(currentStage) && index < currentStage);
      step.classList.toggle("is-current", index === currentStage);
      if (index === currentStage) {
        step.setAttribute("aria-current", "step");
      } else {
        step.removeAttribute("aria-current");
      }
    });
    cancelButton.hidden = appointment.status !== "booked";
    rescheduleButton.hidden = !appointment.can_reschedule;
    trackingResult.hidden = false;
  } catch (error) {
    showMessage(trackingMessage, error.message, true);
  }
}

function selectStudentPanel(panelName) {
  document.querySelectorAll(".student-tab").forEach(tab => {
    tab.classList.toggle("active", tab.dataset.panel === panelName);
  });
  document.getElementById("bookingPanel").hidden = panelName !== "bookingPanel";
  document.getElementById("trackingPanel").hidden = panelName !== "trackingPanel";
}

function enterRescheduleMode() {
  if (!currentTracking) return;
  rescheduleMode = true;
  bookingTitle.textContent = "Reschedule your appointment";
  bookingHelp.textContent = "Choose a new green date and an available time. Your existing service and tracking code will stay the same.";
  rescheduleModeMessage.textContent = `Rescheduling appointment ${currentTracking.tracking_code}.`;
  rescheduleModeMessage.hidden = false;
  serviceOptions.hidden = true;
  updateOtherCertificateField();
  document.getElementById("studentBookButton").textContent = "Confirm reschedule";
  cancelRescheduleButton.hidden = false;
  dateInput.value = "";
  document.getElementById("studentSelectedDate").value = "";
  document.getElementById("studentSelectedTime").value = "";
  dateStatus.hidden = true;
  selectedTime = "";
  slotsContainer.replaceChildren();
  bookingMessage.hidden = true;
  document.getElementById("studentBookButton").disabled = true;
  renderCalendar();
  selectStudentPanel("bookingPanel");
}

function exitRescheduleMode() {
  rescheduleMode = false;
  bookingTitle.textContent = "Book a Registrar appointment";
  bookingHelp.textContent = "Select a green date with open appointment slots. Red dates have reached the daily limit or are holidays; gray dates are past, weekends, or outside the booking window.";
  rescheduleModeMessage.hidden = true;
  serviceOptions.hidden = false;
  updateOtherCertificateField();
  document.getElementById("studentBookButton").textContent = "Confirm appointment";
  cancelRescheduleButton.hidden = true;
  dateInput.value = "";
  document.getElementById("studentSelectedDate").value = "";
  document.getElementById("studentSelectedTime").value = "";
  dateStatus.hidden = true;
  selectedTime = "";
  slotsContainer.replaceChildren();
  document.getElementById("studentBookButton").disabled = true;
  renderCalendar();
}

document.querySelectorAll(".student-tab").forEach(tab => {
  tab.addEventListener("click", () => {
    document.querySelectorAll(".student-tab").forEach(item => item.classList.remove("active"));
    selectStudentPanel(tab.dataset.panel);
  });
});

trackingForm.addEventListener("submit", async event => {
  event.preventDefault();
  await lookupAppointment();
});

rescheduleButton.addEventListener("click", enterRescheduleMode);
cancelRescheduleButton.addEventListener("click", () => {
  exitRescheduleMode();
  selectStudentPanel("trackingPanel");
});

cancelButton.addEventListener("click", async () => {
  if (!currentTracking || !window.confirm("Are you sure you want to cancel this appointment?")) return;
  cancelButton.disabled = true;
  try {
    await requestApi("cancel_booking", {
      method: "POST",
      body: JSON.stringify(currentTracking)
    });
    showMessage(trackingMessage, "The appointment has been cancelled.");
    await loadCalendar();
    await lookupAppointment();
  } catch (error) {
    showMessage(trackingMessage, error.message, true);
  } finally {
    cancelButton.disabled = false;
  }
});

requestApi("student_session")
  .then(student => {
    if (student.authenticated) showStudentPortal(student);
  })
  .catch(error => showMessage(authMessage, error.message, true));
