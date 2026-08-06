# Podman container image auto-updates

Host-wide mechanism for keeping labeled container images up to date, without any custom
scripting beyond what Podman and DNF already ship. Two layers, both installed on
jackson-brain.com:

1. **Podman's native daily timer** (`podman-auto-update.timer`) — checks once a day regardless
   of anything else happening on the host.
2. **A DNF post-transaction hook** (`python3-dnf-plugin-post-transaction-actions`) — also
   triggers a check immediately after any `dnf`/`dnf5` transaction, per the owner's request to
   tie container-image freshness to system package updates.

## How a container opts in

Add this label to a service in its `docker-compose.yml` (see `wordpress/docker-compose.yml`):

```yaml
labels:
  - "io.containers.autoupdate=registry"
```

Requirements for the `registry` policy to work:
- The image reference **must be fully-qualified** (registry + repo + tag), e.g.
  `docker.io/library/wordpress:6.9.4-php8.4-apache` — a bare `wordpress:...` reference doesn't
  give Podman enough information to know what to re-check/pull.
- The container must be started via `podman compose up` (or `podman run`/quadlet) under a
  systemd unit — `podman auto-update` works by pulling the new image, then **restarting the
  systemd unit** that runs the container.

`podman auto-update` compares the image **digest** (not just the tag string) — for a pinned
tag like `6.9.4-php8.4-apache`, this means we only get **rebuilds of that exact same
WordPress+PHP version** (base-image security patches, PHP point releases, etc.), never an
unexpected jump to a different WordPress core version. Bumping to a new WordPress version is a
deliberate act of editing the pinned tag in `docker-compose.yml` yourself.

To opt additional stacks in later (e.g. `ampache`, `shared-mariadb`), add the same label to
their compose files and make sure their `image:` line is fully-qualified.

## Installed pieces (this folder mirrors what's on the server)

- `podman-auto-update.timer`/`.service` — ships with Podman itself (`podman` package); this
  repo just documents enabling it, no files to install for this layer.
- `post-transaction-actions.d/podman-auto-update.action` — deployed to
  `/etc/dnf/plugins/post-transaction-actions.d/` by `setup.sh`. Fires `podman auto-update`
  after **any** package changes in a `dnf`/`dnf5` transaction (matches the owner's ask to tie
  it to system updates). Note: on a transaction touching multiple packages, this can fire more
  than once — harmless, since `podman auto-update` is a fast no-op when no image has changed.
- `setup.sh` — installs `python3-dnf-plugin-post-transaction-actions`, deploys the action file,
  enables `podman-auto-update.timer`.

## Verifying

```bash
# Dry-run: see what WOULD update without pulling/restarting anything
sudo podman auto-update --dry-run --format "{{.Image}} {{.Policy}} {{.Updated}}"

# Force a real check now
sudo podman auto-update

# Confirm the daily timer is enabled and see its next run
systemctl list-timers podman-auto-update.timer

# Confirm the dnf hook is installed
rpm -q python3-dnf-plugin-post-transaction-actions
cat /etc/dnf/plugins/post-transaction-actions.d/podman-auto-update.action
```

After any real update, `podman auto-update` restarts the owning systemd unit
(`wordpress.service`, etc.) — check `systemctl status <unit>` and re-run the app's own smoke
test (e.g. `curl -I http://127.0.0.1:8082/` for WordPress) afterward.

## Rollback behavior

`podman auto-update` defaults to `--rollback=true`: if restarting the unit after a pull fails,
it automatically reverts to the previous image and restarts again. This requires the
container to signal readiness via sdnotify to be fully reliable — the compose-based containers
in this repo don't currently set that up, so treat the rollback as a best-effort safety net,
not a guarantee; still check service status after any auto-update cycle.
