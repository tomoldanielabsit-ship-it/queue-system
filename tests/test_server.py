import json
import tempfile
import threading
import unittest
from datetime import date, datetime, timedelta
from http.server import ThreadingHTTPServer
from pathlib import Path
from unittest.mock import patch
from urllib.error import HTTPError
from urllib.request import Request, urlopen

import server


class QueueApiTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.httpd = ThreadingHTTPServer(("127.0.0.1", 0), server.QueueHandler)
        cls.thread = threading.Thread(target=cls.httpd.serve_forever, daemon=True)
        cls.thread.start()
        cls.base_url = "http://127.0.0.1:%d" % cls.httpd.server_port

    @classmethod
    def tearDownClass(cls):
        cls.httpd.shutdown()
        cls.thread.join()
        cls.httpd.server_close()

    def setUp(self):
        self.temp_dir = tempfile.TemporaryDirectory()
        self.store_patch = patch.object(
            server, "STORE", server.QueueStore(Path(self.temp_dir.name) / "appointments.json")
        )
        self.store_patch.start()
        self.email_patch = patch.object(server, "send_confirmation", return_value="not_configured")
        self.email_patch.start()

    def tearDown(self):
        self.email_patch.stop()
        self.store_patch.stop()
        self.temp_dir.cleanup()

    def request_json(self, path, payload=None):
        data = json.dumps(payload).encode("utf-8") if payload is not None else None
        request = Request(
            self.base_url + path,
            data=data,
            headers={"Content-Type": "application/json"},
            method="POST" if payload is not None or path.startswith("/api/call-next") else "GET",
        )
        try:
            response = urlopen(request)
        except HTTPError as error:
            response = error
        return response.code, json.loads(response.read().decode("utf-8"))

    def booking(self, slot="09:00", email="alex@example.com", selected_date=None):
        return self.request_json(
            "/api/bookings",
            {
                "name": "Alex Morgan",
                "email": email,
                "date": (selected_date or (date.today() + timedelta(days=1))).isoformat(),
                "time": slot,
            },
        )

    def test_booking_reserves_slot_and_reports_email_status(self):
        status, result = self.booking()
        self.assertEqual(status, 201)
        self.assertRegex(result["ticket"], r"^Q\d{4}$")
        self.assertEqual(result["email_status"], "not_configured")
        future_date = (date.today() + timedelta(days=1)).isoformat()
        _, availability = self.request_json("/api/availability?date=" + future_date)
        nine_am = next(slot for slot in availability["slots"] if slot["time"] == "09:00")
        self.assertEqual(nine_am["available"], server.SLOT_CAPACITY - 1)

    def test_slot_capacity_and_invalid_email_are_rejected(self):
        for index in range(server.SLOT_CAPACITY):
            status, _ = self.booking(slot="16:30", email="person%d@example.com" % index)
            self.assertEqual(status, 201)
        status, result = self.booking(slot="16:30", email="last@example.com")
        self.assertEqual(status, 409)
        self.assertIn("full", result["error"])
        status, result = self.booking(email="not-an-email")
        self.assertEqual(status, 400)
        self.assertIn("email", result["error"])

    def test_booking_rejects_a_time_that_has_already_passed_today(self):
        with patch.object(server, "datetime") as clock:
            clock.now.return_value = datetime.combine(date.today(), datetime.min.time()).replace(
                hour=16, minute=45
            )
            status, result = self.booking(slot="16:30", selected_date=date.today())
        self.assertEqual(status, 400)
        self.assertIn("has not passed", result["error"])

    def test_staff_calls_in_order_and_must_complete_before_next(self):
        with patch.object(server, "datetime") as clock:
            clock.now.return_value = datetime.combine(date.today(), datetime.min.time()).replace(hour=8)
            first_status, first = self.booking(
                slot="09:30", selected_date=date.today()
            )
            self.booking(
                slot="09:00", email="next@example.com", selected_date=date.today()
            )
            self.assertEqual(first_status, 201)
            clock.now.return_value = datetime.combine(date.today(), datetime.min.time()).replace(hour=10)
            status, called = self.request_json("/api/call-next")
            self.assertEqual(status, 200)
            self.assertEqual(called["time"], "09:00")
            status, result = self.request_json("/api/call-next")
            self.assertEqual(status, 409)
            self.assertIn("Complete", result["error"])
            store = server.STORE
            appointment = next(
                item for item in store.read()["appointments"] if item["ticket"] == called["ticket"]
            )
            status, _ = self.request_json(
                "/api/appointments/%s/complete" % appointment["id"], payload={}
            )
            self.assertEqual(status, 200)
            status, called_next = self.request_json("/api/call-next")
            self.assertEqual(status, 200)
            self.assertEqual(called_next["ticket"], first["ticket"])


if __name__ == "__main__":
    unittest.main()
