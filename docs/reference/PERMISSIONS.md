# Simple Form Permissions

The Simple Form plugin implements Craft CMS permissions for fine-grained access control.

## Permission Keys

### `simple-form:manageForms`
Controls access to:
- Form creation, editing, and deletion
- Field management (add/edit/delete/reorder fields)
- Access to FormsController and FieldsController

### `simple-form:viewSubmissions`
Controls access to:
- View submission list and individual submissions
- Filter submissions by form, status, date, search
- Access to SubmissionsController index and view actions

### `simple-form:manageSubmissions` (nested under viewSubmissions)
Controls access to:
- Toggle submission read status
- Requires `viewSubmissions` permission as a prerequisite
- Access to SubmissionsController toggleStatus action

### `simple-form:manageSettings`
Controls access to:
- Plugin settings page
- Configuration of email sender, honeypot, reCAPTCHA, storage location
- Access to SettingsController

## Implementation

### Permission Registration
Permissions are registered via the `UserPermissions::EVENT_REGISTER_PERMISSIONS` event in the Plugin class `init()` method.

### Permission Checks
All controllers use the `SimpleFormControllerTrait` which enforces permission checks in the `beforeAction()` method before any action runs.

Each controller declares a `PERMISSION` constant:
```php
protected const PERMISSION = SimpleFormPermissions::MANAGE_FORMS;
```

The trait checks this permission and calls `requirePermission()` for non-admin users.

### Admin Bypass
Administrators (users with `admin = true`) automatically bypass all permission checks. This is Craft's standard behavior via the `requirePermission()` method.

### CP Navigation
The plugin's CP nav item is conditionally rendered based on user permissions. If a user has no permissions in the Simple Form plugin, the entire plugin nav item is hidden.

## Usage for Admins

1. Navigate to **Settings > Users > User Groups** in Craft
2. Create a new user group (e.g., "Form Managers")
3. Click **Edit Permissions**
4. Under **Simple Form**, assign the desired permissions
5. Add users to the group

## File Locations

- **Permission definitions**: `src/helpers/SimpleFormPermissions.php`
- **Permission enforcement trait**: `src/controllers/SimpleFormControllerTrait.php`
- **Permission registration**: `src/Plugin.php` (init method, getCpNavItem method)
- **Controllers using permissions**: All controllers in `src/controllers/`
