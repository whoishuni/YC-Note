-- Run once as the identity DB administrator, after replacing DB/user/host names.
-- No rental tables, credentials, or business records are copied into YC Note.
CREATE VIEW rental.yc_note_identity AS
SELECT id, name, password, type, is_banned, first_login FROM rental.users
WHERE type IN ('super_admin', 'admin', 'manager');
-- manager is the rental application's historical alias of admin.
-- Create a distinct DB user with a strong password through your hosting panel.
GRANT SELECT ON rental.yc_note_identity TO 'yc_note_identity_reader'@'localhost';
-- Never grant UPDATE/INSERT/DELETE or access to contracts/payments/etc.
