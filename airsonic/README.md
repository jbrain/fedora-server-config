# AirSonic Advanced

AirSonic is a Java-based media server running as a native systemd service, proxied externally via Apache HTTPS.

## URLs

- External: `https://music.jackson-brain.com`
- Internal: `http://localhost:8080`

## Paths

| Path | Purpose |
|---|---|
| `/var/airsonic/` | AirSonic home (config, DB, cache) |
| `/var/airsonic/airsonic.war` | Application JAR |
| `/var/airsonic/airsonic.properties` | Runtime properties |
| `/media/Music/` | Primary music library (591 GB, 631 GB volume used of 931 GB) |
| `/var/playlists/` | Playlist storage |

## systemd Service

Unit file: `/etc/systemd/system/airsonic.service`

```
systemctl status airsonic
systemctl restart airsonic
journalctl -u airsonic -f
```

Key settings in the unit:
- Runs as user/group `airsonic`
- JVM heap: `-Xmx700m`
- Context path: `/`
- Port: `8080`

## Apache Vhost

Config: `/etc/httpd/conf.d/vhosts/music.jackson-brain.com.conf`

- HTTP → HTTPS redirect
- HTTPS proxies `localhost:8080` with WebSocket support
- TLS: Let's Encrypt (`/etc/letsencrypt/live/music.jackson-brain.com/`)

## Notable Properties (`airsonic.properties`)

```properties
OrganizeByFolderStructure=true
PlaylistFolder=/var/playlists
MusicFileTypes=mp3 ogg oga aac m4a m4b flac wav wma aif aiff ape mpc shn mka opus
SonosServiceName=Musicbox
SonosCallbackHostAddress=https://music.jackson-brain.com/
UploadsFolder=%{['USER_MUSIC_FOLDERS'][0]}/Incoming
```
