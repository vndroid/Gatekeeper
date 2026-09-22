# Gatekeeper

## Limit Admin Panel Login Plugin For Typecho

Only allow White IP list access Admin panel.

### Lastest Update Description

* 1.1.0: ACL also covers `/index.php/action/*` (login, XML-RPC, ...); emergency bypass reworked (root-owned `.bypass`, 30-minute expiry, logged); `0.0.0.0` removed

### Notice

* When the plugin update, please disable the plugin before updating.
* Please change the plugin directory name to Gatekeeper.
* Locked out by a wrong whitelist? Create the emergency bypass file **as root** on the server,
  e.g. `docker exec -u root <php-container> touch /path/to/usr/plugins/Gatekeeper/.bypass`.
  It allows all IPs for 30 minutes (counted from the file's mtime) and then expires on its own;
  delete it once you have fixed the whitelist. Every use is written to the PHP error log (`[Gatekeeper]`).
  The file is ignored unless it is a regular file (no symlink / hardlink), owned by uid 0,
  not group/world-writable, and PHP itself is not running as root.
* Since 1.1.0, `0.0.0.0` in the whitelist no longer allows everyone.

### Author

[@fuzqing](https://github.com/fuzqing)


### Thanks

[@Vndroid](https://github.com/Vndroid)