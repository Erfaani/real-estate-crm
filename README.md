# Real Estate CRM

A lightweight PHP-based CRM for real estate offices to manage **property files**, **client records**, **agent activities**, and **staff attendance**.

## Features
- Property / listing filing and management
- Client database and follow-ups
- Expert/agent activity tracking (tasks, actions, notes)
- Staff attendance (check-in / check-out)
- Basic reporting and internal workflows

## Tech Stack
- PHP
- HTML / CSS
- MySQL (if enabled in your config)

## Project Structure (high level)
- `index.php` — main entry point
- `db.php` — database connection/config (edit based on your environment)
- `assets/` — static files (CSS, JS, images)
- Other PHP modules/pages are organized by feature inside the repository

## Setup (Local / Server)
1. Upload the project to a PHP-enabled host (Apache/Nginx).
2. Create a MySQL database (if required by your project).
3. Import the SQL file (if you have one) via phpMyAdmin.
4. Update database settings in `db.php`.
5. Open the app:
   - `https://your-domain.com/your-folder/` or `http://localhost/your-folder/`

## Notes
- This repository is shared as-is for learning and portfolio purposes.
- If you plan to deploy publicly, review and secure configuration files and user-upload directories.

## License
MIT (add a LICENSE file if you want to explicitly publish under MIT)