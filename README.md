# Reverence WorshipTeam - Gospel Choir Group Assignment System

A comprehensive web-based system for managing gospel choir singers and creating fair, balanced group assignments for worship team performances.

## Features

### 🔐 User Roles & Permissions
- **Admin**: Full access to manage singers, create groups, view logs, control images
- **Viewer** (Choir Members): View-only access to current group assignments and activity logs

### 👥 Singer Management
- Add, edit, delete singers with voice categories and levels
- Voice Categories: Soprano, Alto, Tenor, Bass
- Voice Levels: Good (Strong/Experienced), Normal (Average/Developing)
- Status tracking: Active/Inactive

### 🎼 Intelligent Mixing Algorithm
- **Rule 1**: Balance quality first - Good singers divided before Normal singers
- **Rule 2**: Balance voices evenly across groups
- **Rule 3**: Equal total size - Group sizes differ by at most 1
- Supports dynamic number of groups (2-10 groups)
- Validates all voice categories are represented

### 📊 Transparency & Logging
- All administrative actions are logged
- Complete audit trail visible to all users
- No hidden changes - full transparency

### 🖼️ Image Management
- Admin-controlled landing page images
- Upload, activate, and manage worship team photos

## System Requirements

- PHP 8.0 or higher
- MySQL 5.7 or higher (for production)
- Web server (Apache/Nginx) or local development environment (XAMPP, WAMP, etc.)
- Modern web browser

## Installation

### Local Development

1. **Clone or download** the project files to your web server directory
2. **Set up MySQL database**:
   - Create a MySQL database named `reverence`
   - Run the setup script: `http://your-domain/setup.php`
   - Or manually create tables using the SQL in `setup.php`

3. **Configure database connection** in `includes/config.php`:
   ```php
   // Database configuration (MySQL) - Local development
   $pdo = new PDO(
       "mysql:host=localhost;dbname=reverence;charset=utf8mb4",
       "root",
       ""
   );
   ```

4. **Set permissions** for uploads directory:
   ```bash
   chmod 755 uploads/
   chmod 755 uploads/images/
   ```

5. **Access the system**:
   - Main site: `http://your-domain/`
   - Default admin login: `admin` / `admin123`

## Usage

### For Administrators

1. **Login** with admin credentials
2. **Manage Singers**:
   - Add new singers with voice details
   - Edit existing singer information
   - Deactivate inactive members
3. **Create Groups**:
   - Specify number of groups (2-10)
   - System automatically balances singers
   - Publish groups for choir members to view
4. **Monitor Activity**:
   - View detailed logs of all actions
   - Track system usage and changes

### For Choir Members

1. **Login** with provided credentials
2. **View Current Groups**:
   - See your assigned group
   - View complete group compositions
   - Check voice category distributions
3. **Monitor Transparency**:
   - View recent administrative activity
   - Ensure fair and accountable processes

## Mixing Algorithm Details

The system uses a sophisticated algorithm to ensure fair group divisions:

### Phase 1: Quality Balance
- Good (experienced) singers are distributed first
- Ensures no group gets all strong singers of one voice

### Phase 2: Voice Balance
- Each voice category (Soprano/Alto/Tenor/Bass) is balanced
- Singers distributed evenly across available groups

### Phase 3: Size Validation
- Total group sizes differ by at most 1 singer
- Automatic adjustment for odd numbers

### Validation Rules
- Each group must have singers from all voice categories (when possible)
- Quality levels are fairly distributed
- Size constraints are maintained

## Database Schema

### Tables
- `users`: User accounts and roles
- `singers`: Singer information and voice details
- `groups`: Group definitions and metadata
- `group_assignments`: Singer-to-group mappings
- `logs`: Audit trail of all actions
- `landing_images`: Admin-controlled images
- `movement_logs`: Singer movement tracking

## Security Features

- Password hashing with PHP's password_hash()
- Session-based authentication
- Input sanitization and validation
- SQL injection prevention with prepared statements
- File upload validation and security

## File Structure

```
/
├── index.php              # Landing page
├── login.php              # User authentication
├── logout.php             # Session termination
├── setup.php              # Database initialization
├── includes/
│   └── config.php         # Database configuration
├── admin/
│   ├── dashboard.php      # Admin overview
│   ├── singers.php        # Singer management
│   ├── groups.php         # Group creation
│   ├── reports.php        # Report generation
│   ├── images.php         # Image management
│   ├── settings.php       # Account settings
├── viewer/
│   └── groups.php         # Group viewing
├── css/
│   └── style.css          # Black & white theme
├── js/
│   └── main.js            # Interactive features
└── uploads/
    └── images/            # Uploaded images
```
## Support

For technical support or questions about the system, please contact your system administrator.

## License

This system is developed for the Reverence Worship Team choir division needs.
