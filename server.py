import json
import logging
import os
import re
import smtplib
import tempfile
import threading
import uuid
from datetime import date, datetime
from email.message import EmailMessage
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlparse


ROOT = Path(__file__).parent
PUBLIC_DIR = ROOT / "public"
DATA_FILE = Path(os.environ.get("QUEUE_DATA_FILE", ROOT / "data" / "appointments.json"))
SLOT_CAPACITY = 4
SLOTS = [
    "%02d:%02d" % (hour, minute)
    for hour in range(9, 17)
    for minute in (0, 30)
]
logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")


class QueueStore:
    def __init__(self, path):
        self.path = Path(path)
        self.lock = threading.Lock()

    def read(self):
        with self.lock:
            if not self.path.exists():
                return {"appointments": []}
            with self.path.open(encoding="utf-8") as file:
                return json.load(file)

    def update(self, callback):
        with self.lock:
            if self.path.exists():
                with self.path.open(encoding="utf-8") as file:
                    data = json.load(file)
            else:
                data = {"appointments": []}
            result = callback(data)
            self.path.parent.mkdir(parents=True, exist_ok=True)
            handle, temp_path = tempfile.mkstemp(
                dir=str(self.path.parent), prefix=self.path.name, suffix=".tmp"
            )
            try:
                with os.fdopen(handle, "w", encoding="utf-8") as file:
                    json.dump(data, file, indent=2)
                os.replace(temp_path, self.path)
            finally:
                if os.path.exists(temp_path):
                    os.unlink(temp_path)
            return result


STORE = QueueStore(DATA_FILE)


def parse_booking_date(value):
    if not isinstance(value, str) or not re.fullmatch(r"\d{4}-\d{2}-\d{2}", value):
        raise ValueError("Choose a valid date.")
    try:
        selected = date.fromisoformat(value)
    except ValueError:
        raise ValueError("Choose a valid date.")
    if selected < date.today():
        raise ValueError("Choose today or a future date.")
    return selected


def send_confirmation(appointment):
    host = os.environ.get("PLMUN_SMTP_HOST")
    if not host:
        logging.info(
            "Email not configured; development confirmation for %s",
            appointment["ticket"],
        )
        return "not_configured"

    message = EmailMessage()
    message["Subject"] = "Your service appointment " + appointment["ticket"]
    message["From"] = os.environ.get("PLMUN_SMTP_FROM", os.environ.get("PLMUN_SMTP_USER", ""))
    message["To"] = appointment["email"]
    message.set_content(
        "Hello %s,\n\nYour service appointment is confirmed.\n"
        "Date: %s\nTime: %s\nQueue number: %s\n\n"
        "Please arrive a few minutes before your appointment.\n"
        % (
            appointment["name"],
            appointment["date"],
            appointment["time"],
            appointment["ticket"],
        )
    )
    try:
        port = int(os.environ.get("PLMUN_SMTP_PORT", "587"))
        with smtplib.SMTP(host, port, timeout=15) as smtp:
            smtp.starttls()
            username = os.environ.get("PLMUN_SMTP_USER")
            password = os.environ.get("PLMUN_SMTP_PASSWORD")
            if username:
                smtp.login(username, password or "")
            smtp.send_message(message)
        return "sent"
    except (OSError, smtplib.SMTPException, ValueError):
        logging.exception("Could not send appointment confirmation to %s", appointment["email"])
        return "failed"


