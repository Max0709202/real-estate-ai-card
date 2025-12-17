# Setup Guide - Email Invitation System

## 🚀 Quick Setup (3 Steps)

### Step 1: Run Database Migration
```bash
cd C:\xampp\htdocs\php
mysql -u root -p your_database < backend/database/migrations/create_email_invitations_table.sql
```

Or use phpMyAdmin:
1. Open phpMyAdmin
2. Select your database
3. Go to "SQL" tab
4. Copy and paste contents from `backend/database/migrations/create_email_invitations_table.sql`
5. Click "Go"

### Step 2: Test the Import
1. Login to admin dashboard: `http://localhost/php/frontend/admin/dashboard.php`
2. Click "📧 メール招待" button
3. Upload `sample_email_invitations.csv`
4. Verify data appears in table

### Step 3: Send Test Email
1. Select a user (checkbox)
2. Choose role from dropdown
3. Click "選択したユーザーにメール送信"
4. Check email delivery

## 📋 Requirements

✅ Already installed in your project:
- PHP 7.4+
- MySQL 5.7+
- Admin authentication system
- Email sending functionality (`sendEmail()` function)

## 🎯 Features at a Glance

### Import
- Drag & drop CSV upload
- Auto-validate emails
- Handle duplicates gracefully

### Manage
- Change roles (New/Existing/Free)
- Track email sent status
- View send timestamps

### Send
- Batch email sending
- Role-based landing pages
- HTML + Plain text formats

## 📁 File Locations

```
C:\xampp\htdocs\php\
│
├── backend/
│   ├── database/
│   │   └── migrations/
│   │       └── create_email_invitations_table.sql  ← Run this
│   └── api/
│       └── admin/
│           ├── import-email-csv.php
│           ├── get-email-invitations.php
│           ├── update-role.php
│           └── send-invitation-email.php
│
├── frontend/
│   └── admin/
│       ├── send-email.php  ← Main page
│       └── dashboard.php   ← Updated (navigation link)
│
└── sample_email_invitations.csv  ← Test data
```

## 🧪 Test CSV Format

```csv
ユーザー名,メールアドレス
山田太郎,yamada.taro@example.com
佐藤花子,sato.hanako@example.com
田中一郎,tanaka.ichiro@example.com
```

**Rules:**
- First row is header (skipped)
- Two columns: username, email
- Email validation is automatic
- Duplicates are updated, not rejected

## 🎨 UI Preview

### Main Page Components

1. **Header Section**
   - Page title
   - Description
   - Back to dashboard link

2. **Upload Zone**
   - Drag & drop area
   - File selection button
   - Upload confirmation

3. **Statistics Cards**
   - Total records
   - Sent count
   - Pending count

4. **Data Table**
   - Checkboxes for selection
   - Role dropdowns
   - Status badges
   - Action buttons

### Color Coding

- 🟢 **Green Badge** = Email sent successfully
- 🔴 **Red Badge** = Email not sent yet
- 🔵 **Blue Buttons** = Primary actions
- ⚪ **Gray Buttons** = Secondary actions

## 📧 Email Content

### For New Users (新規)
```
Subject: 【不動産AI名刺】サービスへのご招待

{username} 様

不動産AI名刺サービスへのご招待です。

下記のリンクからアクセスして、サービスをご利用ください。

[サービスにアクセス]
↓
http://yourdomain.com/frontend/register.php
```

### For Existing Users (既存)
```
Landing Page: /frontend/login.php
```

### For Free Users (無料)
```
Landing Page: /frontend/register.php?type=free
```

## 🔒 Security Features

✅ **Authentication**
- Admin login required
- Session validation

✅ **Input Validation**
- File type checking
- Email format validation
- SQL injection prevention

✅ **Data Integrity**
- Transaction support
- Unique email constraint
- Foreign key relationships

✅ **Activity Logging**
- All actions logged
- Admin tracking
- Timestamp recording

## ⚙️ Configuration

### Email Settings
The system uses the existing `sendEmail()` function from `backend/includes/functions.php`.

Make sure your email settings are configured:
```php
// Check these settings in your config
SMTP_HOST
SMTP_PORT
SMTP_USER
SMTP_PASS
FROM_EMAIL
FROM_NAME
```

### Base URL
The landing pages use `BASE_URL` constant:
```php
// Defined in backend/config/config.php
define('BASE_URL', 'http://yourdomain.com/php');
```

## 🐛 Troubleshooting

### CSV Upload Fails
**Problem:** "CSVファイルをアップロードしてください"
**Solution:** 
- Ensure file is .csv format
- Check file permissions
- Try smaller file first

### Emails Not Sending
**Problem:** "メール送信に失敗しました"
**Solution:**
- Check email configuration
- Verify SMTP settings
- Test `sendEmail()` function separately

### Role Not Updating
**Problem:** Dropdown changes but doesn't save
**Solution:**
- Check browser console for errors
- Verify admin session is active
- Check database connection

### Table Shows "データを読み込んでいます..."
**Problem:** Table doesn't load
**Solution:**
- Check browser console
- Verify API endpoint path
- Check admin authentication

## 📊 Database Schema

```sql
email_invitations
├── id (PK, AUTO_INCREMENT)
├── username (VARCHAR 255)
├── email (VARCHAR 255, UNIQUE) ← Cannot duplicate
├── role_type (ENUM: new|existing|free)
├── email_sent (TINYINT 0 or 1)
├── sent_at (TIMESTAMP)
├── imported_by (INT, FK to admins)
├── created_at (TIMESTAMP)
└── updated_at (TIMESTAMP)
```

**Indexes for Performance:**
- `idx_email` on email
- `idx_role_type` on role_type
- `idx_email_sent` on email_sent

## 🔄 Workflow Example

1. **Admin imports CSV**
   ```
   Upload CSV → System validates → Insert to database → Show in table
   ```

2. **Admin configures roles**
   ```
   Select role from dropdown → AJAX update → Database saves → Success message
   ```

3. **Admin sends emails**
   ```
   Check users → Click send button → System sends emails → Update status → Show results
   ```

## 📱 Mobile Responsive

The page is fully responsive:

- **Desktop (> 768px)**
  - Full table width
  - Horizontal button layout
  - Side-by-side stats cards

- **Mobile (< 768px)**
  - Scrollable table
  - Stacked buttons
  - Vertical stats cards

## ✅ Verification Checklist

After setup, verify:

- [ ] Can access `/frontend/admin/send-email.php`
- [ ] See navigation link in dashboard header
- [ ] Can upload CSV file
- [ ] Data appears in table
- [ ] Can change roles
- [ ] Can select users
- [ ] Can send emails
- [ ] Status updates after send
- [ ] Statistics show correct counts

## 🆘 Support

If you encounter issues:

1. **Check browser console** for JavaScript errors
2. **Check PHP error log** for backend errors
3. **Verify database** table was created
4. **Test email function** separately
5. **Check file permissions** on upload directory

## 🎉 Success!

If everything works:
- ✅ CSV import is smooth
- ✅ Role changes save instantly
- ✅ Emails send successfully
- ✅ UI is responsive and fast

You're ready to invite users to your platform!

---

**Created:** December 17, 2025  
**Version:** 1.0  
**Status:** Production Ready ✓

