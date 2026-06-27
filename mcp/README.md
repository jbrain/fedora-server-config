# GitHub Copilot MCP Server (linux-mcp-server)

The `linux-mcp-server` package gives GitHub Copilot in VS Code read-only access to system diagnostics on this Fedora server over SSH.

## How It Works

```
VS Code (GitHub Copilot)
    │  MCP stdio transport
    ▼
ssh linus-mcp
    │  runs
    ▼
/home/mcp-copilot/.local/bin/linux-mcp-server
    │  reads
    ▼
systemd, journald, /proc, /sys, lsblk, ss, etc.
```

VS Code connects by spawning `ssh linus-mcp <command>` as a child process and communicates via stdin/stdout (stdio MCP transport). No port is opened — the SSH session is the transport.

## Server-Side Setup

### User

A dedicated low-privilege user `mcp-copilot` (UID 1008) was created specifically for MCP access. It has no sudo rights and no shell login except via SSH key.

```bash
sudo useradd -m -s /bin/bash mcp-copilot
```

### SSH Key

Key type: ED25519, no passphrase.
- Private key (Windows client): `C:\Users\brain\.ssh\fedora_mcp_ed25519`
- Public key installed at: `/home/mcp-copilot/.ssh/authorized_keys`

To rotate the key:
```bash
# On Windows, generate new key
ssh-keygen -t ed25519 -f C:\Users\brain\.ssh\fedora_mcp_ed25519 -N ""

# On the server (as jack with sudo)
echo "<new public key>" | sudo tee /home/mcp-copilot/.ssh/authorized_keys
sudo chmod 600 /home/mcp-copilot/.ssh/authorized_keys
sudo chown mcp-copilot:mcp-copilot /home/mcp-copilot/.ssh/authorized_keys
```

### Installing linux-mcp-server

Installed via `uv` (Python package manager):

```bash
# As mcp-copilot on the server
uv tool install linux-mcp-server
```

Binary location: `/home/mcp-copilot/.local/bin/linux-mcp-server`

To update:
```bash
ssh linus-mcp "uv tool upgrade linux-mcp-server"
```

To check version:
```bash
ssh linus-mcp "linux-mcp-server --version"
```

## Windows Client Setup

### SSH Config (`~/.ssh/config`)

```
Host linus-mcp
    HostName 192.168.88.251
    User mcp-copilot
    IdentityFile ~/.ssh/fedora_mcp_ed25519
    IdentitiesOnly yes
```

### VS Code MCP Config (`%APPDATA%\Code\User\mcp.json`)

```json
{
    "servers": {
        "linux-diagnostics": {
            "type": "stdio",
            "command": "ssh",
            "args": [
                "linus-mcp",
                "env LINUX_MCP_ALLOWED_LOG_PATHS=/var/log/messages,/var/log/dnf.log,/var/log/audit/audit.log /home/mcp-copilot/.local/bin/linux-mcp-server"
            ]
        }
    }
}
```

The `LINUX_MCP_ALLOWED_LOG_PATHS` environment variable whitelists which log files Copilot can read via the `read_log_file` tool. Add additional paths as needed (comma-separated).

## Allowed Log Paths

| Path | Contents |
|---|---|
| `/var/log/messages` | General system messages |
| `/var/log/dnf.log` | Package manager history |
| `/var/log/audit/audit.log` | SELinux / audit events |

To add more paths, update the `args` in `mcp.json` and reload VS Code MCP servers.

## Verifying the Connection

Test from Windows:
```powershell
ssh -o ConnectTimeout=10 linus-mcp "echo 'SSH OK' && whoami"
```

Test full MCP handshake:
```powershell
echo '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"test","version":"1.0"}}}' |
  ssh linus-mcp "env LINUX_MCP_ALLOWED_LOG_PATHS=/var/log/messages,/var/log/dnf.log,/var/log/audit/audit.log /home/mcp-copilot/.local/bin/linux-mcp-server"
```

A successful response contains `"serverInfo":{"name":"linux-mcp-server",...}`.

## Available MCP Tools

The server exposes read-only tools in six categories:

| Category | Examples |
|---|---|
| System | hostname, OS, kernel, uptime, CPU, memory, disk, hardware |
| Services | list systemd units, service status, service logs |
| Network | interfaces, active connections, listening ports |
| Processes | full process list, PID details |
| Storage & files | block devices, directory listing, read file |
| Logs | systemd journal (filtered), read allowed log files |

## Security Notes

- `mcp-copilot` has no sudo access — all tools run as that user
- Tools are strictly read-only; no write operations are possible
- Log file access is restricted to the `LINUX_MCP_ALLOWED_LOG_PATHS` allowlist
- SSH key has no passphrase; protect the private key file on Windows accordingly
