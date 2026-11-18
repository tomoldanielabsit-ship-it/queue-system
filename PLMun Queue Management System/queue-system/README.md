# PLMun Queue System - XAMPP setup

## Requirements

- XAMPP with Apache, MariaDB, PHP PDO MySQL, and PDO SQLite enabled
- PHP 8.1 or later

## Setup

1. Make this project available at `D:\xampp\htdocs\queue-system`. If the project is elsewhere, create a Windows directory junction or copy the folder there.
2. Start **Apache** and **MySQL** in the `D:\xampp` Control Panel.
3. Open `http://localhost/phpmyadmin`, select **Import**, and import `database.sql`.
4. If your MariaDB credentials differ from the XAMPP defaults, add these values to `config.local.php`:

   ```php
   'db_host' => '127.0.0.1',
   'db_port' => 3306,
   'db_name' => 'plmun_queue',
   'db_user' => 'root',
   'db_password' => '',
   ```

   The standard local XAMPP setup uses `root` with an empty password.
5. To preserve records from the previous SQLite database, run this once from PowerShell:

   ```powershell
   D:\xampp\php\php.exe D:\xampp\htdocs\queue-system\migrate-sqlite-to-mariadb.php
   ```

   The importer stops if the MariaDB tables already contain records. It does not modify or delete the SQLite database.
6. Open `http://localhost/queue-system/login.html`.

Use Apache/XAMPP for the app. Live Server only serves static files and cannot execute PHP.

Appointment scheduling is unavailable on weekends and Philippine nationwide holidays. The holiday list is in `holiday-calendar.php`; update it when the government publishes each year's holiday proclamation.

The student portal calendar uses live appointment data: green dates accept appointments until the 100-appointment daily limit, red dates have reached that limit or are holidays, and gray dates are past dates, weekends, or outside the booking window. Available time slots remain selectable even if another appointment uses the same time; past times stay disabled, and the 100-appointment daily limit still applies.

Students can reschedule future appointments that are still booked from **Track / manage appointment**. Rescheduling keeps the same tracking code and services, and checks date, holiday, and slot availability on the server. Checked-in, completed, cancelled, no-show, and past or ongoing appointments cannot be rescheduled. Reschedule notifications go to the student and configured Registrar recipient when email is configured.

The student tracker displays booked appointments as **Pending**, checked-in appointments as **Processing**, and completed appointments as **Completed**. Cancelled and no-show appointments retain their own status. Students can press **Check status** again to refresh the progress.

Admins can reissue existing tracking codes from **Settings** as two uppercase letters and six digits. This replaces old codes and provides an admin-only CSV for manual distribution through an approved private channel. New bookings use the same format.

Staff can mark a student's arrival with **Check in** in today's queue; checked-in appointments remain waiting until marked complete. Existing databases add the new status automatically on the next API request without removing appointment records.