class QueueHandler(BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):
        logging.info("%s - %s", self.address_string(), fmt % args)

    def send_json(self, status, payload):
        body = json.dumps(payload).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(body)

    def read_json(self):
        try:
            length = int(self.headers.get("Content-Length", "0"))
        except ValueError:
            raise ValueError("Invalid request body.")
        if length < 1 or length > 16_384:
            raise ValueError("Request body must be between 1 and 16384 bytes.")
        try:
            return json.loads(self.rfile.read(length))
        except (json.JSONDecodeError, UnicodeDecodeError):
            raise ValueError("Request body must be valid JSON.")

    def do_GET(self):
        parsed = urlparse(self.path)
        if parsed.path == "/api/availability":
            query = parse_qs(parsed.query)
            try:
                selected = parse_booking_date(query.get("date", [""])[0])
            except ValueError as error:
                return self.send_json(400, {"error": str(error)})
            appointments = STORE.read()["appointments"]
            counts = {
                slot: sum(
                    1
                    for appointment in appointments
                    if appointment["date"] == selected.isoformat()
                    and appointment["time"] == slot
                    and appointment["status"] != "cancelled"
                )
                for slot in SLOTS
            }
            if selected == date.today():
                now = datetime.now().strftime("%H:%M")
                counts = {slot: count for slot, count in counts.items() if slot > now}
            return self.send_json(
                200,
                {
                    "date": selected.isoformat(),
                    "slots": [
                        {
                            "time": slot,
                            "available": max(0, SLOT_CAPACITY - counts[slot]),
                        }
                        for slot in counts
                    ],
                },
            )

        if parsed.path == "/api/queue":
            today = date.today().isoformat()
            now = datetime.now().strftime("%H:%M")
            appointments = [
                appointment
                for appointment in STORE.read()["appointments"]
                if appointment["date"] == today
                and appointment["status"] in ("waiting", "called")
                and (appointment["status"] == "called" or appointment["time"] <= now)
            ]
            appointments.sort(key=lambda item: (item["time"], item["created_at"]))
            called = [item for item in appointments if item["status"] == "called"]
            waiting = [item for item in appointments if item["status"] == "waiting"]
            return self.send_json(
                200,
                {
                    "date": today,
                    "called": (
                        {
                            "ticket": called[-1]["ticket"],
                            "time": called[-1]["time"],
                        }
                        if called
                        else None
                    ),
                    "waiting": [
                        {"ticket": item["ticket"], "time": item["time"]}
                        for item in waiting
                    ],
                },
            )

        if parsed.path == "/api/staff/appointments":
            today = date.today().isoformat()
            appointments = [
                {
                    key: item[key]
                    for key in ("id", "ticket", "name", "email", "date", "time", "status")
                }
                for item in STORE.read()["appointments"]
                if item["date"] == today
            ]
            appointments.sort(key=lambda item: (item["time"], item["ticket"]))
            return self.send_json(200, {"date": today, "appointments": appointments})

        pages = {
            "/": "index.html",
            "/staff": "staff.html",
            "/display": "display.html",
        }
        filename = pages.get(parsed.path)
        if filename:
            return self.send_file(PUBLIC_DIR / filename, "text/html; charset=utf-8")
        if parsed.path in ("/styles.css", "/app.js", "/staff.js", "/display.js"):
            content_type = "text/javascript; charset=utf-8" if parsed.path.endswith(".js") else "text/css; charset=utf-8"
            return self.send_file(PUBLIC_DIR / parsed.path.lstrip("/"), content_type)
        return self.send_json(404, {"error": "Not found."})

    def send_file(self, path, content_type):
        try:
            body = path.read_bytes()
        except OSError:
            return self.send_json(404, {"error": "Not found."})
        self.send_response(200)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_POST(self):
        parsed = urlparse(self.path)
        if parsed.path == "/api/bookings":
            return self.create_booking()
        if parsed.path == "/api/call-next":
            return self.call_next()
        match = re.fullmatch(r"/api/appointments/([0-9a-f-]+)/complete", parsed.path)
        if match:
            return self.complete_appointment(match.group(1))
        return self.send_json(404, {"error": "Not found."})

    def create_booking(self):
        try:
            payload = self.read_json()
            if not isinstance(payload, dict):
                raise ValueError("Provide a name, email, date, and time.")
            name = payload.get("name", "").strip()
            email = payload.get("email", "").strip()
            selected = parse_booking_date(payload.get("date"))
            slot = payload.get("time")
            if not name or len(name) > 100:
                raise ValueError("Name must be between 1 and 100 characters.")
            if (
                not isinstance(email, str)
                or len(email) > 254
                or not re.fullmatch(r"[^@\s]+@[^@\s]+\.[^@\s]+", email)
            ):
                raise ValueError("Enter a valid email address.")
            if slot not in SLOTS:
                raise ValueError("Choose an available time slot.")
            if selected == date.today() and slot <= datetime.now().strftime("%H:%M"):
                raise ValueError("Choose a time that has not passed.")
        except (ValueError, AttributeError) as error:
            return self.send_json(400, {"error": str(error)})

        def reserve(data):
            same_slot = [
                item
                for item in data["appointments"]
                if item["date"] == selected.isoformat()
                and item["time"] == slot
                and item["status"] != "cancelled"
            ]
            if len(same_slot) >= SLOT_CAPACITY:
                return None
            number = max(
                (int(item["ticket"][1:]) for item in data["appointments"]),
                default=0,
            ) + 1
            appointment = {
                "id": str(uuid.uuid4()),
                "ticket": "Q%04d" % number,
                "name": name,
                "email": email,
                "date": selected.isoformat(),
                "time": slot,
                "status": "waiting",
                "created_at": datetime.now().isoformat(timespec="seconds"),
            }
            data["appointments"].append(appointment)
            return appointment

        appointment = STORE.update(reserve)
        if appointment is None:
            return self.send_json(409, {"error": "That time slot is full. Please choose another."})
        email_status = send_confirmation(appointment)
        return self.send_json(
            201,
            {
                "ticket": appointment["ticket"],
                "date": appointment["date"],
                "time": appointment["time"],
                "email_status": email_status,
            },
        )

    def call_next(self):
        today = date.today().isoformat()

        def call(data):
            if any(
                item["date"] == today and item["status"] == "called"
                for item in data["appointments"]
            ):
                return "active"
            candidates = [
                item
                for item in data["appointments"]
                if item["date"] == today
                and item["status"] == "waiting"
                and item["time"] <= datetime.now().strftime("%H:%M")
            ]
            if not candidates:
                return None
            candidates.sort(key=lambda item: (item["time"], item["created_at"]))
            candidates[0]["status"] = "called"
            candidates[0]["called_at"] = datetime.now().isoformat(timespec="seconds")
            return candidates[0]

        result = STORE.update(call)
        if result == "active":
            return self.send_json(409, {"error": "Complete the current customer before calling the next."})
        if result is None:
            return self.send_json(409, {"error": "There are no customers ready to be called."})
        return self.send_json(
            200,
            {"ticket": result["ticket"], "name": result["name"], "time": result["time"]},
        )

    def complete_appointment(self, appointment_id):
        def complete(data):
            for item in data["appointments"]:
                if item["id"] == appointment_id and item["status"] == "called":
                    item["status"] = "completed"
                    item["completed_at"] = datetime.now().isoformat(timespec="seconds")
                    return item["ticket"]
            return None

        ticket = STORE.update(complete)
        if ticket is None:
            return self.send_json(404, {"error": "Called appointment not found."})
        return self.send_json(200, {"ticket": ticket, "status": "completed"})


def main():
    host = os.environ.get("HOST", "127.0.0.1")
    port = int(os.environ.get("PORT", "8000"))
    STORE.path.parent.mkdir(parents=True, exist_ok=True)
    print("Queue system running at http://%s:%d" % (host, port))
    ThreadingHTTPServer((host, port), QueueHandler).serve_forever()


if __name__ == "__main__":
    main()
