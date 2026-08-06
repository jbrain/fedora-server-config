# Local mail spool pruning

`/var/spool/mail/<user>` (root's mbox in particular) accumulates local system mail forever
with no rotation by default — root@localhost is the destination for cron job output, netdata
health-alert notifications, certbot/dnf/sendmail service warnings, etc. Nothing on this host
reads that mailbox interactively, so left alone it just grows.

**Found 2026-08-05**: `/var/spool/mail/root` had reached 83.4MB after only ~2 days of uptime,
driven mostly by frequent netdata health-check notification emails (`netdata@jackson-brain.com`
-> `root@jackson-brain.com`, some single messages 40-230KB). No existing logrotate config
covered `/var/spool/mail/*` (`/etc/logrotate.d/` has no `mail` stanza on this host).

## Fix

`logrotate.d/mail-spool.conf` (deployed to `/etc/logrotate.d/mail-spool`):

```
/var/spool/mail/*
{
    su root root
    weekly
    rotate 4
    compress
    missingok
    notifempty
    maxsize 10M
    copytruncate
}
```

- `su root root`: required because `/var/spool/mail` is group-writable (`root:mail`, mode 775)
  — logrotate refuses to touch files under a group/world-writable dir without an explicit
  `su` directive declaring which user/group to act as (files themselves are `root:root` 600,
  so root is correct).
- `weekly` + `rotate 4`: normal cadence, ~1 month of history kept, compressed.
- `maxsize 10M`: also rotates out-of-cycle the next time logrotate runs (daily, via the OS's
  own `logrotate.timer`) if a mailbox blows past 10M before its weekly turn — caps worst-case
  growth between cycles instead of waiting a full week.
- `copytruncate`: mail spool files are kept open/appended-to by the local MTA (sendmail) and
  `mail`/`mutt` style readers; copytruncate copies the file then truncates it in place, so nothing
  needs to be signaled/restarted to pick up the new (empty) file, unlike log files that support
  `create`+reopen. Deliberately **no `delaycompress`**: that directive exists to avoid
  compressing a file a process might still hold open across the rotation's rename — irrelevant
  here since copytruncate never renames the live path, so the just-rotated `.1` is safe to gzip
  immediately.

`setup.sh` deploys the config, validates it (`logrotate -d`), then **forces an immediate
rotation** (`logrotate -f`) so any already-oversized mailbox gets pruned right away instead of
waiting for the next scheduled run.

## Deploying

```bash
sudo bash setup.sh
```

## Verifying

```bash
ls -la /var/spool/mail/
cat /etc/logrotate.status | grep -A1 mail-spool   # or grep for /var/spool/mail
sudo logrotate -d /etc/logrotate.d/mail-spool      # dry-run, shows what would happen
```

## Not addressed here (separate follow-up)

The volume of netdata alert emails itself (why it's mailing every 30-90 minutes around the
clock) wasn't investigated/tuned as part of this fix — this only stops the resulting mailbox
from consuming disk. If the alert frequency itself is unwanted noise (vs. a real recurring
health condition worth knowing about), check `/etc/netdata/health_alarm_notify.conf` and
`netdata -W buildinfo`/the Netdata UI's Alerts tab for which specific check is flapping.
